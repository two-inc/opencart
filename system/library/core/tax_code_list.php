<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The tax codes Two gives for the merchant's country, and whether Two
 * requires one on a 0% line there (`GET /v1/tax_codes/<country>`, TWO-27058).
 * Admin only: nothing at order time reads it.
 *
 * Held a day per country. A failed fetch keeps the last good list and is
 * retried at most every RETRY seconds, the floor stamped before the call so
 * an outage costs one attempt per floor. A country Two does not support
 * (400 COUNTRY_NOT_SUPPORTED) is an empty list, never a failure. While
 * the last good list is served after a failure, the list carries when it
 * was read and the last failure, for the section to name.
 */
final class TaxCodeList
{
    public const CACHE_KEY = 'tax_codes';
    public const TRIED_KEY = 'tax_codes_tried';
    /** The last failed fetch: its country, time and HTTP status; cleared by the next good one. */
    public const FAILED_KEY = 'tax_codes_failed';
    public const TTL = 86_400;
    public const RETRY = 300;
    public const TIMEOUT_SECONDS = 10;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly Client $client,
        private readonly SettingsStore $store,
        private readonly Logger $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The list for a country, or null when none was ever read for it, with
     * when it was read and the last fetch for the country that failed since.
     *
     * @return array{codes: list<array{code: string, display_name: string, rate: float}>, mandatory: bool, fetched_at: int, failure: array{at: int, status: int}|null}|null
     */
    public function forCountry(string $country): ?array
    {
        $country = strtoupper($country);
        if (preg_match('/^[A-Z]{2}\z/', $country) !== 1) {
            return null;
        }
        $now = ($this->clock)();
        $cached = json_decode((string) $this->store->get(self::CACHE_KEY), true);
        $fetchedAt = (int) ($cached['fetched_at'] ?? 0);
        $held = is_array($cached) && ($cached['country'] ?? null) === $country && is_array($cached['codes'] ?? null)
            ? ['codes' => $cached['codes'], 'mandatory' => (bool) ($cached['mandatory'] ?? false), 'fetched_at' => $fetchedAt, 'failure' => $this->failure($country, $fetchedAt)]
            : null;
        if ($held !== null && $fetchedAt + self::TTL > $now) {
            return $held;
        }
        // The floor holds only for the country it was stamped for, so a merchant whose country changed is asked at once.
        $tried = explode(':', (string) $this->store->get(self::TRIED_KEY), 2);
        if (count($tried) === 2 && $tried[0] === $country && (int) $tried[1] + self::RETRY > $now) {
            return $held;
        }
        $this->store->setMany([self::TRIED_KEY => $country . ':' . $now]);

        $response = $this->client->request('GET', '/v1/tax_codes/' . rawurlencode($country), null, self::TIMEOUT_SECONDS);
        $body = $response->json() ?? [];
        $list = null;
        if ($response->status === 200 && is_array($body['data'] ?? null)) {
            $codes = TaxCodes::normalise($body['data']);
            $list = $codes === null ? null : ['codes' => $codes, 'mandatory' => TaxCodes::isMandatoryAtZero($body['metadata'] ?? null)];
        } elseif ($response->status === 400 && ($body['error_code'] ?? null) === 'COUNTRY_NOT_SUPPORTED') {
            $list = ['codes' => [], 'mandatory' => false];
        }
        if ($list !== null) {
            $this->store->setMany([self::CACHE_KEY => (string) json_encode(['country' => $country, 'fetched_at' => $now] + $list, JSON_UNESCAPED_UNICODE), self::FAILED_KEY => '']);

            return $list + ['fetched_at' => $now, 'failure' => null];
        }
        $this->store->setMany([self::FAILED_KEY => (string) json_encode(['country' => $country, 'at' => $now, 'status' => $response->status])]);
        $this->logger->error('Two tax codes: GET /v1/tax_codes/' . $country . ' failed (HTTP ' . $response->status . '). ' . ($held === null ? 'The tax code section shows a notice.' : 'The last list read stays in use.'));

        return $held === null ? null : ['failure' => ['at' => $now, 'status' => $response->status]] + $held;
    }

    /**
     * The last failed fetch for the country after the list was read.
     *
     * @return array{at: int, status: int}|null
     */
    private function failure(string $country, int $fetchedAt): ?array
    {
        $failed = json_decode((string) $this->store->get(self::FAILED_KEY), true);
        if (!is_array($failed) || ($failed['country'] ?? null) !== $country || !is_int($failed['at'] ?? null) || $failed['at'] < $fetchedAt) {
            return null;
        }

        return ['at' => $failed['at'], 'status' => (int) ($failed['status'] ?? 0)];
    }
}
