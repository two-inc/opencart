<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The merchant record from Two (Build Guide sections 16 and 18): terms grid,
 * buyer countries, surcharge settings. Cached through the SettingsStore with
 * no expiry, refreshed by the scheduled job and on settings save, so checkout
 * never waits on Two for it.
 *
 * - The cache is bound to the credential fingerprint: a record fetched for
 *   other credentials is not served, but it is not dropped either.
 * - A failed refresh, on any path, leaves the cached record in place. Only a
 *   successful fetch replaces it.
 * - A 200 that answers none of the record's questions (a captive portal, a
 *   proxy page) is a failed fetch, never a merchant with no restrictions.
 * - Reading the record stands in for a schedule that is not running: a cold
 *   cache is fetched on read (at most every RETRY_COLD seconds), and a record
 *   older than STALE_AFTER is refetched on read (at most every RETRY_STALE
 *   seconds, with a short timeout since a buyer may be waiting). Every
 *   attempt (read, save or schedule) is stamped before the call, so
 *   concurrent reads share one.
 *
 * Readers of individual fields (terms, buyer countries, minimum order) land
 * with the phases that use them (P6, P7); this class holds the record whole.
 */
final class RecordProvider
{
    public const CACHE_KEY = 'merchant_record';
    public const CRON_LAST_RUN_KEY = 'cron_last_run';
    public const ATTEMPT_KEY = 'merchant_record_attempt';
    public const MERCHANT_PATH = '/v1/merchant/';
    public const TIMEOUT_SECONDS = 10;

    /** A daily schedule that has missed a run by two hours. */
    public const STALE_AFTER = 93_600;
    public const RETRY_COLD = 300;
    public const RETRY_STALE = 3_600;
    public const STALE_TIMEOUT_SECONDS = 2;

    public const CRON_REFRESHED = 'refreshed';
    public const CRON_FAILED = 'failed';
    public const CRON_UNCONFIGURED = 'unconfigured';
    public const CRON_REJECTED = 'rejected';
    public const CRON_THROTTLED = 'throttled';

    /** The scheduled refresh URL's secret, and when a rejected call was last logged. */
    public const CRON_TOKEN_KEY = 'cron_token';
    public const CRON_REJECT_LOGGED_KEY = 'cron_reject_logged_at';

    /**
     * The public URL refreshes the record at most once per this many seconds.
     * Every accepted call still runs the pending check (TWO-26300), so the
     * URL can be scheduled every 15 minutes.
     */
    public const CRON_MIN_INTERVAL = 900;
    public const REJECT_LOG_INTERVAL = 3_600;

    /** Root fields of which at least one must be present for a body to be the record. */
    private const RECORD_FIELDS = ['id', 'available_terms', 'due_in_days', 'invoice_distributed_by_merchant', 'min_order_amount', 'supported_buyer_countries'];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly Client $client,
        private readonly SettingsProvider $settings,
        private readonly ApiKeyVerdict $verdict,
        private readonly Logger $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The cached record for the credentials in force, or null before the
     * first successful fetch for them. Refreshes first when one is due (see
     * refreshIfDue), and serves whatever the cache then holds.
     *
     * @return array<string, mixed>|null
     */
    public function record(): ?array
    {
        $this->refreshIfDue();
        $slot = $this->slot();

        return $slot !== null && $slot['fingerprint'] === $this->settings->credentialFingerprint() ? $slot['record'] : null;
    }

    /** When the record served by record() was fetched, or null when none is. */
    public function fetchedAt(): ?int
    {
        $slot = $this->slot();

        return $slot !== null && $slot['fingerprint'] === $this->settings->credentialFingerprint() ? $slot['fetched_at'] : null;
    }

