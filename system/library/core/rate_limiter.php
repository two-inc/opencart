<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Limits on the checkout's relays to Two, as the reference plugin has them,
 * so a visitor with a cart cannot drive unlimited searches, token mints or
 * order intents on the merchant's key:
 *
 * - per caller: a fixed window per action (CALLER_LIMITS), counted in the
 *   shop's database. The caller is the socket address, never a header the
 *   client sends; behind the merchant's own proxies (the Trusted proxies
 *   setting) it is the nearest X-Forwarded-For hop that is not one of them.
 *   An IPv6 caller counts as its /64;
 * - per checkout session: the order intent, at most SESSION_INTENT_LIMIT in a
 *   sliding SESSION_INTENT_WINDOW, counted in the session.
 *
 * The rules are here; the adapter keeps the counts.
 */
final class RateLimiter
{
    /** Per caller: action => [most requests, window in seconds]. */
    public const CALLER_LIMITS = [
        'intent' => [30, 60],
        'saveCompany' => [60, 60],
        'soleTrader' => [60, 60],
        'soleTraderTokens' => [60, 60],
        'companyDetails' => [60, 60],
        'countries' => [60, 60],
        'search' => [300, 60],
        'term' => [60, 60],
        'fees' => [30, 60],
    ];
    /** Rows older than this are pruned: twice the longest window. */
    public const PRUNE_AFTER = 120;
    public const SESSION_INTENT_LIMIT = 5;
    public const SESSION_INTENT_WINDOW = 60;
    private const IPV6_BUCKET_BYTES = 8;

    /**
     * Who is calling: the socket address, unless it is one of the merchant's
     * trusted proxies, when it is the nearest X-Forwarded-For hop (read from
     * the right) that is not one; 'unknown' when there is no address at all.
     *
     * @param list<string> $trustedProxies IPs or CIDR ranges
     */
    public static function callerIdentity(string $peer, string $forwardedFor, array $trustedProxies): string
    {
        $peer = trim($peer);
        if ($peer === '') {
            return 'unknown';
        }
        if ($trustedProxies === [] || !self::matchesAny($peer, $trustedProxies)) {
            return self::bucket($peer);
        }
        foreach (array_reverse(explode(',', $forwardedFor)) as $hop) {
            $hop = trim($hop);
            if ($hop !== '' && !self::matchesAny($hop, $trustedProxies)) {
                return self::bucket($hop);
            }
        }

        return self::bucket($peer);
    }

    /**
     * The Trusted proxies setting as entries, split on commas and whitespace.
     *
     * @return list<string>
     */
    public static function parseProxies(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('~[\s,]+~', $raw) ?: []), static fn (string $entry): bool => $entry !== ''));
    }

    /** Whether an entry is an IP address or a CIDR range. */
    public static function isValidProxyEntry(string $entry): bool
    {
        return self::parseRange($entry) !== null;
    }

    /** The key a caller's count is kept under for an action: hashed, so no address is stored. */
    public static function key(string $action, string $identity): string
    {
        return hash('sha256', $action . "\0" . $identity);
    }

    /**
     * Counts one intent in the session's sliding window: the timestamps to
     * keep, and whether this one is within the limit (a refused one is not kept).
     *
     * @param list<int> $stamps
     * @return array{stamps: list<int>, allowed: bool}
     */
    public static function sessionIntent(array $stamps, int $now): array
    {
        $recent = array_values(array_filter($stamps, static fn (int $at): bool => $at > 0 && $now - $at < self::SESSION_INTENT_WINDOW));
        if (count($recent) >= self::SESSION_INTENT_LIMIT) {
            return ['stamps' => $recent, 'allowed' => false];
        }
        $recent[] = $now;

        return ['stamps' => $recent, 'allowed' => true];
    }

    /**
     * The session's intent stamps after a request that reloaded the session:
     * those stored now (other requests' too) and this request's, within the
     * window, each once.
     *
     * @param list<int> $stored
     * @param list<int> $ours
     * @return list<int>
     */
    public static function keepStamps(array $stored, array $ours, int $now): array
    {
        $all = array_values(array_unique(array_filter(array_merge($stored, $ours), static fn (int $at): bool => $at > 0 && $now - $at < self::SESSION_INTENT_WINDOW)));
        sort($all);

        return $all;
    }

    /** @param list<string> $rules */
    private static function matchesAny(string $address, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (self::matches($address, $rule)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $address, string $rule): bool
    {
        $parsed = self::parseRange($rule);
        $packed = @inet_pton($address);
        if ($parsed === null || $packed === false || strlen($packed) !== strlen($parsed[0])) {
            return false;
        }
        [$subnet, $bits] = $parsed;
        $whole = intdiv($bits, 8);
        if ($whole > 0 && strncmp($packed, $subnet, $whole) !== 0) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($subnet[$whole]) & $mask);
    }

    /** @return array{0: string, 1: int}|null the packed address and the prefix length */
    private static function parseRange(string $range): ?array
    {
        $bits = null;
        if (str_contains($range, '/')) {
            [$range, $bits] = explode('/', $range, 2);
            if (preg_match('~^\d+\z~', trim($bits)) !== 1) {
                return null;
            }
        }
        $packed = @inet_pton(trim($range));
        if ($packed === false) {
            return null;
        }
        $width = strlen($packed) * 8;
        $bits = $bits === null ? $width : (int) trim($bits);

        return $bits < 1 || $bits > $width ? null : [$packed, $bits];
    }

    /** An IPv6 address counts as its /64, so one caller cannot rotate through its own block. */
    private static function bucket(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return $address;
        }

        return inet_ntop(substr($packed, 0, self::IPV6_BUCKET_BYTES) . str_repeat("\0", 16 - self::IPV6_BUCKET_BYTES)) . '/64';
    }
}
