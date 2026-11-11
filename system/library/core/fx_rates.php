<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Two's own FX spot rates (GET /refdata/v1/fx-rates), so the plugin converts
 * the minimum order and the surcharge's fixed fees and caps with the rates
 * Two applies, not the shop's. The table is EUR-pivot: rates[CCY] is one CCY
 * in EUR.
 *
 * Cached through the SettingsStore. Every conversion refreshes the table
 * once it is older than TTL; the attempt is stamped before the call, so
 * concurrent requests share one, and a failed one is retried after BACKOFF.
 * Meanwhile the last good table is served for as long as no refresh
 * succeeds, as the reference plugin does; a rate is missing only when no
 * table was ever fetched or it lacks the currency, and the charge paths
 * then fail closed. The admin health row shows the table's age. A fresh
 * table is merged over the cached one, so a currency that drops out of one
 * answer keeps its last rate.
 */
final class FxRates
{
    public const CACHE_KEY = 'fx_rates';
    public const STAMP_KEY = 'fx_rates_checked';
    public const PATH = '/refdata/v1/fx-rates';
    public const TTL = 21_600;
    public const BACKOFF = 300;
    public const TIMEOUT_SECONDS = 5;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly Client $client,
        private readonly SettingsProvider $settings,
        private readonly Logger $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * An amount in another currency, rounded to cents once, or null when Two's
     * table holds no rate for either currency (after one refresh, when due).
     */
    public function convert(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === '' || $to === '') {
            return null;
        }
        if ($from === $to) {
            return round($amount, 2);
        }
        $this->refreshIfDue();
        $table = $this->table();
        if ($table === null) {
            return null;
        }
        $rates = $table['rates'];

        return ($rates[$from] ?? 0) > 0 && ($rates[$to] ?? 0) > 0 ? round($amount * $rates[$from] / $rates[$to], 2) : null;
    }

    /**
     * The cached table, stale or not, or null when none was ever stored.
     *
     * @return array{base: string, as_of: string, fetched_at: int, rates: array<string, float>}|null
     */
    public function table(): ?array
    {
        $decoded = json_decode((string) $this->settings->store()->get(self::CACHE_KEY), true);
        $rates = self::rates(is_array($decoded) ? $decoded : null);
        if ($rates === []) {
            return null;
        }

        return ['base' => is_string($decoded['base'] ?? null) ? $decoded['base'] : 'EUR', 'as_of' => is_string($decoded['as_of'] ?? null) ? $decoded['as_of'] : '', 'fetched_at' => is_int($decoded['fetched_at'] ?? null) ? $decoded['fetched_at'] : 0, 'rates' => $rates];
    }

    /** Fetches the table when the last attempt is older than TTL (BACKOFF after a failure). Whether a fresh table was stored. */
    public function refreshIfDue(): bool
    {
        if ($this->settings->apiKey() === '') {
            return false;
        }
        $now = ($this->clock)();
        $checked = (int) $this->settings->store()->get(self::STAMP_KEY);
        if ($checked > 0 && $now - $checked < self::TTL) {
            return false;
        }
        $this->settings->store()->setMany([self::STAMP_KEY => (string) $now]);

        $response = $this->client->request('GET', self::PATH, null, self::TIMEOUT_SECONDS);
        $body = $response->json();
        $rates = self::rates($body);
        if ($response->status !== 200 || $rates === []) {
            // Retry after the backoff, not a whole TTL; the cached table stays in use.
            $this->settings->store()->setMany([self::STAMP_KEY => (string) ($now - self::TTL + self::BACKOFF)]);
            $this->logger->error('Two FX rates fetch failed (HTTP ' . $response->status . '). The cached rates stay in use.');

            return false;
        }
        $this->settings->store()->setMany([self::CACHE_KEY => (string) json_encode([
            'base' => is_string($body['base'] ?? null) ? $body['base'] : 'EUR',
            'as_of' => is_scalar($body['as_of'] ?? null) ? (string) $body['as_of'] : '',
            'fetched_at' => $now,
            'rates' => array_merge($this->table()['rates'] ?? [], $rates),
        ])]);

        return true;
    }

    /**
     * The positive numeric rates of a table, by upper-case code.
     *
     * @param array<string, mixed>|null $table
     * @return array<string, float>
     */
    private static function rates(?array $table): array
    {
        $rates = [];
        foreach (is_array($table['rates'] ?? null) ? $table['rates'] : [] as $code => $rate) {
            if (is_string($code) && preg_match('~^[A-Za-z]{3}\z~', $code) === 1 && is_numeric($rate) && (float) $rate > 0) {
                $rates[strtoupper($code)] = (float) $rate;
            }
        }

        return $rates;
    }
}
