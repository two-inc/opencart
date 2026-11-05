<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Order-edit sync (Build Guide section 12.1): when a placed order's own rows
 * (lines, totals, addresses) change before it is fulfilled, the order is
 * rebuilt and, if its payload hash differs from the one Two last accepted,
 * sent as `PUT /v1/order/{id}`.
 *
 * - Runs off the order history event, which OpenCart's order edit fires
 *   once the edited rows are written, so every edit path is covered.
 * - The rows hash is the trigger and the payload hash the test: a tax setup
 *   change alone never sends an edit, and an edit that changes nothing Two
 *   holds (a product's model code) sends nothing.
 * - Keyed `edit_<two order id>_<hash>`, chained on the last accepted edit's
 *   key (the create key for the first edit). Each accepted edit stores its
 *   key, payload hash and rows hash, so the later fulfil check passes.
 * - When the outcome is unknown (no answer, or a 5xx) the stored payload
 *   hash is cleared, so the next save always sends the order as it then is,
 *   even edited back to what Two last accepted, and fulfil waits until Two
 *   has accepted it. The key repeats as long as the order is unchanged.
 * - An edit Two refuses as such (400, 422) keeps its rows hash, so the same
 *   edit is not sent or noted again; a different edit is. A 408 or 429 is
 *   handled like no answer, and any other 4xx (a key being changed, a
 *   conflict) records nothing, so the next save sends the edit again.
 * - After fulfilment an edit is refused with a note, never sent: Two has
 *   invoiced the order as it was.
 */
final class OrderEdit
{
    /** The Two states that accept an edit. */
    public const EDITABLE_STATES = ['VERIFIED', 'CONFIRMED'];

    /** Two's status once part of the order is fulfilled. */
    public const PARTIAL_STATUS = 'PARTIAL';

    public const UNKNOWN = 'unknown';

    /** Two's answers that refuse the edit itself: the same edit would be refused again. */
    public const DEFINITIVE_REFUSALS = [400, 422];

    /** Answers that say nothing about the edit (a timeout, a rate limit): handled like no answer. */
    public const TRANSIENT = [408, 429];

    public function __construct(
        private readonly OrderFlow $flow,
        private readonly Client $client,
        private readonly OrderBuilder $builder,
        private readonly Postprocessing $postprocessing,
        private readonly ErrorMapper $errors,
        private readonly OrderRecords $records,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Sends the order's edit to Two when it has one.
     *
     * @param array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>, currency?: string}|null $rows the order's own rows now
     * @param \Closure(array<string, string>): (array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>}|null) $rebuild
     *        the stored order as OrderBuilder's inputs, given the record
     * @return array{outcome: string, reason: string, message: Message}|null null when the order was not edited, or nothing Two holds changed
     */
    public function sync(int $orderId, ?array $rows, \Closure $rebuild): ?array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || $rows === null || !OrderFlow::validTwoOrderId($twoOrderId) || ($record['rows_hash'] ?? '') === '') {
            return null;
        }
        $rowsHash = OrderLifecycle::rowsHash($rows);
        $stored = $record['two_order_state'] ?? '';
        // An edit whose outcome is unknown cleared payload_hash: whatever the rows are now, even back as
        // they were, Two may hold something else, so the order is always sent.
        $unknown = ($record['payload_hash'] ?? '') === '';
        if ($stored === 'CANCELLED') {
            return null;
        }
        if (in_array($stored, OrderLifecycle::FULFILLED_STATES, true)) {
            // Noted once for these rows: nothing is sent after Two has invoiced, whatever the edit's outcome.
            return hash_equals($record['rows_hash'], $rowsHash) ? null : $this->afterFulfilment($orderId, $twoOrderId, $rowsHash);
        }
        // Judged before the rows: OpenCart keeps rows in the default currency, so a currency-only edit leaves them alike.
        $currency = (string) ($rows['currency'] ?? '');
        $placed = FeeQuote::placedInOtherCurrency($record, $currency);
        if ($placed !== null) {
            return $this->refused($orderId, Message::of('note_edit_currency_changed', $placed, $currency));
        }
        if (!$unknown && (hash_equals($record['rows_hash'], $rowsHash) || hash_equals($record['edit_refused_hash'] ?? '', $rowsHash))) {
            return null;
        }

        $state = $this->flow->readState($orderId, $twoOrderId);
        if ($state === null) {
            return $this->refused($orderId, Message::of('note_edit_unread', $twoOrderId));
        }
        if (in_array($state, OrderLifecycle::FULFILLED_STATES, true) || ($this->records->get($orderId)['two_order_status'] ?? '') === self::PARTIAL_STATUS) {
            return $this->afterFulfilment($orderId, $twoOrderId, $rowsHash);
        }
        if (!in_array($state, self::EDITABLE_STATES, true)) {
            return $this->refused($orderId, Message::of('note_edit_closed', $twoOrderId, $state));
        }

        $input = $rebuild($record);
        if ($input === null) {
            return $this->refused($orderId, Message::of('note_edit_unreadable'));
        }
        $path = '/v1/order/' . $twoOrderId;
        try {
            $snapshot = $this->flow->snapshotHash($input);
            if (hash_equals($record['payload_hash'] ?? '', $snapshot)) {
                // The rows changed, but not what Two holds: nothing to send.
                $this->records->put($orderId, ['rows_hash' => $rowsHash]);

                return null;
            }
            $payload = $this->builder->build($input['order'], $input['lines'], $input['totals']);
            $failures = OrderBuilder::shopMatchFailures($payload, $input['order']);
            // Two keeps the buyer's VAT number given at create (section 22.4), so an edit never sends it.
            unset($payload['merchant_urls'], $payload['buyer'], $payload['buyer_vat_number']);
            $body = $this->postprocessing->apply(Postprocessing::ORDER_UPDATE, 'order_edit', $path, $payload, ($input['context'] ?? []) + ['order_id' => $orderId], $failures);
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, Message::of('note_edit_not_built', $refused->note));
        }

        $previous = ($record['edit_key'] ?? '') !== '' ? $record['edit_key'] : IdempotencyKey::orderCreate($orderId, $record['token_hash'] ?? '');
        $key = IdempotencyKey::edit($twoOrderId, $previous, OrderFlow::payloadHash($body));
        $response = $this->client->request('PUT', $path, $body, Client::TIMEOUT_SECONDS, true, IdempotencyKey::header($key));
        if ($response->reachedTwo() && $response->status < 300) {
            $this->records->put($orderId, ['payload_hash' => $snapshot, 'rows_hash' => $rowsHash, 'edit_key' => $key, 'edit_refused_hash' => ''] + OrderFlow::stateFields($response->json() ?? []));
            $this->logger->info('Order ' . $orderId . ': Two order ' . $twoOrderId . ' updated, idempotency key ' . $key . '.');

            return OrderLifecycle::outcome(OrderLifecycle::DONE, Message::of('note_edit_done', $twoOrderId));
        }
        if (in_array($response->status, self::DEFINITIVE_REFUSALS, true) && $response->reachedTwo()) {
            // Kept so the same refused edit is not resent and noted on every status save; another edit is still tried.
            $this->records->put($orderId, ['edit_refused_hash' => $rowsHash]);

            return $this->refused($orderId, Message::of('note_edit_refused', $twoOrderId, $this->errors->noteReason($response)));
        }
        if ($response->reachedTwo() && $response->status < 500 && !in_array($response->status, self::TRANSIENT, true)) {
            // Not about the edit itself (an API key being changed, a conflict): nothing recorded, so the next save sends it again.
            return $this->refused($orderId, Message::of('note_edit_not_taken', $twoOrderId, $this->errors->noteReason($response)));
        }
        // Two may or may not hold the edit: the next save must send it whatever the hash says.
        $this->records->put($orderId, ['payload_hash' => '']);

        return $this->refused($orderId, Message::of('note_edit_unknown', $twoOrderId, $this->errors->noteReason($response)), self::UNKNOWN);
    }

    /**
     * An edit after fulfilment is never sent. The rows hash moves on so the
     * note is written once, not on every later status save.
     *
     * @return array{outcome: string, reason: string, message: Message}
     */
    private function afterFulfilment(int $orderId, string $twoOrderId, string $rowsHash): array
    {
        $this->records->put($orderId, ['rows_hash' => $rowsHash]);

        return $this->refused($orderId, Message::of('note_edit_invoiced', $twoOrderId));
    }

    /** @return array{outcome: string, reason: string, message: Message} */
    private function refused(int $orderId, Message $message, string $outcome = OrderLifecycle::REFUSED): array
    {
        $this->logger->error('Order ' . $orderId . ': ' . $message->english());

        return OrderLifecycle::outcome($outcome, $message);
    }
}
