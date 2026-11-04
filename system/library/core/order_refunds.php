<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Refunds at Two (Build Guide section 12.2) and the credit note.
 *
 * - The rest of the order, off the refund status map: what Two's order
 *   total leaves once the refunds Two already holds are taken off. With no
 *   earlier refund the body is empty (Two refunds the order in full);
 *   after one, the rest goes as one line per tax rate, taken from Two's own
 *   tax subtotals less its refunds'. Keyed on the earlier refunds' ids and
 *   the amount, so a repeated status event never refunds twice.
 * - A partial refund from the order panel: lines of the Two order with a
 *   quantity, and an amount line the merchant types (net and tax at one of
 *   the order's rates). Each is stored as a plugin row before the call and
 *   keyed on that row's id.
 * - Section 12.2.1: a line keeps the tax the shop recorded, never one the
 *   plugin computes; a line with a net amount but no tax at a taxed rate is
 *   refused, and nothing is sent that is more than remains.
 * - After every refund Two's refunded total is read back. It must have risen
 *   by at least the amount sent; less means Two answered with an earlier
 *   refund under the same key and this one was not applied. More is fine (a
 *   refund made meanwhile elsewhere).
 * - When Two's answer is not received (a timeout, a network error, a 5xx)
 *   and the refunded total does not show the refund, its row is marked
 *   unknown. No other refund is sent until it is retried under its own key.
 */
final class OrderRefunds
{
    /** Two refunds only an invoiced order; REFUNDED is what Two reports after any refund, partial included. */
    public const REFUNDABLE_STATES = ['FULFILLED', 'DELIVERED', 'REFUNDED'];

    /** Sent, but Two's refunded total could not be read back, or did not show it and Two did not answer. */
    public const UNKNOWN = 'unknown';
    /** Two answered with an earlier refund under this key; nothing new was refunded. */
    public const NOT_APPLIED = 'not_applied';

    public const CREDIT_NOTE_NO_REFERENCE = 'no_reference';
    public const CREDIT_NOTE_ERROR = 'error';

    /** Two's own reason field is at most this long. */
    public const REASON_MAX = 255;

    public function __construct(
        private readonly Client $client,
        private readonly Postprocessing $postprocessing,
        private readonly ErrorMapper $errors,
        private readonly OrderRecords $records,
        private readonly RefundRecords $refunds,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Two's order as it stands, its state stored; null when Two did not answer with one.
     *
     * @return array<string, mixed>|null
     */
    public function read(int $orderId, string $twoOrderId, int $timeout = Client::TIMEOUT_SECONDS): ?array
    {
        $response = $this->client->request('GET', '/v1/order/' . $twoOrderId, null, $timeout);
        $body = $response->json();
        if (!$response->reachedTwo() || $response->status >= 400 || !is_string($body['state'] ?? null)) {
            $this->logger->error('Two order ' . $twoOrderId . ' could not be read: ' . $this->errors->reason($response));

            return null;
        }
        $this->records->put($orderId, OrderFlow::stateFields($body));

        return $body;
    }

    /**
     * What the Two tab shows: Two's order as it stands, read for any linked
     * order Two has not cancelled whatever state was stored (a fulfil read
     * back as FULFILLING, or an order fulfilled in the merchant portal, has
     * a stored state that nothing else refreshes), and whether it can be
     * refunded now, judged on that live state.
     *
     * @return array{two: array<string, mixed>|null, state: string, refundable: bool}
     */
    public function panel(int $orderId, int $timeout = OrderLifecycle::INVOICE_TIMEOUT_SECONDS): array
    {
        $record = $this->records->get($orderId) ?? [];
        $twoOrderId = $record['two_order_id'] ?? '';
        $stored = $record['two_order_state'] ?? '';
        if (!OrderFlow::validTwoOrderId($twoOrderId) || $stored === 'CANCELLED') {
            return ['two' => null, 'state' => $stored, 'refundable' => false];
        }
        $two = $this->read($orderId, $twoOrderId, $timeout);
        $state = $two === null ? $stored : strtoupper((string) $two['state']);

        return ['two' => $two, 'state' => $state, 'refundable' => $two !== null && in_array($state, self::REFUNDABLE_STATES, true)];
    }

    /**
     * What Two has refunded on the order, in cents. Two reports each refund's total as a negative amount.
     *
     * @param array<string, mixed> $twoOrder
     */
    public static function refundedCents(array $twoOrder): int
    {
        $total = 0;
        foreach (self::list($twoOrder['refunds'] ?? null) as $refund) {
            $total += abs(Postprocessing::cents($refund['total_amount'] ?? 0));
        }

        return $total;
    }

    /**
     * What is left to refund, in cents; null when Two's order total cannot be read.
     *
     * @param array<string, mixed> $twoOrder
     */
    public static function remainingCents(array $twoOrder): ?int
    {
        $gross = Postprocessing::cents($twoOrder['gross_amount'] ?? null);

        return $gross > 0 ? max(0, $gross - self::refundedCents($twoOrder)) : null;
    }

    /**
     * The Two order's lines the panel offers: those with a positive net (a
     * discount line is never refunded on its own).
     *
     * @param array<string, mixed> $twoOrder
     * @return list<array{id: string, name: string, quantity: int, net_amount: string, tax_amount: string, tax_rate: string}>
     */
    public static function refundableLines(array $twoOrder): array
    {
        $lines = [];
        foreach (self::list($twoOrder['line_items'] ?? null) as $line) {
            $id = is_string($line['id'] ?? null) ? $line['id'] : '';
            $quantity = (int) ($line['quantity'] ?? 0);
            if (!OrderFlow::validTwoOrderId($id) || $quantity <= 0 || Postprocessing::cents($line['net_amount'] ?? 0) <= 0) {
                continue;
            }
            $lines[] = [
                'id' => $id,
                'name' => (string) ($line['name'] ?? ''),
                'quantity' => $quantity,
                'net_amount' => Postprocessing::money(Postprocessing::cents($line['net_amount'])),
                'tax_amount' => Postprocessing::money(Postprocessing::cents($line['tax_amount'] ?? 0)),
                'tax_rate' => OrderBuilder::rate((float) ($line['tax_rate'] ?? 0)),
            ];
        }

        return $lines;
    }

    /**
     * What the shop's earlier refunds took from each Two order line, from
     * the rows Two accepted or may have (unknown or never settled): the
     * quantity, net and tax by line id.
     *
     * @param list<array<string, string>> $rows the order's refund rows
     * @return array<string, array{quantity: int, net: int, tax: int}>
     */
    public static function refundedPerLine(array $rows): array
    {
        $per = [];
        foreach ($rows as $row) {
            if (!in_array($row['state'] ?? '', [RefundRecords::DONE, RefundRecords::UNKNOWN, RefundRecords::PENDING], true)) {
                continue;
            }
            $payload = json_decode($row['payload'] ?? '', true);
            foreach (self::list(is_array($payload) ? ($payload['line_items'] ?? null) : null) as $line) {
                $id = is_string($line['id'] ?? null) ? $line['id'] : '';
                if ($id === '') {
                    continue;
                }
                $per[$id] ??= ['quantity' => 0, 'net' => 0, 'tax' => 0];
                $per[$id]['quantity'] += (int) ($line['quantity'] ?? 0);
                $per[$id]['net'] += Postprocessing::cents($line['net_amount'] ?? 0);
                $per[$id]['tax'] += Postprocessing::cents($line['tax_amount'] ?? 0);
            }
        }

        return $per;
    }

    /**
     * The tax rates of the Two order's lines, the only rates an amount line may carry.
     *
     * @param array<string, mixed> $twoOrder
     * @return list<string>
     */
    public static function rates(array $twoOrder): array
    {
        $rates = [];
        foreach (self::list($twoOrder['line_items'] ?? null) as $line) {
            $rates[OrderBuilder::rate((float) ($line['tax_rate'] ?? 0))] = true;
        }
        $rates = array_map('strval', array_keys($rates));
        sort($rates);

        return $rates;
    }

    /**
     * Section 12.2.1: a line with a net amount but no tax at a rate that
     * taxes it. The rounding allowance is Two's own: tax under 2 cents at
     * that rate may round to nothing.
     */
    public static function untaxed(int $netCents, int $taxCents, string $rate): bool
    {
        return $taxCents === 0 && (int) round(abs($netCents) * (float) $rate) > 2;
    }

    /**
     * Refunds what is left of the order, from the refund status map.
     *
     * @return array{outcome: string, reason: string, message: Message}
     */
    public function remainder(int $orderId): array
    {
        return $this->locked($orderId, fn (): array => $this->remainderLocked($orderId));
    }

    /** @return array{outcome: string, reason: string, message: Message} */
    private function remainderLocked(int $orderId): array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || !OrderFlow::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, Message::of('note_refund_no_order'));
        }
        $blocked = $this->blocked($orderId);
        if ($blocked !== null) {
            return $blocked;
        }
        $storedState = $record['two_order_state'] ?? '';
        $two = $this->read($orderId, $twoOrderId);
        if ($two === null) {
            return $this->refused($orderId, Message::of('note_refund_status_unread', $twoOrderId));
        }
        $state = strtoupper((string) $two['state']);
        if (!in_array($state, self::REFUNDABLE_STATES, true)) {
            return $this->refused($orderId, Message::of('note_refund_state', $twoOrderId, $state));
        }
        $remaining = self::remainingCents($two);
        if ($remaining === null) {
            return $this->refused($orderId, Message::of('note_refund_total_unread', $twoOrderId));
        }
        if ($remaining === 0) {
            return self::outcome($storedState === 'REFUNDED' ? OrderLifecycle::SKIPPED : OrderLifecycle::ALREADY, Message::of('note_refund_already', $twoOrderId));
        }

        $prior = [];
        foreach (self::list($two['refunds'] ?? null) as $refund) {
            $prior[] = (string) ($refund['id'] ?? '');
        }
        if ($prior === []) {
            // Nothing refunded yet: an empty body refunds the order in full, Two copying its own lines.
            $payload = [];
            $amount = $remaining;
        } else {
            $payload = $this->restPayload($orderId, $two, $remaining);
            if ($payload === null) {
                return $this->refused($orderId, Message::of('note_refund_split', $twoOrderId, Postprocessing::money($remaining)));
            }
            $amount = Postprocessing::cents($payload['amount']);
        }
        $key = IdempotencyKey::refundRemainder($twoOrderId, $prior, Postprocessing::money($amount));

        return $this->send($orderId, $twoOrderId, $two, $payload, $amount, $key, 'status_change', RefundRecords::KIND_REMAINDER);
    }

    /**
     * A partial refund from the order panel.
     *
     * @param array{lines?: array<string, int>, net?: string, tax?: string, rate?: string, reason?: string} $request
     *        quantities by Two line id; the amount line's net, tax and rate as typed
     * @return array{outcome: string, reason: string, message: Message}
     */
    public function partial(int $orderId, array $request): array
    {
        return $this->locked($orderId, fn (): array => $this->partialLocked($orderId, $request));
    }

    /**
     * @param array{lines?: array<string, int>, net?: string, tax?: string, rate?: string, reason?: string} $request
     * @return array{outcome: string, reason: string, message: Message}
     */
    private function partialLocked(int $orderId, array $request): array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || !OrderFlow::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, Message::of('note_refund_no_order'));
        }
        $blocked = $this->blocked($orderId);
        if ($blocked !== null) {
            return $blocked;
        }
        $two = $this->read($orderId, $twoOrderId);
        if ($two === null) {
            return $this->refused($orderId, Message::of('note_refund_unread', $twoOrderId));
        }
        $state = strtoupper((string) $two['state']);
        if (!in_array($state, self::REFUNDABLE_STATES, true)) {
            return $this->refused($orderId, Message::of('note_refund_not_fulfilled', $twoOrderId, $state));
        }

        try {
            $lines = $this->requestLines($two, $request, $orderId);
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, Message::of('note_refund_form_not_built', $refused->note));
        }
        $totals = Postprocessing::recomputeTotals(['line_items' => $lines]);
        $payload = ['amount' => $totals['gross_amount'], 'currency' => (string) ($two['currency'] ?? ''), 'line_items' => $lines, 'tax_subtotals' => $totals['tax_subtotals']];
        $reason = trim((string) ($request['reason'] ?? ''));
        if ($reason !== '') {
            $payload['reason'] = mb_substr($reason, 0, self::REASON_MAX);
        }

        return $this->send($orderId, $twoOrderId, $two, $payload, Postprocessing::cents($payload['amount']), null, 'refund_form', RefundRecords::KIND_PARTIAL);
    }

    /**
     * The order's latest credit note, streamed by Two itself.
     *
     * @return array{pdf: string}|array{notice: string}
     */
    public function creditNote(int $orderId): array
    {
        $twoOrderId = $this->records->get($orderId)['two_order_id'] ?? '';
        if (!OrderFlow::validTwoOrderId($twoOrderId)) {
            return ['notice' => self::CREDIT_NOTE_NO_REFERENCE];
        }
        $response = $this->client->request('GET', '/v1/credit_note/' . $twoOrderId . '/pdf?proxy=true', null, OrderLifecycle::INVOICE_TIMEOUT_SECONDS, true, ['Accept' => 'application/pdf']);
        if ($response->reachedTwo() && $response->status === 200 && str_starts_with($response->body, '%PDF-')) {
            return ['pdf' => $response->body];
        }
        $this->logger->error('Order ' . $orderId . ': the credit note for Two order ' . $twoOrderId . ' could not be fetched: ' . $this->errors->reason($response));

        return ['notice' => self::CREDIT_NOTE_ERROR];
    }

    /**
     * The panel's request as refund lines, each checked.
     *
     * @param array<string, mixed>                                                                     $two
     * @param array{lines?: array<string, int>, net?: string, tax?: string, rate?: string, reason?: string} $request
     * @return list<array<string, mixed>>
     *
     * @throws OrderRefused
     */
    private function requestLines(array $two, array $request, int $orderId): array
    {
        $earlier = self::refundedPerLine($this->refunds->forOrder($orderId));
        $byId = [];
        foreach (self::refundableLines($two) as $line) {
            $byId[$line['id']] = $line;
        }
        $lines = [];
        foreach ($request['lines'] ?? [] as $id => $quantity) {
            if ($quantity <= 0) {
                continue;
            }
            $line = $byId[(string) $id] ?? null;
            if ($line === null) {
                throw OrderRefused::because('refund_line_unknown', Message::of('refusal_refund_line_unknown'));
            }
            $done = $earlier[$line['id']] ?? ['quantity' => 0, 'net' => 0, 'tax' => 0];
            $left = $line['quantity'] - $done['quantity'];
            if ($quantity > $left) {
                throw OrderRefused::because('refund_line_quantity', Message::of('refusal_refund_line_quantity', $line['name'], max(0, $left), $line['quantity']));
            }
            // The share of the line's own net and tax; the last of the line takes what earlier refunds left, so the pieces add up to what Two holds.
            $lineNet = Postprocessing::cents($line['net_amount']);
            $lineTax = Postprocessing::cents($line['tax_amount']);
            $net = $quantity === $left ? $lineNet - $done['net'] : intdiv($lineNet * $quantity * 2 + $line['quantity'], $line['quantity'] * 2);
            $tax = $quantity === $left ? $lineTax - $done['tax'] : intdiv($lineTax * $quantity * 2 + $line['quantity'], $line['quantity'] * 2);
            $lines[] = ['id' => $line['id']] + self::checkedLine($line['name'], $quantity, $net, $tax, $line['tax_rate'], 'pcs');
        }

        $netTyped = trim((string) ($request['net'] ?? ''));
        $taxTyped = trim((string) ($request['tax'] ?? ''));
        if ($netTyped !== '' || $taxTyped !== '') {
            foreach (['net' => $netTyped, 'tax' => $taxTyped === '' ? '0' : $taxTyped] as $field => $typed) {
                if (preg_match('~^\d{1,9}(\.\d{1,2})?\z~', $typed) !== 1) {
                    throw OrderRefused::because('refund_amount_format', Message::of($field === 'net' ? 'refusal_refund_amount_net' : 'refusal_refund_amount_tax'));
                }
            }
            $rate = OrderBuilder::rate((float) ($request['rate'] ?? 0));
            if (!in_array($rate, self::rates($two), true)) {
                throw OrderRefused::because('refund_amount_rate', Message::of('refusal_refund_amount_rate'));
            }
            $net = Postprocessing::cents($netTyped);
            if ($net <= 0) {
                throw OrderRefused::because('refund_amount_zero', Message::of('refusal_refund_amount_zero'));
            }
            $name = 'Refund on order ' . $orderId;
            $lines[] = self::checkedLine($name, 1, $net, Postprocessing::cents($taxTyped === '' ? '0' : $taxTyped), $rate, 'item');
        }
        if ($lines === []) {
            throw OrderRefused::because('refund_empty', Message::of('refusal_refund_empty'));
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OrderRefused
     */
    private static function checkedLine(string $name, int $quantity, int $net, int $tax, string $rate, string $unit): array
    {
        if (self::untaxed($net, $tax, $rate)) {
            throw OrderRefused::because('refund_line_untaxed', Message::of('refusal_refund_line_untaxed', $name, $rate));
        }

        return OrderBuilder::line($name, 'PHYSICAL', $quantity, intdiv($net, max(1, $quantity)), $net, $tax, (float) $rate, $unit);
    }

    /**
     * The per-rate split of an order or a refund as Two holds it: its tax
     * subtotals, else, when it carries none (Validate tax subtotals off sends
     * none), the sums of its own lines per rate. Empty when it has neither,
     * so the rest does not add up and is refused rather than guessed.
     *
     * @param array<string, mixed> $item
     * @return list<array<string, mixed>>
     */
    private static function split(array $item): array
    {
        $subtotals = self::list($item['tax_subtotals'] ?? null);
        if ($subtotals !== []) {
            return $subtotals;
        }
        $lines = self::list($item['line_items'] ?? null);

        return $lines === [] ? [] : self::list(Postprocessing::recomputeTotals(['line_items' => $lines])['tax_subtotals'] ?? null);
    }

    /**
     * The rest of a partly refunded order, one line per tax rate: Two's split
     * for the order less that of each refund it holds (split()). Null when
     * those do not add up to what remains (within a cent a rate).
     *
     * @param array<string, mixed> $two
     * @return array<string, mixed>|null
     */
    private function restPayload(int $orderId, array $two, int $remaining): ?array
    {
        $buckets = [];
        $add = static function (mixed $subtotals, int $sign) use (&$buckets): void {
            foreach (self::list($subtotals) as $subtotal) {
                $rate = OrderBuilder::rate((float) ($subtotal['tax_rate'] ?? 0));
                $buckets[$rate] ??= ['net' => 0, 'tax' => 0];
                $buckets[$rate]['net'] += $sign * abs(Postprocessing::cents($subtotal['taxable_amount'] ?? 0));
                $buckets[$rate]['tax'] += $sign * abs(Postprocessing::cents($subtotal['tax_amount'] ?? 0));
            }
        };
        $add(self::split($two), 1);
        foreach (self::list($two['refunds'] ?? null) as $refund) {
            $add(self::split($refund), -1);
        }
        $lines = [];
        foreach ($buckets as $rate => $bucket) {
            if ($bucket['net'] + $bucket['tax'] <= 0) {
                continue;
            }
            if ($bucket['net'] < 0 || $bucket['tax'] < 0 || self::untaxed($bucket['net'], $bucket['tax'], (string) $rate)) {
                return null;
            }
            $lines[] = OrderBuilder::line('Refund of the rest of order ' . $orderId, 'PHYSICAL', 1, $bucket['net'], $bucket['net'], $bucket['tax'], (float) $rate, 'item');
        }
        if ($lines === []) {
            return null;
        }
        $totals = Postprocessing::recomputeTotals(['line_items' => $lines]);
        $gross = Postprocessing::cents($totals['gross_amount']);
        if ($gross > $remaining || $remaining - $gross > count($lines)) {
            return null;
        }

        return ['amount' => $totals['gross_amount'], 'currency' => (string) ($two['currency'] ?? ''), 'line_items' => $lines, 'tax_subtotals' => $totals['tax_subtotals']];
    }

    /**
     * The order's refund whose outcome is not known yet (no answer from Two,
     * or the request never settled), or null. While there is one, no other
     * refund is sent: a new one would carry a new key, and Two could refund
     * twice. It is retried under its own key instead.
     *
     * @return array<string, string>|null
     */
    public function unresolved(int $orderId): ?array
    {
        foreach ($this->refunds->forOrder($orderId) as $row) {
            if (in_array($row['state'] ?? '', [RefundRecords::UNKNOWN, RefundRecords::PENDING], true)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Sends a refund whose outcome is unknown again: the same payload under
     * the same key, so Two applies it at most once, then the usual check on
     * Two's refunded total. If Two answers with a refund it already held
     * before this retry, that is the earlier attempt, applied after all.
     *
     * @return array{outcome: string, reason: string, message: Message}
     */
    public function retry(int $orderId, int $refundId): array
    {
        return $this->locked($orderId, fn (): array => $this->retryLocked($orderId, $refundId));
    }

    /** @return array{outcome: string, reason: string, message: Message} */
    private function retryLocked(int $orderId, int $refundId): array
    {
        $row = null;
        foreach ($this->refunds->forOrder($orderId) as $candidate) {
            if ((int) $candidate['refund_id'] === $refundId) {
                $row = $candidate;
            }
        }
        if ($row === null || !in_array($row['state'], [RefundRecords::UNKNOWN, RefundRecords::PENDING], true)) {
            return $this->refused($orderId, Message::of('note_retry_not_waiting'));
        }
        $twoOrderId = $row['two_order_id'];
        $payload = json_decode($row['payload'], true);
        $key = $row['idempotency_key'];
        if ($key === '' && $row['kind'] === RefundRecords::KIND_PARTIAL && OrderFlow::validTwoOrderId($twoOrderId)) {
            // The request stopped between the row and its key: a partial refund's key is its row id by definition.
            $key = IdempotencyKey::refund($twoOrderId, $refundId);
            $this->refunds->update($refundId, ['idempotency_key' => $key]);
        }
        if (!OrderFlow::validTwoOrderId($twoOrderId) || !is_array($payload) || $key === '') {
            return $this->refused($orderId, Message::of('note_retry_unreadable'));
        }
        $two = $this->read($orderId, $twoOrderId);
        if ($two === null) {
            return $this->refused($orderId, Message::of('note_retry_unread', $twoOrderId), self::UNKNOWN);
        }

        $first = ($row['refunded_before'] ?? '') === '' ? null : Postprocessing::cents($row['refunded_before']);
        $amount = Postprocessing::cents($row['amount']);
        if (!array_key_exists('amount', $payload)) {
            // A full refund's body (empty, or with only fields a hook added) asks Two for whatever remains now:
            // after a refund made meanwhile (in the merchant portal) that is less than when it was first sent,
            // and that is what to look for.
            $amount = min($amount, self::remainingCents($two) ?? $amount);
            if ($amount <= 0) {
                $result = $this->done($orderId, $key, Message::of('note_refund_nothing_left', $twoOrderId));

                return $this->settled($refundId, RefundRecords::DONE, $result);
            }
        }
        // An explicit amount over what remains is still sent under its key: Two answers with the earlier
        // attempt if it applied it, and refuses it otherwise, which settles the row either way.

        return $this->post($orderId, $twoOrderId, $two, $refundId, $payload, $amount, $key, $row['currency'], true, $first);
    }

    /**
     * Stores the refund, sends it through the hook and checks Two applied it.
     *
     * @param array<string, mixed> $two     Two's order as read just before
     * @param array<string, mixed> $payload
     * @param string|null          $key     null for a partial refund, keyed on its new row
     * @return array{outcome: string, reason: string, message: Message}
     */
    private function send(int $orderId, string $twoOrderId, array $two, array $payload, int $amount, ?string $key, string $trigger, string $kind): array
    {
        $path = '/v1/order/' . $twoOrderId . '/refund';
        try {
            $body = $this->postprocessing->apply(Postprocessing::REFUND, $trigger, $path, $payload, ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, Message::of('note_refund_not_built', $twoOrderId, $refused->note));
        }
        // The amount Two is asked for: the hooked body's when it names one, else the plugin's own (a full
        // refund's empty body may come back with a field added, and still asks Two for the whole rest).
        if (array_key_exists('amount', $body)) {
            $amount = Postprocessing::cents($body['amount']);
        }
        $remaining = self::remainingCents($two) ?? 0;
        if ($amount <= 0 || $amount > $remaining) {
            return $this->refused($orderId, Message::of('note_refund_too_much', $twoOrderId, Postprocessing::money($amount), Postprocessing::money($remaining)));
        }

        $currency = (string) ($two['currency'] ?? '');
        $fields = ['two_order_id' => $twoOrderId, 'kind' => $kind, 'amount' => Postprocessing::money($amount), 'currency' => $currency,
            'payload' => (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'state' => RefundRecords::PENDING, 'idempotency_key' => $key ?? '',
            'refunded_before' => Postprocessing::money(self::refundedCents($two))];
        $rowId = $this->refunds->create($orderId, $fields);
        if ($key === null) {
            if ($rowId <= 0) {
                return $this->refused($orderId, Message::of('note_refund_not_stored', $twoOrderId));
            }
            $key = IdempotencyKey::refund($twoOrderId, $rowId);
            $this->refunds->update($rowId, ['idempotency_key' => $key]);
        }

        return $this->post($orderId, $twoOrderId, $two, $rowId, $body, $amount, $key, $currency, false);
    }

    /**
     * Posts a stored refund and settles its row from Two's answer and the refunded total read back.
     *
     * @param array<string, mixed> $two   Two's order as read just before
     * @param array<string, mixed> $body
     * @param int|null             $first on a retry, Two's refunded total in cents before the first attempt, when it was kept
     * @return array{outcome: string, reason: string, message: Message}
     */
    private function post(int $orderId, string $twoOrderId, array $two, int $rowId, array $body, int $amount, string $key, string $currency, bool $retry, ?int $first = null): array
    {
        $before = self::refundedCents($two);
        $beforeIds = array_map(static fn (array $refund): string => (string) ($refund['id'] ?? ''), self::list($two['refunds'] ?? null));
        $response = $this->client->request('POST', '/v1/order/' . $twoOrderId . '/refund', $body, Client::TIMEOUT_SECONDS, true, IdempotencyKey::header($key));
        $money = Postprocessing::money($amount) . ' ' . $currency;
        if ($response->reachedTwo() && $response->status >= 400 && $response->status < 500) {
            $result = $this->refused($orderId, Message::of('note_refund_refused', $money, $twoOrderId, $this->errors->noteReason($response)));

            return $this->settled($rowId, RefundRecords::REFUSED, $result);
        }
        $after = $this->read($orderId, $twoOrderId);
        $answered = $response->reachedTwo() && $response->status < 300;
        if ($after !== null && self::refundedCents($after) - $before >= $amount) {
            return $this->settled($rowId, RefundRecords::DONE, $this->done($orderId, $key, Message::of('note_refunded', $money, $twoOrderId)));
        }
        $answeredId = (string) ($response->json()['id'] ?? '');
        if ($retry && $answered && $answeredId !== '' && in_array($answeredId, $beforeIds, true)) {
            return $this->settled($rowId, RefundRecords::DONE, $this->done($orderId, $key, Message::of('note_refunded_earlier', $money, $twoOrderId)));
        }
        // Only with an answer from Two to this retry: a rise alone could be a refund made in the merchant portal.
        if ($retry && $answered && $first !== null && $after !== null && self::refundedCents($after) - $first >= $amount) {
            return $this->settled($rowId, RefundRecords::DONE, $this->done($orderId, $key, Message::of('note_refunded_risen', $money, $twoOrderId)));
        }
        if ($answered && $after !== null) {
            $result = $this->refused($orderId, Message::of('note_refund_not_applied', $money, $twoOrderId, $key), self::NOT_APPLIED);

            return $this->settled($rowId, RefundRecords::NOT_APPLIED, $result);
        }
        $result = $this->refused($orderId, $answered ? Message::of('note_refund_unknown_unread', $money, $twoOrderId) : Message::of('note_refund_unknown', $money, $twoOrderId, $this->errors->noteReason($response)), self::UNKNOWN);

        return $this->settled($rowId, RefundRecords::UNKNOWN, $result);
    }

    /**
     * Runs one refund operation with the order's refund lock held, so a
     * status-map refund and a form refund can never both pass the checks.
     *
     * @param \Closure(): array{outcome: string, reason: string, message: Message} $work
     * @return array{outcome: string, reason: string, message: Message}
     */
    private function locked(int $orderId, \Closure $work): array
    {
        if (!$this->refunds->lock($orderId)) {
            return $this->refused($orderId, Message::of('note_refund_locked'));
        }
        try {
            return $work();
        } finally {
            $this->refunds->unlock($orderId);
        }
    }

    /** @return array{outcome: string, reason: string, message: Message}|null the refusal while a refund awaits a retry */
    private function blocked(int $orderId): ?array
    {
        $row = $this->unresolved($orderId);

        return $row === null ? null : $this->refused($orderId, Message::of('note_refund_blocked', $row['amount'] . ' ' . $row['currency'], $row['date_added']));
    }

    /**
     * @param array{outcome: string, reason: string, message: Message} $result
     * @return array{outcome: string, reason: string, message: Message}
     */
    private function settled(int $rowId, string $state, array $result): array
    {
        if ($rowId > 0) {
            $this->refunds->update($rowId, ['state' => $state, 'reason' => $result['message']->encode()]);
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * A refund Two applied, logged with its key whatever the debug setting, as every order write is.
     *
     * @return array{outcome: string, reason: string, message: Message}
     */
    private function done(int $orderId, string $key, Message $message): array
    {
        $this->logger->info('Order ' . $orderId . ': ' . $message->english() . ' Idempotency key ' . $key . '.');

        return self::outcome(OrderLifecycle::DONE, $message);
    }

    /** @return array{outcome: string, reason: string, message: Message} */
    private function refused(int $orderId, Message $message, string $outcome = OrderLifecycle::REFUSED): array
    {
        $this->logger->error('Order ' . $orderId . ': ' . $message->english());

        return self::outcome($outcome, $message);
    }

    /** @return array{outcome: string, reason: string, message: Message} */
    private static function outcome(string $outcome, Message $message): array
    {
        return OrderLifecycle::outcome($outcome, $message);
    }
}
