<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The placed order's lifecycle at Two, driven by the merchant's status maps
 * (Build Guide section 12): moving an order into a mapped fulfilment status
 * fulfils the whole Two order, into the mapped cancel status cancels it.
 * Plus the invoice download once the order is fulfilled.
 *
 * - Whole orders only: Two's partial fulfilment is done in the Two merchant
 *   portal. Refunds (OrderRefunds) and order-edit sync (OrderEdit) have
 *   their own classes.
 * - Idempotent: the stored Two state and then Two's live state decide
 *   whether anything is sent, so a repeated event or a re-save never fulfils
 *   or cancels twice. Each call also carries an idempotency key
 *   (IdempotencyKey), which Two honours today only on partial fulfilments
 *   and refunds.
 * - Before fulfilling, the order's own rows (products, totals, addresses)
 *   must still hash to what was stored when Two confirmed it, so Two never
 *   invoices an order the merchant has since edited. Only data the order
 *   owns is used: a deleted product or a new tax rate does not block it.
 * - Outcomes are plain arrays. Every outcome but SKIPPED carries a reason
 *   for the order history, so the merchant always sees what happened; the
 *   merchant's status change itself is never undone.
 */
final class OrderLifecycle
{
    public const FULFIL = 'fulfil';
    public const CANCEL = 'cancel';
    public const REFUND = 'refund';

    /** Two accepted the request. */
    public const DONE = 'done';
    /** Nothing to send: the stored state shows Two already holds the order that way. */
    public const SKIPPED = 'skipped';
    /** Nothing sent: Two already holds the order that way, though the stored state was behind (an earlier refusal). */
    public const ALREADY = 'already';
    /** Not sent, or Two refused it. */
    public const REFUSED = 'refused';

    /** Two order states once fulfilment has been asked for; FULFILLED, DELIVERED and REFUNDED are invoiced. */
    public const FULFILLED_STATES = ['FULFILLING', 'FULFILMENT_NEEDS_MANUAL_RESOLUTION', 'FULFILLED', 'DELIVERED', 'REFUNDED'];

    public const INVOICE_NOT_READY = 'not_ready';
    public const INVOICE_UNAVAILABLE_STATE = 'unavailable_state';
    public const INVOICE_NO_REFERENCE = 'no_reference';
    public const INVOICE_ERROR = 'error';

    /**
     * The invoice as issued, streamed by Two itself: without these Two may
     * answer with a redirect (a refunded order to its credit note, or to a
     * download link), and the transport does not follow redirects.
     */
    public const INVOICE_QUERY = '?v=original&proxy=true';

