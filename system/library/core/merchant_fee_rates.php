<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The fee Two charges the merchant for each payment term (not the buyer's
 * surcharge), shown beside each term on the Payment terms tab, from
 * POST /pricing/v1/merchant/rates. The reference plugin's contract:
 *
 * - Asked from the admin page after it renders, never on the render path,
 *   with a tight timeout.
 * - A good answer is stored as the last-known-good set for its identity
 *   (environment, key, buyer country, terms) and stamped. A failure serves
 *   that set marked stale, and answers a failure only when nothing was ever
 *   stored for the identity.
 * - After a failure no call is made for COOLDOWN seconds, so an outage costs
 *   one call a minute rather than one per page view. The failure is kept
 *   with the cooldown, so a view meanwhile still says why.
 * - A refusal (a 4xx other than 408 and 429) carries the status and Two's
 *   own message, with or without a stale set beside it.
 */
final class MerchantFeeRates
{
    public const PATH = '/pricing/v1/merchant/rates';
    public const TIMEOUT_SECONDS = 10;
    public const COOLDOWN = 60;
    public const STORE_PREFIX = 'fee_rates_';
    public const COOLDOWN_PREFIX = 'fee_rates_cooldown_';

    /** Failure categories, which the admin script words. */
    public const UPSTREAM = 'upstream';
    public const REJECTED = 'rejected';
    public const NO_TERMS = 'no_terms';
    public const NOT_CONFIGURED = 'not_configured';

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
     * The fees for the terms asked, by day count.
     *
     * @param array<mixed> $days       the terms the page shows, raw
     * @param string       $buyerCountry the shop's own country: there is no buyer on a settings page
     * @return array{success: bool, currency?: string, fees?: array<string, array{percentage: float, fixed: float}>, stale?: bool, fetched_at?: int, error?: string, http_status?: int, message?: string}
     */
    public function fetch(array $days, string $buyerCountry): array
    {
        $terms = self::terms($days);
        if ($terms === []) {
            return ['success' => false, 'error' => self::NO_TERMS];
        }
        if ($this->settings->apiKey() === '') {
            return ['success' => false, 'error' => self::NOT_CONFIGURED];
        }
        $country = strtoupper(trim($buyerCountry));
        $slot = substr(hash('sha256', $this->settings->credentialFingerprint() . "\0" . $country . "\0" . implode(',', $terms)), 0, 32);
        $store = $this->settings->store();
        $now = ($this->clock)();
        $cooling = $this->cooling($slot, $now);
        $fresh = $cooling ?? $this->live($terms, $country);

        if ($fresh['success']) {
            $fresh['fetched_at'] = $now;
            $fresh['stale'] = false;
            $store->setMany([self::STORE_PREFIX . $slot => (string) json_encode($fresh), self::COOLDOWN_PREFIX . $slot => '']);

            return $fresh;
        }

        $detail = (string) ($fresh['detail'] ?? '');
        unset($fresh['detail']);
        $stored = $this->stored($slot);
        if ($cooling === null) {
            $store->setMany([self::COOLDOWN_PREFIX . $slot => (string) json_encode(['at' => $now] + $fresh)]);
            // Views while cooling take this path too, and would fill the log.
            $this->logger->error(($stored === null
                ? 'Two merchant fee rates fetch failed: no figures held for this shop.'
                : 'Two merchant fee rates fetch failed: serving the last figures retrieved.') . ($detail === '' ? '' : ' (' . $detail . ')'));
        }
        if ($stored === null) {
            return $fresh;
        }
        $stored['stale'] = true;
        if ($fresh['error'] === self::REJECTED) {
            $stored['error'] = self::REJECTED;
            $stored['http_status'] = $fresh['http_status'] ?? 0;
            $stored['message'] = $fresh['message'] ?? '';
        }

        return $stored;
    }

    /**
     * The terms asked, normalised: unique positive day counts, ascending.
     *
     * @param array<mixed> $days
     * @return list<int>
     */
    public static function terms(array $days): array
    {
        $terms = [];
        foreach ($days as $day) {
            if ((is_int($day) || (is_string($day) && ctype_digit(trim($day)))) && (int) $day > 0) {
                $terms[] = (int) $day;
            }
        }
        $terms = array_values(array_unique($terms));
        sort($terms);

        return $terms;
    }

