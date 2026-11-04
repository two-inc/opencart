<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The `Idempotency-Key` every write request to Two carries (POST, PUT,
 * PATCH, DELETE; never a GET), whether or not the endpoint reads it today.
 * Two deduplicates on it where it does (refunds, partial fulfilments), and
 * the rest are ready when it starts to.
 *
 * A key names one logical operation: the same on every retry or re-trigger
 * of it (a repeated status event, an admin re-save), different for any
 * other. It is built from durable ids only, never from time, randomness or
 * an in-memory counter, as `<operation>_<ids>`, lowercase ASCII, at most
 * 100 characters. Client refuses a write without one, and logs the key
 * with the request.
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';
    public const MAX_LENGTH = 100;
    public const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public const CONFIRM = 'confirm';
    public const FULFIL = 'fulfil';
    public const CANCEL = 'cancel';
    public const EDIT = 'edit';
    public const REFUND = 'refund';

    private const SHAPE = '~^[a-z0-9_-]{1,100}\z~';

    /**
     * Order create: one key per checkout attempt. Each attempt gets a fresh
     * callback token, so the key is the shop order id plus the start of the
     * token's stored hash (never the token itself): a new attempt on the same
     * cart, whatever its company, gets a new key.
     */
    public static function orderCreate(int $orderId, string $tokenHash): string
    {
        return self::checked('create_' . $orderId . '_' . substr(strtolower($tokenHash), 0, 16));
    }

    /** A whole-order write on one Two order: confirm, fulfil or cancel. */
    public static function forTwoOrder(string $operation, string $twoOrderId): string
    {
        if (!in_array($operation, [self::CONFIRM, self::FULFIL, self::CANCEL], true)) {
            throw new \InvalidArgumentException('Unknown whole-order operation "' . $operation . '".');
        }

        return self::checked($operation . '_' . strtolower($twoOrderId));
    }

    /**
     * The order intent: one buyer's checkout session and the payload asked
     * about. A retry of the same question repeats the key; a changed cart or
     * company asks a new one. Never shared between buyers: with no session
     * there is no key, and the caller sends nothing.
     */
    public static function orderIntent(string $sessionHash, string $payloadHash): string
    {
        return self::checked('intent_' . self::part($sessionHash) . '_' . self::part($payloadHash));
    }

    /**
     * The fee quote: a pure calculation with no side effect at Two, so the
     * same payload has the same answer for any buyer and the key is the
     * payload's hash alone.
     */
    public static function feeQuote(string $payloadHash): string
    {
        return self::checked('fee_' . self::part($payloadHash));
    }

    /**
     * The merchant fee rates read for the Payment terms tab: a pure lookup
     * with no side effect at Two, keyed by the payload's hash alone.
     */
    public static function merchantRates(string $payloadHash): string
    {
        return self::checked('merchant_rates_' . self::part($payloadHash));
    }

    /**
     * One mint of a sole-trader signup token ('delegation' or 'autofill').
     * $keyBase names the buyer's enrolment attempt (soleTraderAttempt), so
     * each click mints afresh and a retry of one click repeats its key.
     */
    public static function soleTraderMint(string $token, string $keyBase): string
    {
        if (!in_array($token, ['delegation', 'autofill'], true)) {
            throw new \InvalidArgumentException('Unknown sole-trader token "' . $token . '".');
        }

        return self::checked('soletrader_' . $token . '_' . $keyBase);
    }

    /** The base of one buyer's enrolment attempt: the session's hash and the attempt's number in it. */
    public static function soleTraderAttempt(string $sessionHash, int $attempt): string
    {
        return self::part($sessionHash) . '_' . max(1, $attempt);
    }

    /**
     * Order edit (PUT on the order): chained on the last accepted edit's key,
     * or the create key for the first edit, plus this payload's hash. Every
     * edit in a sequence gets its own key (A to B to A gives three), so Two
     * can never answer an edit with an earlier one, and a retry of the same
     * edit repeats its key because the last accepted key has not moved.
     */
    public static function edit(string $twoOrderId, string $previousKey, string $payloadHash): string
    {
        if ($previousKey === '' || $payloadHash === '') {
            throw new \InvalidArgumentException('An edit key needs the previous key and the payload hash.');
        }

        return self::checked(self::EDIT . '_' . strtolower($twoOrderId) . '_' . substr(hash('sha256', $previousKey . '|' . $payloadHash), 0, 16));
    }

    /** A refund with its own record: the plugin's refund row, created before the call. */
    public static function refund(string $twoOrderId, int $refundRowId): string
    {
        if ($refundRowId <= 0) {
            throw new \InvalidArgumentException('A refund key needs a stored refund row id.');
        }

        return self::checked(self::REFUND . '_' . strtolower($twoOrderId) . '_' . $refundRowId);
    }

    /**
     * A refund with no record of its own (the rest of the order, from a
     * status change): the refunds Two already holds plus the amount. The
     * same rest after the same refunds gets the same key, so a repeated
     * status event cannot refund it twice.
     *
     * @param list<string> $priorRefundIds Two's ids of the refunds already made
     */
    public static function refundRemainder(string $twoOrderId, array $priorRefundIds, string $amount): string
    {
        sort($priorRefundIds);

        return self::checked(self::REFUND . '_remainder_' . strtolower($twoOrderId) . '_' . substr(hash('sha256', implode(',', $priorRefundIds) . '|' . $amount), 0, 16));
    }

    /**
     * The request header carrying a key.
     *
     * @return array<string, string>
     */
    public static function header(string $key): array
    {
        return [self::HEADER => self::checked($key)];
    }

    /**
     * The key among a request's headers, matched case-insensitively; null when there is none.
     *
     * @param array<string, string> $headers
     */
    public static function in(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, self::HEADER) === 0 && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** The first 16 characters of a hex hash, which must be one. */
    private static function part(string $hash): string
    {
        $hash = strtolower($hash);
        if (preg_match('~^[0-9a-f]{16,}\z~', $hash) !== 1) {
            throw new \InvalidArgumentException('An idempotency key part is a hex hash of at least 16 characters.');
        }

        return substr($hash, 0, 16);
    }

    private static function checked(string $key): string
    {
        if (preg_match(self::SHAPE, $key) !== 1) {
            throw new \InvalidArgumentException('An idempotency key is lowercase ASCII letters, digits, _ and -, at most ' . self::MAX_LENGTH . ' characters.');
        }

        return $key;
    }
}
