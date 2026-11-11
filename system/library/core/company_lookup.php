<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The checkout's company capture calls to Two (Build Guide section 1.1),
 * relayed by the shop so the API key never reaches the browser:
 *
 * - search: the company register for one country, one page of results;
 * - supportedCountries: the countries the search covers;
 * - soleTraderAvailable: whether the registry lets a buyer in a country
 *   enrol as a sole trader;
 * - soleTraderTokens: the two short-lived delegated-authority tokens the
 *   hosted sole-trader signup takes, minted with the merchant's key. Two
 *   answers each in the `two-delegated-authority-token` response header.
 *   Both or neither: the browser is never handed half a flow.
 *
 * Nothing here is a credit decision: the order intent and the order create
 * are. A failure is reported as one, never as an empty answer.
 */
final class CompanyLookup
{
    public const SEARCH_LIMIT = 50;
    public const MIN_TERM_LENGTH = 3;
    public const MAX_TERM_LENGTH = 100;
    public const TIMEOUT_SECONDS = 30;
    public const SOLE_TRADER = 'SOLE_TRADER';
    public const TOKEN_HEADER = 'two-delegated-authority-token';
    public const SIGNUP_PATH = '/soletrader/signup';
    /** A search result's lookup id, as Two issues it (URL-safe base64): nothing else, no dot or slash, is put in a path. */
    public const LOOKUP_ID = '~^[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z~';

    /** The two mints, each with the scopes the signup needs. */
    private const MINTS = [
        'delegation' => ['/registry/v1/delegation', ['create_proposal' => true, 'read_current_business' => true]],
        'autofill' => ['/autofill/v1/delegation', ['read_current_buyer' => true, 'write_current_buyer' => true]],
    ];

    public function __construct(
        private readonly Client $client,
        private readonly Environment $environment,
        private readonly SettingsProvider $settings,
    ) {
    }