    /**
     * One live call, normalised. A failure carries `detail`, the log's
     * account of it, which fetch() strips before answering.
     *
     * @param list<int> $terms
     * @return array{success: bool, currency?: string, fees?: array<string, array{percentage: float, fixed: float}>, error?: string, http_status?: int, message?: string, detail?: string}
     */
    private function live(array $terms, string $country): array
    {
        $payload = ['buyer_country_code' => $country, 'recourse_pricing' => false, 'net_terms' => $terms];
        $response = $this->client->request('POST', self::PATH, $payload, self::TIMEOUT_SECONDS, true, IdempotencyKey::header(IdempotencyKey::merchantRates(hash('sha256', (string) json_encode($payload)))));
        $status = $response->status;
        if ($status >= 400 && $status < 500 && !in_array($status, [408, 429], true)) {
            return ['success' => false, 'error' => self::REJECTED, 'http_status' => $status, 'message' => ErrorMapper::ownMessage($response), 'detail' => 'HTTP ' . $status];
        }
        if ($status !== 200) {
            return ['success' => false, 'error' => self::UPSTREAM, 'detail' => $response->reachedTwo() ? 'HTTP ' . $status : 'no answer: ' . $response->transportError];
        }
        $body = $response->json() ?? [];
        if (!is_array($body['rates'] ?? null)) {
            return ['success' => false, 'error' => self::UPSTREAM, 'detail' => 'HTTP 200 without a rates list'];
        }
        $fees = [];
        foreach ($body['rates'] as $rate) {
            if (!is_array($rate) || !is_numeric($rate['net_terms'] ?? null) || (int) $rate['net_terms'] <= 0) {
                continue;
            }
            $fees[(string) (int) $rate['net_terms']] = [
                'percentage' => is_numeric($rate['percentage_fee'] ?? null) ? (float) $rate['percentage_fee'] : 0.0,
                'fixed' => is_numeric($rate['fixed_fee'] ?? null) ? (float) $rate['fixed_fee'] : 0.0,
            ];
        }
        // Nothing priced, or amounts with no currency to read them in: storing either would displace real figures.
        $currency = is_string($body['currency'] ?? null) ? strtoupper(trim($body['currency'])) : '';
        if ($fees === [] || $currency === '') {
            return ['success' => false, 'error' => self::UPSTREAM, 'detail' => 'HTTP 200 with no priced term or no currency'];
        }

        return ['success' => true, 'currency' => $currency, 'fees' => $fees];
    }

    /**
     * The failure the slot is cooling down from, or null when it is not.
     *
     * @return array{success: bool, error: string, http_status?: int, message?: string}|null
     */
    private function cooling(string $slot, int $now): ?array
    {
        $decoded = json_decode((string) $this->settings->store()->get(self::COOLDOWN_PREFIX . $slot), true);
        if (!is_array($decoded) || !is_int($decoded['at'] ?? null) || $decoded['at'] <= 0 || $decoded['at'] + self::COOLDOWN <= $now) {
            return null;
        }
        if (($decoded['error'] ?? null) === self::REJECTED) {
            return ['success' => false, 'error' => self::REJECTED, 'http_status' => is_int($decoded['http_status'] ?? null) ? $decoded['http_status'] : 0, 'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : ''];
        }

        return ['success' => false, 'error' => self::UPSTREAM];
    }

    /**
     * The last good set for the slot, re-validated on read, or null.
     *
     * @return array{success: bool, currency: string, fees: array<string, array{percentage: float, fixed: float}>, fetched_at: int}|null
     */
    private function stored(string $slot): ?array
    {
        $decoded = json_decode((string) $this->settings->store()->get(self::STORE_PREFIX . $slot), true);
        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true || !is_array($decoded['fees'] ?? null) || $decoded['fees'] === []
            || !is_string($decoded['currency'] ?? null) || trim($decoded['currency']) === '') {
            return null;
        }
        $fees = [];
        foreach ($decoded['fees'] as $term => $fee) {
            if (!is_array($fee) || !is_numeric($fee['percentage'] ?? null) || !is_numeric($fee['fixed'] ?? null)) {
                return null;
            }
            // JSON drops a zero fraction, so a stored 0.0 decodes as an int.
            $fees[(string) $term] = ['percentage' => (float) $fee['percentage'], 'fixed' => (float) $fee['fixed']];
        }

        return ['success' => true, 'currency' => $decoded['currency'], 'fees' => $fees, 'fetched_at' => is_int($decoded['fetched_at'] ?? null) ? $decoded['fetched_at'] : 0];
    }
}