    /** The invoice fetch runs inside an admin page request, so it never waits the API default. */
    public const INVOICE_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly OrderFlow $flow,
        private readonly Client $client,
        private readonly Postprocessing $postprocessing,
        private readonly ErrorMapper $errors,
        private readonly OrderRecords $records,
        private readonly Logger $logger,
    ) {
    }

    /**
     * What moving an order into a status asks of Two: FULFIL, CANCEL,
     * REFUND (the rest of the order) or null. A status in more than one map
     * takes the first, in the order the settings page lists them.
     *
     * @param list<int> $fulfilStatusIds
     */
    public static function action(int $statusId, array $fulfilStatusIds, ?int $cancelStatusId, ?int $refundStatusId = null): ?string
    {
        if ($statusId <= 0) {
            return null;
        }
        if (in_array($statusId, $fulfilStatusIds, true)) {
            return self::FULFIL;
        }
        if ($statusId === $cancelStatusId) {
            return self::CANCEL;
        }

        return $statusId === $refundStatusId ? self::REFUND : null;
    }

    /**
     * The fingerprint of an order's own rows, as stored when Two confirms it
     * and compared before it is fulfilled. Values are hashed as the shop
     * stores them; row ids are left out (OpenCart rewrites the rows on an
     * edit) and rows are sorted, so only a change of content counts.
     *
     * @param array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>, currency?: string} $rows
     */
    public static function rowsHash(array $rows): string
    {
        $canonical = [];
        foreach (['products', 'totals'] as $part) {
            $list = array_map(static fn (array $row): string => (string) json_encode(self::strings($row), JSON_UNESCAPED_UNICODE), $rows[$part]);
            sort($list);
            $canonical[$part] = $list;
        }
        $canonical['addresses'] = self::strings($rows['addresses']);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Fulfils the whole Two order.
     *
     * @param array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>, currency?: string}|null $rows
     *        the order's own rows now; null when the order is gone
     * @return array{outcome: string, reason: string, message: Message}
     */
    public function fulfil(int $orderId, ?array $rows): array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || !OrderFlow::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, Message::of('note_fulfil_no_order'));
        }
        $stored = $record['two_order_state'] ?? '';
        if (in_array($stored, self::FULFILLED_STATES, true)) {
            return self::outcome(self::SKIPPED, Message::of('note_already', $twoOrderId, $stored));
        }
        if ($stored === 'CANCELLED') {
            return $this->refused($orderId, Message::of('note_fulfil_cancelled', $twoOrderId));
        }
        if (($record['rows_hash'] ?? '') === '') {
            return $this->refused($orderId, Message::of('note_fulfil_unhashed', $twoOrderId));
        }
        if (($record['payload_hash'] ?? '') === '') {
            return $this->refused($orderId, Message::of('note_fulfil_edit_unknown', $twoOrderId));
        }
        // Before the rows, which a currency-only edit leaves alike (OpenCart keeps them in the default currency).
        $currency = (string) ($rows['currency'] ?? '');
        $placed = FeeQuote::placedInOtherCurrency($record, $currency);
        if ($placed !== null) {
            return $this->refused($orderId, Message::of('note_fulfil_currency_changed', $twoOrderId, $placed, $currency));
        }
        if ($rows === null || !hash_equals($record['rows_hash'], self::rowsHash($rows))) {
            return $this->refused($orderId, Message::of('note_fulfil_edited', $twoOrderId));
        }

        $state = $this->flow->readState($orderId, $twoOrderId);
        if ($state === null) {
            return $this->refused($orderId, Message::of('note_fulfil_unread', $twoOrderId));
        }
        if (in_array($state, self::FULFILLED_STATES, true)) {
            return self::outcome(self::ALREADY, Message::of('note_already', $twoOrderId, $state));
        }
        if ($state !== 'CONFIRMED') {
            return $this->refused($orderId, Message::of('note_fulfil_state', $twoOrderId, $state));
        }

        $path = '/v1/order/' . $twoOrderId . '/fulfillments';
        try {
            $body = $this->postprocessing->apply(Postprocessing::CAPTURE, 'status_change', $path, [], ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, Message::of('note_fulfil_not_built', $twoOrderId, $refused->note));
        }
        $response = $this->client->request('POST', $path, $body, Client::TIMEOUT_SECONDS, true, IdempotencyKey::header(IdempotencyKey::forTwoOrder(IdempotencyKey::FULFIL, $twoOrderId)));
        if (!$response->reachedTwo() || $response->status >= 400) {
            return $this->refused($orderId, Message::of('note_fulfil_refused', $twoOrderId, $this->errors->noteReason($response)));
        }
        // Marks the fulfilment as asked for before the read back, so a repeat skips even when the read fails.
        $this->records->put($orderId, ['two_order_state' => 'FULFILLING']);
        $after = $this->flow->readState($orderId, $twoOrderId) ?? 'FULFILLING';

        return self::outcome(self::DONE, Message::of('note_fulfilled', $twoOrderId, $after));
    }

    /**
     * Cancels the whole Two order, and reads it back whatever the cancel
     * answered: it counts as cancelled once Two says so.
     *
     * @return array{outcome: string, reason: string, message: Message}
     */
    public function cancel(int $orderId): array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || !OrderFlow::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, Message::of('note_cancel_no_two_order'));
        }
        $stored = $record['two_order_state'] ?? '';
        if ($stored === 'CANCELLED') {
            return self::outcome(self::SKIPPED, Message::of('note_cancel_already', $twoOrderId));
        }
        if (in_array($stored, self::FULFILLED_STATES, true)) {
            return $this->refused($orderId, Message::of('note_cancel_fulfilled', $twoOrderId, $stored));
        }

        ['failure' => $failure, 'status' => $status] = $this->flow->cancelOrder($twoOrderId, 'status_change', $orderId);
        $state = $this->flow->readState($orderId, $twoOrderId);
        if ($state === 'CANCELLED') {
            // 202: Two held it as cancelled already; a refusal read back as cancelled means the same.
            $already = $failure !== null || $status === 202;

            return self::outcome($already ? self::ALREADY : self::DONE, Message::of($already ? 'note_cancel_already' : 'note_cancelled', $twoOrderId));
        }

        $key = match (true) {
            $state === null => 'note_cancel_refused_unread',
            in_array($state, self::FULFILLED_STATES, true) => 'note_cancel_refused_fulfilled',
            default => 'note_cancel_refused',
        };

        return $this->refused($orderId, Message::of(
            $key . ($failure === null ? '' : '_why'),
            $twoOrderId,
            ...($state === null ? [] : [$state]),
            ...($failure === null ? [] : [$failure]),
        ));
    }

    /**
     * The order's invoice PDF, or why there is none. When Two answers that
     * the order is not fulfilled, its state decides the notice, and an order
     * that has just been invoiced is asked once more.
     *
     * @return array{pdf: string}|array{notice: string, state: string}
     */
    public function invoice(int $orderId): array
    {
        $twoOrderId = $this->records->get($orderId)['two_order_id'] ?? '';
        if (!OrderFlow::validTwoOrderId($twoOrderId)) {
            return ['notice' => self::INVOICE_NO_REFERENCE, 'state' => ''];
        }
        $response = $this->fetchInvoice($twoOrderId);
        if (self::isPdf($response)) {
            return ['pdf' => $response->body];
        }
        if ($response->status !== 400 || strtoupper((string) ($response->json()['error_code'] ?? '')) !== 'ORDER_NOT_FULFILLED') {
            $this->logger->error('Order ' . $orderId . ': the invoice for Two order ' . $twoOrderId . ' could not be fetched: ' . $this->errors->reason($response));

            return ['notice' => self::INVOICE_ERROR, 'state' => ''];
        }

        $state = $this->flow->readState($orderId, $twoOrderId);
        if ($state === null) {
            return ['notice' => self::INVOICE_ERROR, 'state' => ''];
        }
        if ($state === 'FULFILLING' || $state === 'FULFILMENT_NEEDS_MANUAL_RESOLUTION') {
            return ['notice' => self::INVOICE_NOT_READY, 'state' => $state];
        }
        if (!in_array($state, ['FULFILLED', 'DELIVERED', 'REFUNDED'], true)) {
            return ['notice' => self::INVOICE_UNAVAILABLE_STATE, 'state' => $state];
        }
        $retry = $this->fetchInvoice($twoOrderId);
        if (self::isPdf($retry)) {
            return ['pdf' => $retry->body];
        }
        $this->logger->error('Order ' . $orderId . ': the invoice for invoiced Two order ' . $twoOrderId . ' could not be fetched: ' . $this->errors->reason($retry));

        return ['notice' => self::INVOICE_ERROR, 'state' => $state];
    }

    private function fetchInvoice(string $twoOrderId): HttpResponse
    {
        return $this->client->request('GET', '/v1/invoice/' . $twoOrderId . '/pdf' . self::INVOICE_QUERY, null, self::INVOICE_TIMEOUT_SECONDS, true, ['Accept' => 'application/pdf']);
    }

    /** A 200 that really is a PDF: an error page is never served as one. */
    private static function isPdf(HttpResponse $response): bool
    {
        return $response->reachedTwo() && $response->status === 200 && str_starts_with($response->body, '%PDF-');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private static function strings(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
        }
        ksort($out);

        return $out;
    }

    /** @return array{outcome: string, reason: string, message: Message} */
    private function refused(int $orderId, Message $message): array
    {
        $this->logger->error('Order ' . $orderId . ': ' . $message->english());

        return self::outcome(self::REFUSED, $message);
    }

    /** @return array{outcome: string, reason: string, message: Message} */
    public static function outcome(string $outcome, Message $message): array
    {
        return ['outcome' => $outcome, 'reason' => $message->english(), 'message' => $message];
    }
}
