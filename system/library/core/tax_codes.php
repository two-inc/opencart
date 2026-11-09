<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Tax codes on 0% lines (Build Guide section 22, TWO-27058). The plugin makes
 * no determination about how tax is levied: a 0% line takes the code the
 * merchant chose for the row the shop's own tax setup puts it on, and no code
 * when no row of theirs covers it. It never derives a code of its own.
 *
 * Per tax class the mapping holds three kinds of row, stored as one JSON
 * object (setting `tax_code_map`):
 *   "<tax_class_id>|exempt"             a VAT-registered buyer in another EU state (step 1)
 *   "<tax_class_id>|rate:<tax_rate_id>" a 0% tax rate the class's rules apply at the order's addresses (step 2)
 *   "<tax_class_id>|none"               none of the class's rates applies there, so no tax is charged (step 3)
 *
 * A line with no tax class of its own (a discount, store credit, a voucher,
 * or a product or charge with no tax class) takes the one code the order's
 * coded 0% lines share, and none when they disagree or there are none
 * (step 4). A matched row left on (none) gives no code: it never falls
 * through to a later step.
 */
final class TaxCodes
{
    /** The 27 member states plus Monaco. Northern Ireland (GB with a BT postcode) is added in inEuVatArea(). */
    public const EU_VAT_AREA = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'HU', 'MC',
    ];

    /** The setting the mapping is stored in. */
    public const MAP_KEY = 'tax_code_map';
    /** The setting naming the custom field that holds the buyer's VAT number. */
    public const VAT_FIELD_KEY = 'buyer_vat_field';

    public const VIEW_HIDDEN = 'hidden';
    public const VIEW_RECORD_ERROR = 'record_error';
    public const VIEW_LIST_ERROR = 'list_error';
    public const VIEW_ROWS = 'rows';
    public const VIEW_NOTICE = 'notice';

    /** The saved mapping cannot be read, so Two is withheld at checkout (Availability::TAX_CODE_MAP). */
    public const NOTICE_UNREADABLE = 'unreadable';
    /** Every saved code is listed at 0% for the country, which does not require them. */
    public const NOTICE_SAVED_VALID = 'saved_valid';
    /** A saved code is unlisted, or not at 0%, for the country. */
    public const NOTICE_SAVED_INVALID = 'saved_invalid';

    /**
     * Whether an address is in the EU VAT area: the 27 member states, Monaco,
     * and Northern Ireland, which shops hold as GB with a postcode starting BT.
     */
    public static function inEuVatArea(string $country, string $postcode): bool
    {
        $country = strtoupper(trim($country));
        if ($country === 'GB') {
            return str_starts_with(strtoupper(trim($postcode)), 'BT');
        }

        return in_array($country, self::EU_VAT_AREA, true);
    }

    /**
     * Step 1: the billing address and the tax address are both in the EU VAT
     * area, neither is in the merchant's country, and the VAT number is not
     * empty after trimming. An unknown merchant country makes nobody exempt.
     *
     * @param array{country: string, postcode: string} $billing
     * @param array{country: string, postcode: string} $taxAddress
     */
    public static function isExemptBuyer(array $billing, array $taxAddress, string $vatNumber, string $merchantCountry): bool
    {
        $merchant = strtoupper(trim($merchantCountry));
        if ($merchant === '' || trim($vatNumber) === '') {
            return false;
        }
        foreach ([$billing, $taxAddress] as $address) {
            if (strtoupper(trim($address['country'])) === $merchant || !self::inEuVatArea($address['country'], $address['postcode'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Steps 1 to 3 for a line taxed by a tax class.
     *
     * @param array<string, string>                              $map     the stored mapping
     * @param list<array{id: int, rate: float, type: string}>    $applied the rates the class applies at the order's addresses, as the shop's tax engine picks them
     */
    public static function resolve(array $map, int $class, bool $exempt, array $applied): ?string
    {
        if ($exempt) {
            return self::row($map, self::exemptKey($class));
        }
        if ($applied === []) {
            return self::row($map, self::noRuleKey($class));
        }
        $codes = [];
        foreach ($applied as $rate) {
            if ($rate['type'] !== 'P' || abs($rate['rate']) > 0.0000001) {
                // The line is 0% but the class applied a rate that is not: no code.
                return null;
            }
            $code = self::row($map, self::rateKey($class, $rate['id']));
            if ($code === null) {
                return null;
            }
            $codes[] = $code;
        }

        return self::shared($codes);
    }

    /**
     * Step 4: the one code the given codes share; null when they differ or there are none.
     *
     * @param list<string|null> $codes
     */
    public static function shared(array $codes): ?string
    {
        $distinct = array_values(array_unique(array_filter(array_map('strval', $codes), static fn (string $code): bool => $code !== '')));

        return count($distinct) === 1 ? $distinct[0] : null;
    }

    /**
     * Sets `tax_code` on every 0% line the merchant's rows code. Lines at any
     * other rate are left as they are. The mapping is read only once a 0%
     * line needs it, so an order with none never depends on it.
     *
     * A rebuilt order (the confirm check, an edit) passes its placement
     * record (`recorded`, see record()): a 0% line placement coded takes the
     * recorded code, whether its class or step 4 gave it, so a later change
     * to the mapping, the addresses or a tax rule never moves a placed order
     * (TWO-27110). A line placement did not code (one an edit added, or an
     * order placed before the record existed) is resolved as at placement.
     * Step 4 shares the recorded codes the mapping gave at placement (the
     * record's `mapped` keys) and the codes resolved now, never a code step 4
     * gave, even when a line's tax class has changed since.
     *
     * @param list<array<string, mixed>>                                                                             $items the payload's line items
     * @param list<array{class: int, rates: list<array{id: int, rate: float, type: string}>, line?: string|null}|null> $keys  one per item: its tax class, the rates applied and the line's record key, null for a line with none
     * @param array{map?: string|null, exempt?: bool, recorded?: array{codes: array<string, string>, mapped: list<string>}|null}|null $facts the stored mapping, whether the buyer is exempt (step 1) and the placement record (null on a create)
     * @return list<array<string, mixed>>
     *
     * @throws OrderRefused when a 0% line needs a mapping the plugin's form could not have written (the last guard: checkout withholds Two first)
     */
    public static function apply(array $items, array $keys, ?array $facts): array
    {
        if ($facts === null) {
            return $items;
        }
        $recorded = $facts['recorded'] ?? null;
        $map = null;
        $codes = [];
        $keyless = [];
        // Recorded codes step 4 gave at placement: kept as placed, and out of step 4's pool.
        $kept = [];
        foreach ($items as $i => $item) {
            if ((float) $item['tax_rate'] != 0.0) {
                continue;
            }
            $key = $keys[$i] ?? null;
            $classed = $key !== null && $key['class'] > 0;
            $line = $key['line'] ?? null;
            if ($line !== null && isset($recorded['codes'][$line])) {
                if (in_array($line, $recorded['mapped'], true)) {
                    $codes[$i] = $recorded['codes'][$line];
                } else {
                    $kept[$i] = $recorded['codes'][$line];
                }
                continue;
            }
            // A rebuild's keyless lines share recorded codes, so only a line to resolve needs the mapping then.
            if ($map === null && ($classed || $recorded === null)) {
                $map = self::parse($facts['map'] ?? null);
                if ($map === null) {
                    throw OrderRefused::because('tax_code_map_unreadable', Message::of('refusal_tax_code_map'));
                }
            }
            if (!$classed) {
                $keyless[] = $i;
                continue;
            }
            $codes[$i] = self::resolve($map ?? [], $key['class'], (bool) ($facts['exempt'] ?? false), $key['rates']);
        }
        $shared = self::shared(array_values($codes));
        foreach ($keyless as $i) {
            $codes[$i] = $shared;
        }
        foreach ($kept + $codes as $i => $code) {
            if ($code !== null) {
                $items[$i]['tax_code'] = $code;
            }
        }

        return $items;
    }

    /**
     * The placement record (TWO-27110): the code each 0% line with a record
     * key was sent with, by that key (`codes`), and which of those the
     * mapping gave, steps 1 to 3 for a line with a tax class (`mapped`), as
     * against step 4. A line sent with no code is not recorded. The first
     * line with a key decides it.
     *
     * @param list<array<string, mixed>>        $items the coded line items
     * @param list<array<string, mixed>|null>   $keys  as apply()'s
     * @return array{codes: array<string, string>, mapped: list<string>}
     */
    public static function record(array $items, array $keys): array
    {
        $record = ['codes' => [], 'mapped' => []];
        foreach ($items as $i => $item) {
            $key = $keys[$i] ?? null;
            $line = is_string($key['line'] ?? null) ? $key['line'] : null;
            if ($line === null || (float) $item['tax_rate'] != 0.0 || isset($record['codes'][$line]) || !self::isCode($item['tax_code'] ?? null)) {
                continue;
            }
            $record['codes'][$line] = $item['tax_code'];
            if ((int) ($key['class'] ?? 0) > 0) {
                $record['mapped'][] = $line;
            }
        }

        return $record;
    }

    /**
     * The record as stored on the order's row: '' for none.
     *
     * @param array{codes: array<string, string>, mapped: list<string>} $record
     */
    public static function encodeRecord(array $record): string
    {
        return $record['codes'] === [] ? '' : (string) json_encode($record, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The stored record; null when there is none (an order placed before
     * the record existed, or with no coded line) or it is not one.
     *
     * @return array{codes: array<string, string>, mapped: list<string>}|null
     */
    public static function decodeRecord(string $raw): ?array
    {
        $decoded = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['codes'] ?? null) || $decoded['codes'] === [] || !is_array($decoded['mapped'] ?? null) || !array_is_list($decoded['mapped'])) {
            return null;
        }
        $codes = [];
        foreach ($decoded['codes'] as $line => $code) {
            if (!is_string($line) || $line === '' || !self::isCode($code)) {
                return null;
            }
            $codes[$line] = $code;
        }
        $mapped = [];
        foreach ($decoded['mapped'] as $line) {
            if (!is_string($line) || !isset($codes[$line])) {
                return null;
            }
            $mapped[] = $line;
        }

        return ['codes' => $codes, 'mapped' => $mapped];
    }

    /**
     * The buyer's VAT number as entered, trimmed and otherwise untouched.
     * Sent as `buyer_vat_number` on a Spanish merchant's order create, except
     * for a Spanish buyer (Two requires a Spanish buyer's VAT number to equal
     * its organisation number) or when there is none.
     */
    public static function buyerVatNumber(string $vatNumber, string $merchantCountry, string $buyerCountry): ?string
    {
        $vat = trim($vatNumber);
        if ($vat === '' || strtoupper(trim($merchantCountry)) !== 'ES' || strtoupper(trim($buyerCountry)) === 'ES') {
            return null;
        }

        return $vat;
    }

    /**
     * The merchant's country from Two's merchant record, '' when unknown.
     *
     * @param array<string, mixed>|null $record
     */
    public static function merchantCountry(?array $record): string
    {
        $country = is_string($record['country_code'] ?? null) ? strtoupper(trim($record['country_code'])) : '';

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '';
    }

    /**
     * The stored mapping. Empty or unset is no mapping; null when the value is
     * not a map of rows to codes (one the form could not have written).
     *
     * @return array<string, string>|null
     */
    public static function parse(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $map = [];
        foreach ($decoded as $key => $code) {
            if (!self::isKey((string) $key) || !self::isCode($code)) {
                return null;
            }
            $map[(string) $key] = $code;
        }

        return $map;
    }

    public static function exemptKey(int $class): string
    {
        return $class . '|exempt';
    }

    public static function noRuleKey(int $class): string
    {
        return $class . '|none';
    }

    public static function rateKey(int $class, int $rateId): string
    {
        return $class . '|rate:' . $rateId;
    }

    public static function isKey(string $key): bool
    {
        return preg_match('/^[1-9]\d*\|(?:exempt|none|rate:[1-9]\d*)\z/', $key) === 1;
    }

    public static function isCode(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[A-Z0-9_]+\z/', $code) === 1;
    }

    /**
     * Whether the metadata of GET /v1/tax_codes/<country> makes a code mandatory on a 0% line.
     */
    public static function isMandatoryAtZero(mixed $metadata): bool
    {
        if (!is_array($metadata) || empty($metadata['mandatory_tax_codes_enabled']) || !is_array($metadata['mandatory_tax_rates'] ?? null)) {
            return false;
        }
        foreach ($metadata['mandatory_tax_rates'] as $rate) {
            if (is_numeric($rate) && (float) $rate == 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The codes of a GET /v1/tax_codes answer's `data`, leaving out codes that
     * need an exemption reason from the caller. Null when it is not a code list.
     *
     * @param array<mixed> $entries
     * @return list<array{code: string, display_name: string, rate: float}>|null
     */
    public static function normalise(array $entries): ?array
    {
        $codes = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !self::isCode($entry['code'] ?? null)) {
                return null;
            }
            if (!empty($entry['requires_exemption_reason']) && (string) ($entry['exemption_reason_code'] ?? '') === '') {
                continue;
            }
            $codes[] = [
                'code' => $entry['code'],
                'display_name' => is_string($entry['display_name'] ?? null) ? $entry['display_name'] : $entry['code'],
                'rate' => is_numeric($entry['rate'] ?? null) ? (float) $entry['rate'] : 0.0,
            ];
        }

        return $codes;
    }

    /**
     * The codes a 0% line may carry: the listed codes at rate 0.
     *
     * @param list<array{code: string, display_name: string, rate: float}> $codes
     * @return list<array{code: string, display_name: string, rate: float}>
     */
    public static function zeroRated(array $codes): array
    {
        return array_values(array_filter($codes, static fn (array $code): bool => $code['rate'] == 0.0));
    }

    /**
     * What the "Tax codes for 0% lines" section shows, first match wins: no
     * API key, hidden; no merchant country, record_error; no code list,
     * list_error; codes mandatory at 0%, rows; otherwise the saved mapping's
     * standing as a notice, else hidden. An unreadable mapping adds its notice
     * and Clear to every view but hidden-for-no-key.
     *
     * @param array{has_key: bool, raw: string|null, country: string, list: array{codes: list<array{code: string, display_name: string, rate: float}>, mandatory: bool}|null} $facts
     * @return array{view: string, notices: list<string>, clear: bool, country: string, codes: list<array{code: string, display_name: string, rate: float}>}
     */
    public static function sectionState(array $facts): array
    {
        $state = ['view' => self::VIEW_HIDDEN, 'notices' => [], 'clear' => false, 'country' => $facts['country'], 'codes' => []];
        if (!$facts['has_key']) {
            return $state;
        }
        $entries = self::parse($facts['raw']);
        if ($entries === null) {
            $state['notices'][] = self::NOTICE_UNREADABLE;
            $state['clear'] = true;
        }
        if ($facts['country'] === '') {
            $state['view'] = self::VIEW_RECORD_ERROR;

            return $state;
        }
        if ($facts['list'] === null) {
            $state['view'] = self::VIEW_LIST_ERROR;

            return $state;
        }
        $state['codes'] = $facts['list']['codes'];
        if ($facts['list']['mandatory']) {
            $state['view'] = self::VIEW_ROWS;

            return $state;
        }
        if ($entries !== null && $entries !== []) {
            $zero = array_column(self::zeroRated($state['codes']), 'code');
            $state['notices'][] = array_diff(array_values($entries), $zero) === [] ? self::NOTICE_SAVED_VALID : self::NOTICE_SAVED_INVALID;
            $state['clear'] = true;
        }
        $state['view'] = $state['notices'] === [] ? self::VIEW_HIDDEN : self::VIEW_NOTICE;

        return $state;
    }

    /**
     * The mapping a save of the rows writes: every row the form showed, a
     * blank one dropped. A code must be listed at 0% for the merchant's
     * country, or be the code that row already holds (a saved code no longer
     * listed still shows, marked, and is kept until changed). Null when a
     * posted code is neither, and nothing is written.
     *
     * @param array<string, string> $posted row key => code ('' for none)
     * @param list<string>          $rows   the rows the form showed
     * @param list<string>          $zero   the codes listed at 0% for the country
     * @param array<string, string> $stored the saved mapping
     * @return array<string, string>|null
     */
    public static function posted(array $posted, array $rows, array $zero, array $stored): ?array
    {
        $map = [];
        foreach ($rows as $key) {
            $code = trim($posted[$key] ?? '');
            if ($code === '') {
                continue;
            }
            if (!in_array($code, $zero, true) && ($stored[$key] ?? null) !== $code) {
                return null;
            }
            $map[$key] = $code;
        }
        ksort($map);

        return $map;
    }

    /**
     * @param array<string, string> $map
     */
    private static function row(array $map, string $key): ?string
    {
        return isset($map[$key]) && $map[$key] !== '' ? $map[$key] : null;
    }
}
