<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The hosted checkout flow (Build Guide sections 6.3, 6.4, 11 and 21): create
 * the Two order from the shop's stored order and send the buyer to Two's
 * payment URL; then, when Two redirects back, confirm or cancel it.
 *
 * - Every order request goes through Postprocessing just before it is sent.
 * - The callbacks are guarded by a per-order token: its hash is stored with
 *   the order, the token itself only travels in the callback URLs Two holds.
 * - The order Two confirms is the order the shop will place: the create
 *   payload's hash is stored, and confirm rebuilds the payload from the
 *   shop's stored order (hook included, section 21.1) and refuses on any
 *   difference. OpenCart rewrites an unplaced order on every checkout
 *   render, so the cart can change after the Two order was created.
 * - Two's order state decides the outcome, never the URL the buyer arrived on.
 * - Outcomes are plain arrays the adapter turns into order history, notices
 *   and redirects. A refusal carries the buyer's language keys and the
 *   merchant's reason separately, so Two's text never reaches the buyer.
 */
final class OrderFlow
{
    public const TOKEN_BYTES = 24;

    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const REFUSED = 'refused';
    /** Refused because the shop order changed after the Two order was created. */
    public const CHANGED = 'changed';
    public const UNAUTHORISED = 'unauthorised';
    /** A check from the schedule or the admin changed nothing: the order still waits, and is checked again later. */
    public const WAITING = 'waiting';
    /** Two answered 404 for the Two order: it does not know it (counted only by PendingCheck, never on the buyer's return). */
    public const MISSING = 'missing';

    /** What asked for the Two order's state: the buyer's return, the scheduled check or the admin's button. */
    public const TRIGGER_BUYER = 'buyer_return';
    public const TRIGGER_SCHEDULED = 'scheduled_check';
    public const TRIGGER_ADMIN = 'admin_check';

    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    /** The intent could not be asked (no answer, an error, an answer without a decision). */
    public const UNDECIDED = 'undecided';

    /** The buyer's sentence when another placement of the same order is still running. */
    public const IN_PROGRESS = 'error_order_in_progress';

    /** The buyer's sentences when placement is refused on the intent (language keys). */
    public const INTENT_DECLINED = 'text_intent_declined';
    public const INTENT_CONNECTION = 'error_intent_connection';
    public const INTENT_UNAVAILABLE = 'error_intent_unavailable';
    public const INTENT_UNPROCESSABLE = 'error_intent_unprocessable';

    /**
     * What the order intent asks about: the create payload without what only an order needs.
     * Two's API does not define an order-level discount_amount or billing_address on the intent
     * request and ignores them, so they are not sent (a line's own discount_amount stays).
     */
    public const INTENT_KEYS = ['gross_amount', 'net_amount', 'tax_amount', 'currency', 'buyer', 'shipping_address', 'line_items'];

    public const CALLBACK_CONFIRM = 'confirmation';
    public const CALLBACK_CANCEL = 'cancel';

    /** Two order states in which the buyer has verified and the order stands. */
    public const PLACED_STATES = ['CONFIRMED', 'FULFILLING', 'FULFILMENT_NEEDS_MANUAL_RESOLUTION', 'FULFILLED', 'DELIVERED', 'REFUNDED'];

    public function __construct(
        private readonly Client $client,
        private readonly OrderBuilder $builder,
        private readonly Postprocessing $postprocessing,
        private readonly ErrorMapper $errors,
        private readonly OrderRecords $records,
        private readonly Logger $logger,
    ) {
    }