    /**
     * Companies matching a term in one country's register, or null when the
     * search failed (as opposed to finding nothing).
     *
     * @return list<array{name: string, organization_number: string, highlight: string, lookup_id: string}>|null
     */
    public function search(string $term, string $country): ?array
    {
        $term = trim($term);
        $country = strtoupper(trim($country));
        if (mb_strlen($term) < self::MIN_TERM_LENGTH || mb_strlen($term) > self::MAX_TERM_LENGTH || preg_match('~^[A-Z]{2}\z~', $country) !== 1) {
            return [];
        }
        $query = http_build_query(['country' => $country, 'q' => $term, 'limit' => self::SEARCH_LIMIT, 'offset' => 0]);
        $response = $this->client->request('GET', '/companies/v2/company?' . $query, null, self::TIMEOUT_SECONDS);
        $body = $response->json();
        if ($response->status !== 200 || $body === null || !is_array($body['items'] ?? null)) {
            return null;
        }
        $companies = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || trim($item['name']) === '') {
                continue;
            }
            $companies[] = [
                'name' => trim($item['name']),
                'organization_number' => self::organizationNumber($item),
                'highlight' => is_string($item['highlight'] ?? null) ? $item['highlight'] : '',
                'lookup_id' => is_string($item['lookup_id'] ?? null) && preg_match(self::LOOKUP_ID, $item['lookup_id']) === 1 ? $item['lookup_id'] : '',
            ];
        }

        return $companies;
    }

    /**
     * One company's register entry, by a search result's lookup id: its
     * organisation number, which some registers leave out of the search
     * results, and its addresses, each with its phone number when the
     * register has one and its postcode fitted to its country's format
     * (PostcodeFormat). Null when the lookup failed.
     *
     * @return array{organization_number: string, addresses: list<array<string, string>>}|null
     */
    public function details(string $lookupId): ?array
    {
        if (preg_match(self::LOOKUP_ID, $lookupId) !== 1) {
            return null;
        }
        $response = $this->client->request('GET', '/companies/v2/company/' . rawurlencode($lookupId), null, self::TIMEOUT_SECONDS);
        $body = $response->json();
        if ($response->status !== 200 || $body === null) {
            return null;
        }
        $addresses = [];
        $country = is_string($body['country'] ?? null) ? $body['country'] : '';
        foreach (is_array($body['addresses'] ?? null) ? $body['addresses'] : [] as $address) {
            if (is_array($address)) {
                $address = array_map('strval', array_filter(array_intersect_key($address, array_flip(['type', 'street_address', 'street', 'building', 'apartment', 'postal_code', 'city', 'region', 'country', 'phone_number'])), 'is_scalar'));
                // TWO-27110: the postcode as its country writes it (the address's own country, else the company's).
                if (isset($address['postal_code'])) {
                    $address['postal_code'] = PostcodeFormat::fit($address['postal_code'], ($address['country'] ?? '') !== '' ? $address['country'] : $country);
                }
                $addresses[] = $address;
            }
        }

        return ['organization_number' => self::organizationNumber($body), 'addresses' => $addresses];
    }

    /**
     * The countries the search covers, as uppercase alpha-2 codes, or null
     * when Two did not say.
     *
     * @return list<string>|null
     */
    public function supportedCountries(): ?array
    {
        $response = $this->client->request('GET', '/companies/v2/supported-countries', null, self::TIMEOUT_SECONDS);
        $body = $response->json();
        $list = $body['supported_countries'] ?? null;
        if ($response->status !== 200 || !is_array($list)) {
            return null;
        }
        $codes = [];
        foreach ($list as $code) {
            $code = is_string($code) ? strtoupper(trim($code)) : '';
            if (preg_match('~^[A-Z]{2}\z~', $code) === 1) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * The covered countries in the envelope the shared company search reads:
     * `ok` false (and an empty list) when Two did not answer, so it fails open.
     *
     * @param list<string>|null $countries
     * @return array{ok: bool, status: int, body: array{supported_countries: list<string>}}
     */
    public static function countriesEnvelope(?array $countries): array
    {
        return ['ok' => $countries !== null, 'status' => $countries !== null ? 200 : 502, 'body' => ['supported_countries' => $countries ?? []]];
    }

    /** Whether a buyer in this country may enrol as a sole trader; null when the registry did not answer. */
    public function soleTraderAvailable(string $country): ?bool
    {
        $types = $this->supportedCompanyTypes($country);

        return $types === null ? null : in_array(self::SOLE_TRADER, $types, true);
    }

    /**
     * The registry's company types for a country, upper cased; [] for a
     * malformed country, null when the registry did not answer.
     *
     * @return list<string>|null
     */
    public function supportedCompanyTypes(string $country): ?array
    {
        $country = strtoupper(trim($country));
        if (preg_match('~^[A-Z]{2}\z~', $country) !== 1) {
            return [];
        }
        $response = $this->client->request('GET', '/registry/v1/supported-company-types/' . $country, null, self::TIMEOUT_SECONDS);
        $body = $response->json();
        $types = $body['supported_company_types'] ?? null;
        if ($response->status !== 200 || !is_array($types)) {
            return null;
        }

        return array_values(array_filter(array_map(static fn (mixed $type): string => is_string($type) ? strtoupper(trim($type)) : '', $types), static fn (string $type): bool => $type !== ''));
    }

    /**
     * Mints the signup's two tokens. $keyBase names this enrolment attempt
     * for one buyer (see IdempotencyKey::soleTraderMint); null when either
     * mint failed.
     *
     * @return array{delegation_token: string, autofill_token: string}|null
     */
    public function soleTraderTokens(string $keyBase): ?array
    {
        $tokens = [];
        foreach (self::MINTS as $name => [$path, $scopes]) {
            $response = $this->client->request('POST', $path, $scopes, self::TIMEOUT_SECONDS, true, IdempotencyKey::header(IdempotencyKey::soleTraderMint($name, $keyBase)));
            $token = trim($response->headers[self::TOKEN_HEADER] ?? '');
            if (!$response->reachedTwo() || $response->status >= 300 || $token === '') {
                return null;
            }
            $tokens[$name . '_token'] = $token;
        }

        return ['delegation_token' => $tokens['delegation_token'], 'autofill_token' => $tokens['autofill_token']];
    }

    /** The hosted sole-trader signup page for the environment in force. */
    public function signupUrl(): string
    {
        return $this->environment->checkoutBaseUrl($this->settings->environment()) . self::SIGNUP_PATH;
    }

    /** The hosted checkout's origin, which the sole-trader signup posts its answer from. */
    public function checkoutPageUrl(): string
    {
        return $this->environment->checkoutBaseUrl($this->settings->environment());
    }

    /** The API host the browser asks for the enrolled buyer (it holds the buyer's own Two session). */
    public function apiBaseUrl(): string
    {
        return $this->environment->apiBaseUrl($this->settings->environment());
    }

    /**
     * The company as the buyer reads it: the name, and the number in brackets
     * unless there is none or it is one of Two's own internal identifiers
     * (an enrolled sole trader's `TWO:` number), which the buyer never sees.
     */
    public static function displayLabel(string $name, string $number): string
    {
        return self::isInternalNumber($number) || trim($number) === '' ? $name : $name . ' (' . $number . ')';
    }

    /** A `TWO:` identifier Two issued itself: real for the order, never shown. */
    public static function isInternalNumber(string $number): bool
    {
        return stripos(trim($number), 'TWO:') === 0;
    }

    /** @param array<string, mixed> $item */
    private static function organizationNumber(array $item): string
    {
        $identifier = $item['national_identifier'] ?? null;
        foreach (is_array($identifier) ? ['id', 'value', 'organisationNumber', 'organizationNumber', 'registration_number', 'company_number'] : [] as $key) {
            if (is_scalar($identifier[$key] ?? null) && trim((string) $identifier[$key]) !== '') {
                return trim((string) $identifier[$key]);
            }
        }
        foreach (['registration_number', 'company_number', 'organization_number'] as $key) {
            if (is_scalar($item[$key] ?? null) && trim((string) $item[$key]) !== '') {
                return trim((string) $item[$key]);
            }
        }

        return '';
    }
}