    /**
     * Fetches the record from Two and replaces the cache. The key names its
     * merchant (the verification call, which also refreshes the heartbeat);
     * the record is then read by that id. Whether the cache was replaced.
     */
    public function refresh(int $timeoutSeconds = self::TIMEOUT_SECONDS): bool
    {
        if ($this->settings->apiKey() === '') {
            return false;
        }
        $fingerprint = $this->settings->credentialFingerprint();
        // Every attempt, by any path, holds back the read-time stand-in.
        $this->settings->store()->setMany([self::ATTEMPT_KEY => (string) json_encode(['fingerprint' => $fingerprint, 'at' => ($this->clock)()])]);

        $verified = $this->verdict->verify($timeoutSeconds);
        $merchantId = is_scalar($verified['merchant']['id'] ?? null) ? trim((string) $verified['merchant']['id']) : '';
        if ($merchantId === '') {
            $this->logger->error('Two merchant record not refreshed: the API key resolved no merchant (' . $verified['status'] . '). The cached record stays in use.');

            return false;
        }

        $response = $this->client->request('GET', self::MERCHANT_PATH . rawurlencode($merchantId), null, $timeoutSeconds);
        $record = $response->json();
        if ($response->status !== 200 || $record === null || !self::isRecord($record)) {
            $this->logger->error('Two merchant record refresh failed (HTTP ' . $response->status . '). The cached record stays in use.');

            return false;
        }
        // The merchant may have saved other credentials while the calls were out.
        if ($fingerprint !== $this->settings->credentialFingerprint()) {
            $this->logger->error('Two merchant record not refreshed: the credentials changed during the refresh.');

            return false;
        }

        $this->settings->store()->setMany([self::CACHE_KEY => (string) json_encode([
            'fingerprint' => $fingerprint,
            'fetched_at' => ($this->clock)(),
            'record' => $record,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        return true;
    }

    /**
     * The scheduled refresh's entry point (the shop's cron calls it): stamps
     * the run, so the admin can tell a schedule that stopped calling from one
     * that calls and fails, then refreshes. One of the CRON_* values.
     */
    public function runScheduledRefresh(): string
    {
        if ($this->settings->apiKey() === '') {
            return self::CRON_UNCONFIGURED;
        }
        $this->settings->store()->setMany([self::CRON_LAST_RUN_KEY => (string) ($this->clock)()]);

        return $this->refresh() ? self::CRON_REFRESHED : self::CRON_FAILED;
    }

    /**
     * The secret in the Diagnostics tab's scheduled refresh URL, minted on
     * first read so an existing install acquires one without an upgrade step.
     * Random, and unrelated to the API key. Admin only: an unauthenticated
     * request never mints one (see runTokenRefresh).
     */
    public function cronToken(): string
    {
        $token = trim((string) $this->settings->store()->get(self::CRON_TOKEN_KEY));
        if ($token === '') {
            $token = bin2hex(random_bytes(20));
            $this->settings->store()->setMany([self::CRON_TOKEN_KEY => $token]);
        }

        return $token;
    }

    /**
     * The scheduled refresh as called on its public URL by the merchant's
     * own crontab: the presented token must match the stored one, and the
     * record is refreshed at most once per CRON_MIN_INTERVAL (THROTTLED when
     * it was not due; the call is still accepted). A rejection is logged at
     * most once per REJECT_LOG_INTERVAL, since anyone can call the URL. One
     * of the CRON_* values.
     */
    public function runTokenRefresh(string $candidate): string
    {
        $candidate = trim($candidate);
        $stored = trim((string) $this->settings->store()->get(self::CRON_TOKEN_KEY));
        $now = ($this->clock)();
        if ($candidate === '' || $stored === '' || !hash_equals($stored, $candidate)) {
            $logged = (int) $this->settings->store()->get(self::CRON_REJECT_LOGGED_KEY);
            if ($logged <= 0 || $now - $logged >= self::REJECT_LOG_INTERVAL) {
                $this->settings->store()->setMany([self::CRON_REJECT_LOGGED_KEY => (string) $now]);
                $this->logger->error('Two scheduled refresh rejected: bad or missing token. Further rejections are not logged for ' . self::REJECT_LOG_INTERVAL . ' seconds.');
            }

            return self::CRON_REJECTED;
        }
        $ranAt = (int) $this->settings->store()->get(self::CRON_LAST_RUN_KEY);
        if ($ranAt > 0 && $now - $ranAt < self::CRON_MIN_INTERVAL && $this->settings->apiKey() !== '') {
            return self::CRON_THROTTLED;
        }

        return $this->runScheduledRefresh();
    }

    /**
     * The read-time stand-in for the schedule. Due when the credentials in
     * force have no record (cold) or one older than STALE_AFTER (stale), and
     * no attempt for them was made within that case's retry window. A failed
     * attempt never clears the cache. refresh() stamps the attempt before it
     * calls, so concurrent reads share one. Whether a fetch was attempted.
     */
    public function refreshIfDue(): bool
    {
        if ($this->settings->apiKey() === '') {
            return false;
        }
        $now = ($this->clock)();
        $fetchedAt = $this->fetchedAt();
        $stale = $fetchedAt !== null;
        if ($stale && $now - $fetchedAt < self::STALE_AFTER) {
            return false;
        }
        $fingerprint = $this->settings->credentialFingerprint();
        $attempt = json_decode((string) $this->settings->store()->get(self::ATTEMPT_KEY), true);
        $triedAt = is_array($attempt) && ($attempt['fingerprint'] ?? null) === $fingerprint && is_int($attempt['at'] ?? null) ? $attempt['at'] : null;
        if ($triedAt !== null && $now - $triedAt < ($stale ? self::RETRY_STALE : self::RETRY_COLD)) {
            return false;
        }

        if ($stale) {
            $ranAt = (int) $this->settings->store()->get(self::CRON_LAST_RUN_KEY);
            $this->logger->error('Two merchant record is over ' . self::STALE_AFTER . ' seconds old, refreshing on read. The scheduled refresh last ran: '
                . ($ranAt > 0 ? gmdate('Y-m-d H:i', $ranAt) . 'Z' : 'never') . '.');
        }
        $this->refresh($stale ? self::STALE_TIMEOUT_SECONDS : self::TIMEOUT_SECONDS);

        return true;
    }

    /** @param array<string, mixed> $body */
    private static function isRecord(array $body): bool
    {
        return array_intersect(self::RECORD_FIELDS, array_keys($body)) !== [];
    }

    /** @return array{fingerprint: string, fetched_at: int, record: array<string, mixed>}|null */
    private function slot(): ?array
    {
        $slot = json_decode((string) $this->settings->store()->get(self::CACHE_KEY), true);
        if (!is_array($slot) || !is_string($slot['fingerprint'] ?? null) || !is_array($slot['record'] ?? null)) {
            return null;
        }

        return [
            'fingerprint' => $slot['fingerprint'],
            'fetched_at' => is_int($slot['fetched_at'] ?? null) ? $slot['fetched_at'] : 0,
            'record' => $slot['record'],
        ];
    }
}