    /** A fresh callback token, hex, for the merchant URLs of one order create. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Creates the Two order. On success the buyer goes to the redirect URL.
     *
     * @param array<string, mixed>       $order   OrderBuilder's order shape, merchant_urls carrying the token
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $totals
     * @param array<string, mixed>       $context platform objects and request facts for the hook
     * @return array{ok: true, redirect: string, two_order_id: string}|array{ok: false, buyer: non-empty-list<string>, reason: string, message: Message}
     */
    public function create(array $order, array $lines, array $totals, string $token, array $context = []): array
    {
        $orderId = (int) $order['order_id'];
        $company = is_array($order['company'] ?? null) ? $order['company'] : [];
        foreach (['company_name', 'organization_number', 'country_code'] as $field) {
            if (trim((string) ($company[$field] ?? '')) === '') {
                return self::failed([ErrorMapper::COMPANY_REQUIRED], Message::of('note_no_company'));
            }
        }
        $tooLong = [];
        foreach (OrderRecords::MAX_LENGTHS as $field => $max) {
            if (mb_strlen(trim((string) $company[$field])) > $max) {
                $tooLong[] = $field === 'company_name' ? ErrorMapper::FIELDS['buyer.company.company_name'] : ($field === 'organization_number' ? ErrorMapper::FIELDS['buyer.company.organization_number'] : ErrorMapper::FIELDS['billing_address.country']);
            }
        }
        if ($tooLong !== []) {
            return self::failed($tooLong, Message::of('note_company_too_long'));
        }

        try {
            $payload = $this->payload($order, $lines, $totals, $context, 'checkout');
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, Message::of('note_create_not_built', $refused->note));
        }

        // A re-create (the buyer placed the order again) replaces the earlier
        // Two order, so cancel that first rather than leave it open at Two.
        $earlier = $this->records->get($orderId) ?? [];
        $earlierId = $earlier['two_order_id'] ?? '';
        if ($earlierId !== '' && self::validTwoOrderId($earlierId) && ($earlier['two_order_state'] ?? '') !== 'CANCELLED') {
            $failure = $this->sendCancel($earlierId, 'order_recreate', $orderId);
            if ($failure !== null && $this->readState($orderId, $earlierId) !== 'CANCELLED') {
                return $this->refused($orderId, Message::of('note_create_earlier_open', $earlierId, $failure));
            }
        }

        $stored = [
            'token_hash' => self::tokenHash($token),
            'payload_hash' => self::payloadHash($payload),
            'two_order_id' => '',
            'two_order_state' => '',
            'two_order_status' => '',
            'company_name' => trim((string) $company['company_name']),
            'organization_number' => trim((string) $company['organization_number']),
            'country_code' => trim((string) $company['country_code']),
            'buyer_fields' => BuyerFields::encode(is_array($order['buyer_fields'] ?? null) ? $order['buyer_fields'] : []),
            'payment_terms' => is_array($order['terms'] ?? null) ? (string) json_encode($order['terms']) : '',
            // An edit puts back the fee the order was placed with, and never adds one.
            'payment_fee' => in_array('two_fee', array_column($totals, 'code'), true) ? '1' : '0',
            'placed_fee' => FeeQuote::encodePlaced($totals, (float) $order['currency_value'], (string) $order['currency']),
        ];
        $this->records->put($orderId, $stored);
        $record = $this->records->get($orderId) ?? [];
        foreach ($stored as $field => $value) {
            if (($record[$field] ?? null) !== $value) {
                return $this->refused($orderId, Message::of('note_create_not_stored', $field));
            }
        }
        // A new Two order starts the pending check afresh (PendingCheck).
        $this->records->put($orderId, ['checked_at' => '0', 'missing_at' => '0', 'check_closed' => '']);

        $response = $this->client->request('POST', '/v1/order', $payload, Client::TIMEOUT_SECONDS, true, IdempotencyKey::header(IdempotencyKey::orderCreate($orderId, $stored['token_hash'])));
        $body = $response->json();
        if (!$response->reachedTwo() || $response->status >= 400) {
            return $this->refused($orderId, Message::of('note_create_refused', $this->errors->noteReason($response)), $this->errors->buyerMessageKeys($response->status, $body));
        }
        $twoOrderId = is_scalar($body['id'] ?? null) ? (string) $body['id'] : '';
        $paymentUrl = is_string($body['payment_url'] ?? null) ? $body['payment_url'] : '';
        if (!self::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, Message::of('note_create_no_id', $response->status));
        }
        $this->records->put($orderId, ['two_order_id' => $twoOrderId] + self::stateFields($body));
        if (preg_match('~^https://~', $paymentUrl) !== 1) {
            $this->sendCancel($twoOrderId, 'missing_payment_url', $orderId);

            return $this->refused($orderId, Message::of('note_create_no_url', $twoOrderId));
        }

        return ['ok' => true, 'redirect' => $paymentUrl, 'two_order_id' => $twoOrderId];
    }

    /**
     * Two sent the buyer back to the confirmation URL. Confirms a verified
     * order whose payload the shop's stored order still rebuilds exactly; an
     * order Two already holds as placed confirms again harmlessly.
     *
     * @param \Closure(array<string, string>): (array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>}|null) $rebuild
     *        the shop's stored order as create's inputs, given the stored record (company included); null when it is gone
     * @param array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>, currency?: string}|null $rows
     *        the order's own rows, whose hash is kept once Two holds the order as placed, for the check before fulfilment
     * @return array{outcome: string, reason: string, message: Message, two_order_id: string, state: string}
     */
    public function confirm(int $orderId, string $token, \Closure $rebuild, ?array $rows = null): array
    {
        $record = $this->authorised($orderId, $token);
        if ($record === null) {
            return self::outcome(self::UNAUTHORISED, Message::of('note_confirm_token'), '');
        }

        return $this->resolve($orderId, $record, self::TRIGGER_BUYER, $rebuild, $rows);
    }

    /**
     * The one resolver behind the buyer's return, the scheduled check and the
     * admin's button (TWO-26300): read the Two order, then act on its state.
     * The caller holds the order's lock and has found the shop order still
     * unplaced.
     *
     * - The buyer's return keeps its own rules: a changed order is cancelled
     *   at Two and the buyer told, and any state but VERIFIED or placed is
     *   refused.
     * - The schedule and the button send Two nothing but the confirm of a
     *   VERIFIED order whose shop order still rebuilds what Two holds. A
     *   changed order, a cancelled one, an unverified, refunded or unknown
     *   state, a failed read or a failed confirm change nothing at Two; a
     *   failed confirm leaves the order waiting for the next check.
     *
     * @param array<string, string> $record the order's record
     * @param \Closure(array<string, string>): (array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>}|null) $rebuild
     * @param array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>, currency?: string}|null $rows
     * @param Message|null $by who checked, for the note of an order the schedule or the button places
     * @return array{outcome: string, reason: string, message: Message, two_order_id: string, state: string}
     */
    public function resolve(int $orderId, array $record, string $trigger, \Closure $rebuild, ?array $rows = null, ?Message $by = null): array
    {
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($twoOrderId === '' || !self::validTwoOrderId($twoOrderId)) {
            return self::outcome(self::REFUSED, Message::of('note_confirm_no_order'), '');
        }
        $buyer = $trigger === self::TRIGGER_BUYER;
        $by ??= Message::of('note_by_schedule');

        if ($buyer) {
            $changed = $this->snapshotMismatch($record, $rebuild);
            if ($changed !== null) {
                $this->sendCancel($twoOrderId, 'order_changed', $orderId);
                $this->readState($orderId, $twoOrderId);

                return self::outcome($changed[0], Message::of($changed[1], $twoOrderId, ...$changed[2]), $twoOrderId);
            }
        }

        $read = $this->read($orderId, $twoOrderId);
        $state = $read['state'];
        if ($state === null) {
            if ($buyer) {
                return self::outcome(self::REFUSED, Message::of('note_confirm_unread', $twoOrderId), $twoOrderId);
            }

            return self::outcome($read['status'] === 404 ? self::MISSING : self::WAITING, Message::of('note_check_unread', $twoOrderId, $read['reason']), $twoOrderId);
        }
        if ($state === 'CANCELLED') {
            return self::outcome(self::CANCELLED, Message::of($buyer ? 'note_confirm_cancelled' : 'note_check_cancelled', $twoOrderId), $twoOrderId, $state);
        }
        $placed = $rows === null ? [] : ['rows_hash' => OrderLifecycle::rowsHash($rows)];
        if (!$buyer) {
            if ($state === 'UNVERIFIED') {
                return self::outcome(self::WAITING, Message::of('note_check_unverified', $twoOrderId), $twoOrderId, $state);
            }
            if ($state !== 'VERIFIED' && (!in_array($state, self::PLACED_STATES, true) || $state === 'REFUNDED')) {
                $this->logger->error('Two order ' . $twoOrderId . ' (order ' . $orderId . ') is ' . $state . ': left unchanged by the ' . $trigger . '.');

                return self::outcome(self::WAITING, Message::of('note_check_state', $twoOrderId, $state), $twoOrderId, $state);
            }
            $changed = $this->snapshotMismatch($record, $rebuild);
            if ($changed !== null) {
                $this->logger->error('Order ' . $orderId . ': ' . Message::of($changed[1], $twoOrderId, ...$changed[2])->english() . ' The ' . $trigger . ' sent nothing to Two.');

                return self::outcome(self::CHANGED, Message::of('note_check_unmatched', $twoOrderId), $twoOrderId, $state);
            }
        }
        if (in_array($state, self::PLACED_STATES, true)) {
            if ($placed !== []) {
                $this->records->put($orderId, $placed);
            }

            return self::outcome(self::CONFIRMED, $buyer ? Message::of('note_confirm_already', $twoOrderId, $state) : Message::of('note_check_already', $twoOrderId, $by), $twoOrderId, $state);
        }
        if ($state !== 'VERIFIED') {
            return self::outcome(self::REFUSED, Message::of('note_confirm_state', $twoOrderId, $state), $twoOrderId, $state);
        }

        $path = '/v1/order/' . $twoOrderId . '/confirm';
        try {
            $body = $this->postprocessing->apply(Postprocessing::ORDER_CONFIRM, $trigger, $path, [], ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            $message = Message::of('note_confirm_not_built', $twoOrderId, $refused->note);

            return self::outcome($buyer ? self::REFUSED : self::WAITING, $message, $twoOrderId, $state);
        }
        $response = $this->client->request('POST', $path, $body, Client::TIMEOUT_SECONDS, true, IdempotencyKey::header(IdempotencyKey::forTwoOrder(IdempotencyKey::CONFIRM, $twoOrderId)));
        if (!$response->reachedTwo() || $response->status >= 400) {
            if ($buyer) {
                return self::outcome(self::REFUSED, Message::of('note_confirm_refused', $twoOrderId, $this->errors->noteReason($response)), $twoOrderId, $state);
            }
            $this->logger->error('Two order ' . $twoOrderId . ' (order ' . $orderId . ') confirm failed on the ' . $trigger . ', left for the next check: ' . $this->errors->reason($response));

            return self::outcome(self::WAITING, Message::of('note_check_confirm_failed', $twoOrderId, $this->errors->noteReason($response)), $twoOrderId, $state);
        }
        $this->records->put($orderId, self::stateFields($response->json() ?? []) + ['two_order_state' => 'CONFIRMED'] + $placed);

        return self::outcome(self::CONFIRMED, $buyer ? Message::of('note_confirmed', $twoOrderId) : Message::of('note_check_confirmed', $twoOrderId, $by), $twoOrderId, 'CONFIRMED');
    }

    /**
     * Two sent the buyer back to the cancel URL. Cancels the Two order and
     * reads it back whatever the cancel answered: the order counts as
     * cancelled once Two says so, including when it already was.
     *
     * @return array{outcome: string, reason: string, message: Message, two_order_id: string, state: string}
     */
    public function cancel(int $orderId, string $token): array
    {
        $record = $this->authorised($orderId, $token);
        if ($record === null) {
            return self::outcome(self::UNAUTHORISED, Message::of('note_cancel_token'), '');
        }
        $twoOrderId = $record['two_order_id'];
        if ($twoOrderId === '') {
            return self::outcome(self::CANCELLED, Message::of('note_cancel_no_order'), '');
        }
        $this->sendCancel($twoOrderId, 'buyer_cancel', $orderId);
        if ($this->readState($orderId, $twoOrderId) === 'CANCELLED') {
            return self::outcome(self::CANCELLED, Message::of('note_cancel_by_buyer', $twoOrderId), $twoOrderId);
        }

        return self::outcome(self::REFUSED, Message::of('note_cancel_unconfirmed', $twoOrderId), $twoOrderId);
    }

    /** Whether the token is the order's own, without asking Two anything. */
    public function owns(int $orderId, string $token): bool
    {
        return $this->authorised($orderId, $token) !== null;
    }

    /**
     * What the adapter does with a callback's outcome, given whether the shop
     * order is placed now (read after the flow ran, so a second hit on an
     * order the first hit placed never writes over it): place the order,
     * note the reason on the unplaced order, and where the buyer goes.
     *
     * @return array{place: bool, note: bool, goto: 'success'|'cart', notice: 'cancelled'|'changed'|'generic'|null}
     */
    public static function afterCallback(string $kind, string $outcome, bool $placed): array
    {
        if ($outcome === self::UNAUTHORISED) {
            return ['place' => false, 'note' => false, 'goto' => 'cart', 'notice' => 'generic'];
        }
        if ($kind === self::CALLBACK_CONFIRM && $placed) {
            return ['place' => false, 'note' => false, 'goto' => 'success', 'notice' => null];
        }
        if ($placed) {
            return ['place' => false, 'note' => false, 'goto' => 'cart', 'notice' => 'generic'];
        }
        if ($kind === self::CALLBACK_CONFIRM && $outcome === self::CONFIRMED) {
            return ['place' => true, 'note' => false, 'goto' => 'success', 'notice' => null];
        }

        $notice = match ($outcome) {
            self::CANCELLED => 'cancelled',
            self::CHANGED => 'changed',
            default => 'generic',
        };

        return ['place' => false, 'note' => true, 'goto' => 'cart', 'notice' => $notice];
    }

    /**
     * Where the buyer goes when another hit on the same order still holds it
     * after the wait. That hit may yet place the order and mail the buyer, so
     * the buyer never sees the failure sentence here: the success page when
     * the order is placed by now, otherwise a neutral notice.
     *
     * @return array{goto: 'success'|'cart', notice: 'pending'|null}
     */
    public static function afterLockTimeout(bool $placed): array
    {
        return $placed ? ['goto' => 'success', 'notice' => null] : ['goto' => 'cart', 'notice' => 'pending'];
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The create payload's identity: everything Two is asked to buy, without
     * the callback URLs (which carry the token).
     *
     * @param array<string, mixed> $payload
     */
    public static function payloadHash(array $payload): string
    {
        unset($payload['merchant_urls']);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The payload as it is sent: built, shop-matched, through the hook.
     *
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $totals
     * @param array<string, mixed>       $context
     * @return array<string, mixed>
     */
    private function payload(array $order, array $lines, array $totals, array $context, string $trigger, string $requestType = Postprocessing::ORDER_CREATE): array
    {
        $payload = $this->builder->build($order, $lines, $totals);
        $failures = OrderBuilder::shopMatchFailures($payload, $order);
        $intent = $requestType === Postprocessing::ORDER_INTENT;
        $payload = $intent ? self::intentPayload($payload, $context) : $payload;

        return $this->postprocessing->apply($requestType, $trigger, $intent ? '/v1/order_intent' : '/v1/order', $payload, $context + ['order_id' => (int) $order['order_id']], $failures);
    }

    /**
     * The create payload cut to what the order intent asks about.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function intentPayload(array $payload, array $context): array
    {
        $payload = array_intersect_key($payload, array_flip(self::INTENT_KEYS));
        $shortName = $context['merchant_short_name'] ?? null;

        return is_string($shortName) && $shortName !== '' ? $payload + ['merchant_short_name' => $shortName] : $payload;
    }

    /**
     * Runs one placement of an order while holding its lock. A second
     * placement of the same order never runs alongside the first: it is
     * refused at once with IN_PROGRESS, and the buyer is told the order is
     * still being placed. Once the first has finished, a new placement runs
     * as a re-create (the earlier Two order is cancelled first).
     *
     * @template T of array<string, mixed>
     * @param \Closure(): T $place
     * @return T|array{ok: false, buyer: list<string>, reason: string}
     */
    public static function exclusively(OrderLock $lock, int $orderId, \Closure $place): array
    {
        if (!$lock->acquire($orderId)) {
            return ['ok' => false, 'buyer' => [self::IN_PROGRESS], 'reason' => 'Another placement of this order is still running.'];
        }
        try {
            return $place();
        } finally {
            $lock->release($orderId);
        }
    }

    /**
     * The rebuild of a placed order from its record, for the confirm and the
     * edit sync alike: the company, the optional buyer fields and the payment
     * terms as stored with the order, so a rebuild hashes to what Two was sent. $orderInput
     * maps the shop order with that company and those fields, without
     * callback URLs (the payload's identity leaves them out).
     *
     * @param \Closure(array{company_name: string, organization_number: string, country_code: string}, array<string, string>, array<string, mixed>): (array<string, mixed>|null) $orderInput
     * @return \Closure(array<string, string>): (array<string, mixed>|null)
     */
    public static function rebuildFrom(\Closure $orderInput): \Closure
    {
        return static fn (array $record): ?array => $orderInput([
            'company_name' => $record['company_name'] ?? '',
            'organization_number' => $record['organization_number'] ?? '',
            'country_code' => $record['country_code'] ?? '',
        ], BuyerFields::decode($record['buyer_fields'] ?? ''), PaymentTerms::decode($record['payment_terms'] ?? '') ?? []);
    }

    /**
     * Whether placement goes on after the intent (section 6.3): only an
     * approval lets the order be created. Anything else (a decline, no
     * answer, an error, an answer without a decision, a refused build)
     * refuses it, with the buyer's sentences; null to go on.
     *
     * @param array{outcome: string, reason: string, buyer: list<string>} $intent
     * @return list<string>|null never empty
     */
    public static function afterIntent(array $intent): ?array
    {
        if ($intent['outcome'] === self::APPROVED) {
            return null;
        }
        if ($intent['outcome'] === self::DECLINED) {
            return [self::INTENT_DECLINED];
        }

        return $intent['buyer'] === [] ? [self::INTENT_UNPROCESSABLE] : $intent['buyer'];
    }

    /**
     * Asks Two whether it would take this order (Build Guide section 6.3),
     * built server-side from the shop's stored order by the same builder as
     * the create, through the hook as `order_intent`. An outage or an error
     * is UNDECIDED, with the buyer's sentence for its cause; afterIntent says
     * what placement does with each outcome.
     *
     * $sessionHash names the buyer's checkout session (a hex hash, never the
     * session id itself); without one nothing is sent, since the key must
     * never be shared between buyers.
     *
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $totals
     * @param array<string, mixed>       $context
     * @return array{outcome: string, reason: string, message: Message, buyer: list<string>}
     */
    public function intent(array $order, array $lines, array $totals, string $sessionHash, array $context = []): array
    {
        $orderId = (int) $order['order_id'];
        $company = is_array($order['company'] ?? null) ? $order['company'] : [];
        foreach (['company_name', 'organization_number', 'country_code'] as $field) {
            if (trim((string) ($company[$field] ?? '')) === '') {
                return self::intentOutcome(self::REFUSED, Message::of('note_no_company'), [ErrorMapper::COMPANY_REQUIRED]);
            }
        }
        if (preg_match('~^[0-9a-f]{16,}\z~', $sessionHash) !== 1) {
            return self::intentOutcome(self::UNDECIDED, Message::of('note_intent_no_session'), [self::INTENT_UNPROCESSABLE]);
        }
        try {
            $payload = $this->payload($order, $lines, $totals, $context, 'checkout', Postprocessing::ORDER_INTENT);
        } catch (OrderRefused $refused) {
            $this->logger->error('Order ' . $orderId . ': order intent not sent: ' . $refused->note->english());

            return self::intentOutcome(self::REFUSED, Message::of('note_intent_not_built', $refused->note), [ErrorMapper::GENERIC]);
        }

        $response = $this->client->request('POST', '/v1/order_intent', $payload, Client::TIMEOUT_SECONDS, true, IdempotencyKey::header(IdempotencyKey::orderIntent($sessionHash, self::payloadHash($payload))));
        $body = $response->json();
        if ($response->reachedTwo() && $response->status < 300 && is_bool($body['approved'] ?? null)) {
            return $body['approved']
                ? self::intentOutcome(self::APPROVED, Message::of('note_intent_approved'), [])
                : self::intentOutcome(self::DECLINED, Message::of('note_intent_declined'), []);
        }
        $message = $response->reachedTwo() && $response->status < 300 ? Message::of('note_intent_undecided', $response->status) : Message::of('note_intent_unanswered', $this->errors->noteReason($response));
        $this->logger->error('Order ' . $orderId . ': ' . $message->english());
        $buyer = match (true) {
            !$response->reachedTwo() => self::INTENT_CONNECTION,
            $response->status >= 500 => self::INTENT_UNAVAILABLE,
            default => self::INTENT_UNPROCESSABLE,
        };

        return self::intentOutcome(self::UNDECIDED, $message, [$buyer]);
    }

    /**
     * The hash of the create payload the shop's stored order rebuilds now,
     * through the hook as a snapshot (nothing is sent), compared with the
     * stored payload_hash.
     *
     * @param array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>} $input
     *
     * @throws OrderRefused when the order no longer builds
     */
    public function snapshotHash(array $input): string
    {
        return self::payloadHash($this->payload($input['order'], $input['lines'], $input['totals'], $input['context'] ?? [], 'snapshot_hash'));
    }

    /**
     * Why the shop's stored order no longer rebuilds the payload Two holds,
     * as the outcome (CHANGED when it rebuilds to a different payload), the
     * message key and its parameters after the Two order id, or null when it
     * still does.
     *
     * @param array<string, string> $record
     * @param \Closure(array<string, string>): (array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>}|null) $rebuild
     * @return array{0: string, 1: string, 2: list<string|Message>}|null
     */
    public function snapshotMismatch(array $record, \Closure $rebuild): ?array
    {
        if (($record['payload_hash'] ?? '') === '') {
            return [self::REFUSED, 'note_confirm_no_hash', []];
        }
        $input = $rebuild($record);
        if ($input === null) {
            return [self::REFUSED, 'note_confirm_unreadable', []];
        }
        try {
            $payload = $this->payload($input['order'], $input['lines'], $input['totals'], $input['context'] ?? [], 'snapshot_hash');
        } catch (OrderRefused $refused) {
            return [self::REFUSED, 'note_confirm_rebuild_failed', [$refused->note]];
        }

        return hash_equals($record['payload_hash'], self::payloadHash($payload)) ? null : [self::CHANGED, 'note_confirm_changed', []];
    }

    /**
     * @return array<string, string>|null the order's record when the token is its own
     */
    private function authorised(int $orderId, string $token): ?array
    {
        $record = $this->records->get($orderId);
        if ($record === null || $token === '' || ($record['token_hash'] ?? '') === '') {
            return null;
        }
        if (!hash_equals($record['token_hash'], self::tokenHash($token))) {
            return null;
        }
        $twoOrderId = $record['two_order_id'] ?? '';

        return $twoOrderId === '' || self::validTwoOrderId($twoOrderId) ? $record + ['two_order_id' => ''] : null;
    }

    /** Two's state for the order, stored as read; null when Two did not answer with one. */
    public function readState(int $orderId, string $twoOrderId): ?string
    {
        return $this->read($orderId, $twoOrderId)['state'];
    }

    /**
     * readState with Two's HTTP status (0 when Two was not reached) and why
     * no state came back.
     *
     * @return array{state: string|null, status: int, reason: Message|string}
     */
    private function read(int $orderId, string $twoOrderId): array
    {
        $response = $this->client->request('GET', '/v1/order/' . $twoOrderId);
        $body = $response->json();
        if (!$response->reachedTwo() || $response->status >= 400 || !is_string($body['state'] ?? null)) {
            $this->logger->error('Two order ' . $twoOrderId . ' state could not be read: ' . $this->errors->reason($response));

            return ['state' => null, 'status' => $response->reachedTwo() ? $response->status : 0, 'reason' => $this->errors->noteReason($response)];
        }
        $this->records->put($orderId, self::stateFields($body));

        return ['state' => strtoupper($body['state']), 'status' => $response->status, 'reason' => ''];
    }

    /**
     * Sends the cancel of a whole Two order, through the hook, with an
     * idempotency key.
     *
     * @return Message|string|null why it was not sent (a Message) or Two refused it (Two's text), null when Two accepted it
     */
    public function sendCancel(string $twoOrderId, string $trigger, int $orderId): Message|string|null
    {
        return $this->cancelOrder($twoOrderId, $trigger, $orderId)['failure'];
    }

    /**
     * sendCancel with Two's status too: 202 means Two already held the order
     * as cancelled, 200 that this request cancelled it, 0 that nothing was sent
     * or Two was not reached.
     *
     * @return array{failure: Message|string|null, status: int}
     */
    public function cancelOrder(string $twoOrderId, string $trigger, int $orderId): array
    {
        $path = '/v1/order/' . $twoOrderId . '/cancel';
        try {
            $body = $this->postprocessing->apply(Postprocessing::CANCEL, $trigger, $path, [], ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            $this->logger->error('Two order ' . $twoOrderId . ' cancel not sent: ' . $refused->note->english());

            return ['failure' => Message::of('refusal_cancel_not_sent', $refused->note), 'status' => 0];
        }
        $response = $this->client->request('POST', $path, $body, Client::TIMEOUT_SECONDS, true, IdempotencyKey::header(IdempotencyKey::forTwoOrder(IdempotencyKey::CANCEL, $twoOrderId)));
        if (!$response->reachedTwo() || $response->status >= 400) {
            $this->logger->error('Two order ' . $twoOrderId . ' cancel refused: ' . $this->errors->reason($response));

            return ['failure' => $this->errors->noteReason($response), 'status' => $response->status];
        }

        return ['failure' => null, 'status' => $response->status];
    }

    /**
     * @param list<string> $buyer
     * @return array{ok: false, buyer: non-empty-list<string>, reason: string, message: Message}
     */
    private function refused(int $orderId, Message $message, array $buyer = []): array
    {
        $this->logger->error('Order ' . $orderId . ': ' . $message->english());

        return self::failed($buyer === [] ? [ErrorMapper::GENERIC] : $buyer, $message);
    }

    /**
     * @param non-empty-list<string> $buyer
     * @return array{ok: false, buyer: non-empty-list<string>, reason: string, message: Message}
     */
    private static function failed(array $buyer, Message $message): array
    {
        return ['ok' => false, 'buyer' => $buyer, 'reason' => $message->english(), 'message' => $message];
    }

    /**
     * @param list<string> $buyer
     * @return array{outcome: string, reason: string, message: Message, buyer: list<string>}
     */
    private static function intentOutcome(string $outcome, Message $message, array $buyer): array
    {
        return ['outcome' => $outcome, 'reason' => $message->english(), 'message' => $message, 'buyer' => $buyer];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    public static function stateFields(array $body): array
    {
        $fields = [];
        foreach (['state' => 'two_order_state', 'status' => 'two_order_status'] as $key => $field) {
            if (is_string($body[$key] ?? null)) {
                $fields[$field] = strtoupper($body[$key]);
            }
        }

        return $fields;
    }

    /** Two's order ids are UUIDs; anything else is never put in a path. */
    public static function validTwoOrderId(string $id): bool
    {
        return preg_match('~^[A-Za-z0-9-]{1,64}\z~', $id) === 1;
    }

    /**
     * @return array{outcome: string, reason: string, message: Message, two_order_id: string, state: string}
     */
    private static function outcome(string $outcome, Message $message, string $twoOrderId, string $state = ''): array
    {
        return ['outcome' => $outcome, 'reason' => $message->english(), 'message' => $message, 'two_order_id' => $twoOrderId, 'state' => $state];
    }
}
