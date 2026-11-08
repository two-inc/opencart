<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderRefused;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;
use Opencart\System\Library\Extension\Two\Core\TaxCodeList;
use Opencart\System\Library\Extension\Two\Core\TaxCodes;

/**
 * TWO-27058, Build Guide section 22: tax codes on 0% lines and the buyer's
 * VAT number. A 0% line takes a code only from the merchant's rows, never one
 * the plugin derives; the section shows only where Two requires codes at 0%.
 */
final class TaxCodesSpec
{
    private const NOW = 1_800_000_000;
    private const EXPORT = 'ES_IVA_EXPORT';
    private const INTRA = 'ES_IVA_INTRA_COMMUNITY';
    private const CANARY = 'ES_IVA_CANARY';

    public static function runAll(): void
    {
        self::testLineCodes();
        self::testPlacementRecord();
        self::testExemptBuyer();
        self::testBuyerVatNumber();
        self::testSectionState();
        self::testPosted();
        self::testSave();
        self::testMandatory();
        self::testCodeList();
    }

    /**
     * Each case: the merchant's rows, whether the buyer is exempt (step 1),
     * the order's lines (name, net, tax, rate, key: [class, applied rates as
     * "id:percent[:F]"] or null), its totals, and the code each line is sent
     * with in order (null for none).
     */
    private static function testLineCodes(): void
    {
        $map = ['9|exempt' => self::INTRA, '9|rate:5' => self::CANARY, '9|none' => self::EXPORT, '10|none' => self::EXPORT, '11|rate:7' => self::EXPORT];
        $cases = [
            [$map, false, [['A', 100, 0, 0.0, [9, []]]], [], [self::EXPORT], 'step 3: no rate of the class applies, the no-rate row'],
            [$map, false, [['A', 100, 0, 0.0, [9, ['5:0']]]], [], [self::CANARY], 'step 2: the 0% rate the shop applied, its row'],
            [$map, true, [['A', 100, 0, 0.0, [9, ['5:0']]]], [], [self::INTRA], 'step 1: an exempt buyer takes the exempt row before the rate row'],
            [$map, true, [['A', 100, 0, 0.0, [10, []]]], [], [null], 'step 1: an exempt row left on (none) gives no code, never the no-rate row'],
            [$map, false, [['A', 100, 0, 0.0, [9, ['6:0']]]], [], [null], 'a 0% rate with no row of the merchant\'s: no code, never a derived one'],
            [[], false, [['A', 100, 0, 0.0, [9, []]]], [], [null], 'no mapping at all: no code on any 0% line'],
            [$map, false, [['A', 100, 25, 0.25, [9, ['8:25']]]], [], [null], 'a taxed line never carries a code'],
            [$map, false, [['A', 100, 0, 0.0, [9, ['5:0', '8:10:F']]]], [], [null], 'the class applied a rate that is not 0%: no code'],
            [$map, false, [['A', 100, 0, 0.0, [11, ['7:0', '5:0']]]], [], [null], 'two 0% rates where one row is (none): no code'],
            [$map, false, [['A', 100, 0, 0.0, [9, []]], ['Z', 50, 0, 0.0, null]], [], [self::EXPORT, self::EXPORT], 'step 4: a product with no tax class takes the shared code'],
            [$map, false, [['A', 100, 0, 0.0, [9, []]], ['B', 100, 0, 0.0, [9, ['5:0']]], ['Z', 50, 0, 0.0, null]], [], [self::EXPORT, self::CANARY, null], 'step 4: coded lines that disagree share nothing'],
            [$map, false, [['A', 100, 0, 0.0, [9, []]]], [['code' => 'shipping', 'title' => 'Ship', 'value' => 10, 'tax' => 0, 'rate' => 0.0, 'tax_key' => ['class' => 10, 'rates' => []]], ['code' => 'coupon', 'title' => 'Coupon', 'value' => -10]], [self::EXPORT, self::EXPORT, self::EXPORT], 'a charge takes its own class\'s row; a discount takes the shared code'],
            [$map, false, [['A', 100, 0, 0.0, [9, []]]], [['code' => 'voucher', 'title' => 'Gift voucher', 'value' => -20]], [self::EXPORT, self::EXPORT], 'store credit and vouchers have no class: the shared code'],
        ];
        foreach ($cases as [$rows, $exempt, $lines, $totals, $expected, $description]) {
            $payload = (new OrderBuilder())->build(self::order(['tax_codes' => ['map' => (string) json_encode($rows), 'exempt' => $exempt]]), array_map(self::line(...), $lines), $totals);
            $codes = array_map(static fn (array $item): ?string => $item['tax_code'] ?? null, $payload['line_items']);
            TinyAssert::same($expected, $codes, 'line codes: ' . $description);
        }

        $untouched = (new OrderBuilder())->build(self::order(), [self::line(['A', 100, 0, 0.0, [9, []]])], []);
        TinyAssert::true(!array_key_exists('tax_code', $untouched['line_items'][0]), 'line codes: an order built without tax code facts carries no tax_code key');

        $unreadable = [
            [[['A', 100, 0, 0.0, [9, []]]], 'tax_code_map_unreadable', 'a 0% line with an unreadable mapping is refused'],
            [[['A', 100, 25, 0.25, [9, ['8:25']]]], null, 'an order with no 0% line never reads the mapping'],
        ];
        foreach ($unreadable as [$lines, $reason, $description]) {
            try {
                (new OrderBuilder())->build(self::order(['tax_codes' => ['map' => '{"9":"X"}', 'exempt' => false]]), array_map(self::line(...), $lines), []);
                $got = null;
            } catch (OrderRefused $refused) {
                $got = $refused->reason;
            }
            TinyAssert::same($reason, $got, 'unreadable mapping: ' . $description);
        }
    }

    /**
     * The placement record (TWO-27110). Each case: the rows at placement, the
     * order as placed (lines with a product id, totals), the record placement
     * keeps; then the rows, exemption and lines when the order is rebuilt (an
     * edit or the confirm check) and the code each rebuilt line is sent with.
     */
    private static function testPlacementRecord(): void
    {
        $placed = ['9|rate:5' => self::CANARY, '9|none' => self::EXPORT, '10|none' => self::EXPORT];
        $changed = ['9|rate:5' => self::INTRA, '9|none' => self::INTRA, '9|exempt' => self::INTRA, '10|none' => self::INTRA];
        $a = ['A', 100, 0, 0.0, [9, ['5:0']], 1];
        $ship = ['code' => 'shipping', 'title' => 'Ship', 'value' => 10, 'tax' => 0, 'rate' => 0.0, 'tax_key' => ['class' => 10, 'rates' => []]];
        $coupon = ['code' => 'coupon', 'title' => 'Coupon', 'value' => -10];
        $shipNone = ['code' => 'shipping', 'title' => 'Ship', 'value' => 10, 'tax' => 0, 'rate' => 0.0, 'tax_key' => null];
        $cases = [
            [$placed, [$a], [], ['product:1' => self::CANARY], $changed, false, [$a], [], [self::CANARY], 'the mapping changed after placement: the edit sends the recorded code'],
            [$placed, [$a], [], ['product:1' => self::CANARY], $placed, true, [['A', 100, 0, 0.0, [9, []], 1]], [], [self::CANARY], 'the addresses and rates changed (now exempt, no rule): still the recorded code'],
            [$placed, [$a], [], ['product:1' => self::CANARY], $changed, false, [$a, ['B', 50, 0, 0.0, [9, []], 2]], [], [self::CANARY, self::INTRA], 'a line an edit added is resolved from the mapping as it is now'],
            [$placed, [$a], [$ship, $coupon], ['product:1' => self::CANARY, 'shipping' => self::EXPORT], $changed, false, [$a], [$ship, $coupon], [self::CANARY, self::EXPORT, null], 'a charge is recorded under its total code; a discount is not, and shares nothing when the recorded codes disagree'],
            [$placed, [$a], [$coupon], ['product:1' => self::CANARY, 'discount' => self::CANARY], $changed, false, [$a], [$coupon], [self::CANARY, self::CANARY], 'a discount step 4 coded is recorded, and keeps its code'],
            [$placed, [$a], [$shipNone], ['product:1' => self::CANARY, 'shipping' => self::CANARY], $changed, false, [$a, ['B', 50, 0, 0.0, [9, []], 2]], [$shipNone], [self::CANARY, self::INTRA, self::CANARY], 'shipping with no class keeps its placed code when an edit adds a line with another code'],
            [$placed, [$a], [$shipNone], ['product:1' => self::CANARY, 'shipping' => self::CANARY], $changed, false, [['C', 100, 25, 0.25, [9, ['8:25']], 3]], [$shipNone], [null, self::CANARY], 'shipping with no class keeps its placed code when an edit removes the only coded line'],
            [[], [$a], [], [], $changed, false, [$a], [], [self::INTRA], 'a line placed with no code is not recorded, so a rebuild resolves it'],
            [$placed, [['A', 100, 25, 0.25, [9, ['8:25']], 1]], [], [], $changed, false, [$a], [], [self::INTRA], 'a taxed line is never recorded'],
        ];
        foreach ($cases as [$rowsThen, $linesThen, $totalsThen, $record, $rowsNow, $exemptNow, $linesNow, $totalsNow, $expected, $description]) {
            $create = (new OrderBuilder())->build(self::order(['tax_codes' => ['map' => (string) json_encode($rowsThen), 'exempt' => false]]), array_map(self::line(...), $linesThen), $totalsThen, $kept);
            TinyAssert::same($record, $kept, 'placement record: ' . $description . ' (kept)');
            TinyAssert::same($record === [] ? null : $record, TaxCodes::decodeRecord(TaxCodes::encodeRecord($kept)), 'placement record: ' . $description . ' (stored and read back)');
            $rebuilt = (new OrderBuilder())->build(self::order(['tax_codes' => ['map' => (string) json_encode($rowsNow), 'exempt' => $exemptNow, 'recorded' => TaxCodes::decodeRecord(TaxCodes::encodeRecord($kept))]]), array_map(self::line(...), $linesNow), $totalsNow);
            $codes = array_map(static fn (array $item): ?string => $item['tax_code'] ?? null, $rebuilt['line_items']);
            TinyAssert::same($expected, $codes, 'placement record: ' . $description);
            unset($kept);
        }

        $replayed = (new OrderBuilder())->build(self::order(['tax_codes' => ['map' => '{"9":"X"}', 'exempt' => false, 'recorded' => ['product:1' => self::CANARY]]]), [self::line($a)], [$coupon]);
        TinyAssert::same([self::CANARY, self::CANARY], array_map(static fn (array $item): ?string => $item['tax_code'] ?? null, $replayed['line_items']), 'placement record: a rebuild whose lines are all recorded never reads the mapping, so an unreadable one refuses nothing');
        foreach (['', '[]', '{"product:1":"bad code"}', '{"":"ES_X"}', 'not json'] as $raw) {
            TinyAssert::same(null, TaxCodes::decodeRecord($raw), 'placement record: ' . var_export($raw, true) . ' is no record');
        }
    }

    private static function testExemptBuyer(): void
    {
        $cases = [
            ['FR', 'FR', 'FR123', 'ES', true, 'billing and tax address in another EU state, with a VAT number'],
            ['FR', 'FR', '  ', 'ES', false, 'a VAT number that is only whitespace is none'],
            ['FR', 'US', 'FR123', 'ES', false, 'a tax address outside the EU VAT area is an export'],
            ['ES', 'FR', 'ES123', 'ES', false, 'a billing address in the merchant\'s country'],
            ['FR', 'ES', 'FR123', 'ES', false, 'a tax address in the merchant\'s country'],
            ['MC', 'MC', 'MC1', 'ES', true, 'Monaco is in the EU VAT area'],
            ['GB:BT1 1AA', 'GB:BT1 1AA', 'GB1', 'ES', true, 'Northern Ireland (a BT postcode) is in the EU VAT area'],
            ['GB:SW1A 1AA', 'GB:SW1A 1AA', 'GB1', 'ES', false, 'Great Britain is not'],
            ['FR', 'FR', 'FR123', '', false, 'an unknown merchant country makes nobody exempt'],
        ];
        foreach ($cases as [$billing, $tax, $vat, $merchant, $expected, $description]) {
            TinyAssert::same($expected, TaxCodes::isExemptBuyer(self::address($billing), self::address($tax), $vat, $merchant), 'exempt buyer: ' . $description);
        }
    }

    /** Rows: VAT number as entered, merchant country, buyer company country, expected buyer_vat_number (null for absent). */
    private static function testBuyerVatNumber(): void
    {
        $cases = [
            [' FR 123 ', 'ES', 'FR', 'FR 123', 'a Spanish merchant sends it trimmed and otherwise as entered'],
            ['fr123', 'ES', 'FR', 'fr123', 'never upper-cased'],
            ['ESB123', 'ES', 'ES', null, 'never for a Spanish buyer'],
            ['FR123', 'NO', 'FR', null, 'other merchants\' payloads are unchanged'],
            ['', 'ES', 'FR', null, 'absent rather than empty when there is none'],
        ];
        foreach ($cases as [$vat, $merchant, $buyer, $expected, $description]) {
            $sent = TaxCodes::buyerVatNumber($vat, $merchant, $buyer);
            TinyAssert::same($expected, $sent, 'buyer VAT number: ' . $description);
            $payload = (new OrderBuilder())->build(self::order(['buyer_vat_number' => $sent]), [self::line(['A', 100, 25, 0.25, null])], []);
            TinyAssert::same($expected !== null, array_key_exists('buyer_vat_number', $payload), 'buyer VAT number on the create: ' . $description);
        }
    }

    /** Rows: has key, stored mapping, merchant country, code list (null for none read), expected view, notices, clear. */
    private static function testSectionState(): void
    {
        $mandatory = ['codes' => self::codes(), 'mandatory' => true];
        $optional = ['codes' => self::codes(), 'mandatory' => false];
        $cases = [
            [false, '', 'ES', $mandatory, 'hidden', [], false, 'no API key: hidden'],
            [true, '', '', null, 'record_error', [], false, 'no merchant country: says so'],
            [true, '', 'ES', null, 'list_error', [], false, 'no code list: says so'],
            [true, '', 'ES', $mandatory, 'rows', [], false, 'codes mandatory at 0%: the rows'],
            [true, '', 'NO', $optional, 'hidden', [], false, 'not mandatory, nothing saved: hidden'],
            [true, '{"9|none":"ES_IVA_EXPORT"}', 'NO', $optional, 'notice', ['saved_valid'], true, 'not mandatory, a listed 0% code saved: the notice and Clear'],
            [true, '{"9|none":"ES_IVA_STANDARD"}', 'NO', $optional, 'notice', ['saved_invalid'], true, 'a saved code not listed at 0%: the warning and Clear'],
            [true, '{"9":"X"}', 'ES', $mandatory, 'rows', ['unreadable'], true, 'an unreadable mapping: its notice and Clear over the rows'],
            [true, '{}', 'NO', $optional, 'hidden', [], false, 'a cleared mapping is no mapping'],
        ];
        foreach ($cases as [$hasKey, $raw, $country, $list, $view, $notices, $clear, $description]) {
            $state = TaxCodes::sectionState(['has_key' => $hasKey, 'raw' => $raw, 'country' => $country, 'list' => $list]);
            TinyAssert::same([$view, $notices, $clear], [$state['view'], $state['notices'], $state['clear']], 'section: ' . $description);
        }
    }

    /** Rows: posted rows, stored mapping, expected mapping (null when refused). */
    private static function testPosted(): void
    {
        $rows = ['9|exempt', '9|rate:5', '9|none'];
        $zero = ['ES_IVA_EXPORT', 'ES_IVA_INTRA_COMMUNITY'];
        $cases = [
            [['9|none' => 'ES_IVA_EXPORT', '9|exempt' => ''], [], ['9|none' => 'ES_IVA_EXPORT'], 'a listed code is saved, a blank row dropped'],
            [['9|none' => 'ES_IVA_STANDARD'], [], null, 'a code not listed at 0% refuses the save'],
            [['9|rate:5' => 'ES_RETIRED'], ['9|rate:5' => 'ES_RETIRED'], ['9|rate:5' => 'ES_RETIRED'], 'a saved code no longer listed is kept on its own row'],
            [['9|none' => 'ES_RETIRED'], ['9|rate:5' => 'ES_RETIRED'], null, 'but not moved to another row'],
            [['12|none' => 'ES_IVA_EXPORT'], ['9|none' => 'ES_IVA_EXPORT'], [], 'a row the form did not show is not saved'],
        ];
        foreach ($cases as [$posted, $stored, $expected, $description]) {
            TinyAssert::same($expected, TaxCodes::posted($posted, $rows, $zero, $stored), 'posted rows: ' . $description);
        }
    }

    /** The save through SettingsSave: rows only whole and only while shown, a cut-short form not at all, Clear, and the VAT field. */
    private static function testSave(): void
    {
        $context = ['rows' => ['9|none'], 'zero' => ['ES_IVA_EXPORT'], 'vat_fields' => ['3']];
        $before = '{"9|none":"ES_IVA_EXPORT"}';
        $cases = [
            [['tax_code_map' => ['9|none' => ''], 'debug_mode' => '1', 'form_end' => '1'], $context, '{}', null, 'the rows as posted, (none) clearing a row, with the rest of the form'],
            [['tax_code_map' => ['9|none' => ''], 'debug_mode' => '1', 'form_end' => ''], $context, $before, 'form_truncated', 'a post cut short by max_input_vars saves nothing, a Diagnostics switch after the rows included'],
            [['tax_code_map' => ['9|none' => 'ES_IVA_STANDARD'], 'form_end' => '1'], $context, $before, 'tax_code_unlisted', 'an unlisted code is refused'],
            [['tax_code_map' => ['9|none' => ''], 'form_end' => '1'], ['rows' => null] + $context, $before, null, 'rows posted while the section shows none are ignored'],
            [['tax_codes_clear' => '1', 'tax_code_map' => ['9|none' => 'ES_IVA_STANDARD']], $context, '{}', null, 'Clear stores an empty mapping, whatever the rows say'],
        ];
        foreach ($cases as [$submitted, $taxContext, $stored, $error, $description]) {
            [$save, $store] = self::save($before);
            $result = $save->save($submitted, [], 0, [], $taxContext);
            TinyAssert::same([$stored, $error], [$store->get(TaxCodes::MAP_KEY), $result['errors']['tax_code_map'] ?? $result['errors']['form_end'] ?? null], 'save: ' . $description);
            if (isset($submitted['debug_mode'])) {
                TinyAssert::same($error === null ? '1' : null, $store->get('debug_mode'), 'save: ' . $description . ': the Diagnostics switch');
            }
        }
        $vat = [['3', '3', null, 'a listed custom field'], ['', '', null, 'none'], ['99', '7', 'vat_field_unknown', 'a field that is not a text field of the account or address']];
        foreach ($vat as [$posted, $stored, $error, $description]) {
            [$save, $store] = self::save($before, ['buyer_vat_field' => '7']);
            $result = $save->save(['buyer_vat_field' => $posted], [], 0, [], $context);
            TinyAssert::same([$stored, $error], [$store->get(TaxCodes::VAT_FIELD_KEY), $result['errors']['buyer_vat_field'] ?? null], 'VAT field: ' . $description);
        }
    }

    private static function testMandatory(): void
    {
        $cases = [
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0']], true, '"0"'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0.00', '21']], true, '"0.00" among others'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['21']], false, 'a mandatory rate above 0'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['zero']], false, 'a non-numeric rate'],
            [['mandatory_tax_codes_enabled' => false, 'mandatory_tax_rates' => ['0']], false, 'not enabled'],
            [null, false, 'no metadata'],
        ];
        foreach ($cases as [$metadata, $expected, $description]) {
            TinyAssert::same($expected, TaxCodes::isMandatoryAtZero($metadata), 'mandatory at 0%: ' . $description);
        }
    }

    /** The code list: a day per country, a 5-minute floor after a failure, the last good list served meanwhile. */
    private static function testCodeList(): void
    {
        $good = new HttpResponse(200, (string) json_encode(['data' => [
            ['code' => 'ES_IVA_EXPORT', 'display_name' => 'Export', 'rate' => '0'],
            ['code' => 'ES_IVA_EXEMPT_OTHER', 'rate' => '0', 'requires_exemption_reason' => true],
            ['code' => 'ES_IVA_STANDARD', 'display_name' => 'Standard', 'rate' => '21'],
        ], 'metadata' => ['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0']]]));
        $unsupported = new HttpResponse(400, '{"error_code":"COUNTRY_NOT_SUPPORTED"}');
        $cases = [
            [[$good], [['ES', 0]], [['ES_IVA_EXPORT', 'ES_IVA_STANDARD'], true], 1, 'fetched, codes needing a reason left out'],
            [[$good], [['ES', 0], ['ES', 3600]], [['ES_IVA_EXPORT', 'ES_IVA_STANDARD'], true], 1, 'served from the cache within the day'],
            [[$good, $good], [['ES', 0], ['ES', 90_000]], [['ES_IVA_EXPORT', 'ES_IVA_STANDARD'], true], 2, 'refetched after a day'],
            [[$good, new HttpResponse(503, '')], [['ES', 0], ['ES', 90_000]], [['ES_IVA_EXPORT', 'ES_IVA_STANDARD'], true], 2, 'a failed refetch serves the last good list'],
            [[new HttpResponse(503, '')], [['ES', 0], ['ES', 60]], null, 1, 'a failure is not retried inside the floor'],
            [[new HttpResponse(503, ''), $good], [['ES', 0], ['ES', 301]], [['ES_IVA_EXPORT', 'ES_IVA_STANDARD'], true], 2, 'and is retried after it'],
            [[new HttpResponse(503, ''), $good], [['ES', 0], ['FR', 60]], [['ES_IVA_EXPORT', 'ES_IVA_STANDARD'], true], 2, 'the floor holds only for its country'],
            [[$unsupported], [['GB', 0]], [[], false], 1, 'a country Two does not support is an empty list'],
        ];
        foreach ($cases as [$answers, $asks, $expected, $calls, $description]) {
            $now = self::NOW;
            $transport = new ScriptedTransport($answers);
            $store = new MemoryStore(['api_key' => 'k']);
            $logger = new RecordingLogger();
            $client = new Client(new Environment(false), new SettingsProvider($store), new CustomHeaders(), $transport, $logger, '1.0.0');
            $list = new TaxCodeList($client, $store, $logger, static function () use (&$now): int {
                return $now;
            });
            $got = null;
            foreach ($asks as [$country, $offset]) {
                $now = self::NOW + $offset;
                $got = $list->forCountry($country);
            }
            TinyAssert::same($expected, $got === null ? null : [array_column($got['codes'], 'code'), $got['mandatory']], 'code list: ' . $description);
            TinyAssert::same($calls, count($transport->calls), 'code list calls: ' . $description);
        }

        // While the last good list is served after a failure, it names when it was read and the last failure: [answers, ask offsets, expected [fetched_at offset, failure]].
        $failed = new HttpResponse(503, '');
        $stale = [
            [[$good], [0], [0, null], 'a fresh list names no failure'],
            [[$good, $failed], [0, 90_000], [0, ['at' => 90_000, 'status' => 503]], 'a failed refresh names itself and the held list\'s age'],
            [[$good, $failed], [0, 90_000, 90_060], [0, ['at' => 90_000, 'status' => 503]], 'and still does inside the retry floor'],
            [[$good, $failed, $good], [0, 90_000, 90_301], [90_301, null], 'a good refresh clears it'],
        ];
        foreach ($stale as [$answers, $offsets, [$fetched, $failure], $description]) {
            $now = self::NOW;
            $store = new MemoryStore(['api_key' => 'k']);
            $logger = new RecordingLogger();
            $client = new Client(new Environment(false), new SettingsProvider($store), new CustomHeaders(), new ScriptedTransport($answers), $logger, '1.0.0');
            $list = new TaxCodeList($client, $store, $logger, static function () use (&$now): int {
                return $now;
            });
            $got = null;
            foreach ($offsets as $offset) {
                $now = self::NOW + $offset;
                $got = $list->forCountry('ES');
            }
            $expected = [self::NOW + $fetched, $failure === null ? null : ['at' => self::NOW + $failure['at'], 'status' => $failure['status']]];
            TinyAssert::same($expected, [$got['fetched_at'] ?? null, $got['failure'] ?? null], 'code list age and failure: ' . $description);
        }
    }

    /**
     * @param array<string, string> $extra
     * @return array{0: SettingsSave, 1: MemoryStore}
     */
    private static function save(string $map, array $extra = []): array
    {
        $store = new MemoryStore([TaxCodes::MAP_KEY => $map] + $extra);
        $settings = new SettingsProvider($store);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), $settings, new CustomHeaders(), new ScriptedTransport(), $logger, '1.0.0');
        $verdict = new \Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict($client, $settings);
        $records = new \Opencart\System\Library\Extension\Two\Core\RecordProvider($client, $settings, $verdict, $logger);

        return [new SettingsSave($settings, new Environment(false), $verdict, $records), $store];
    }

    /** @return list<array{code: string, display_name: string, rate: float}> */
    private static function codes(): array
    {
        return [
            ['code' => 'ES_IVA_EXPORT', 'display_name' => 'Export', 'rate' => 0.0],
            ['code' => 'ES_IVA_STANDARD', 'display_name' => 'Standard', 'rate' => 21.0],
        ];
    }

    /** @return array{country: string, postcode: string} "CC" or "CC:postcode" */
    private static function address(string $spec): array
    {
        [$country, $postcode] = explode(':', $spec . ':', 2);

        return ['country' => $country, 'postcode' => rtrim($postcode, ':')];
    }

    /**
     * @param array{0: string, 1: float, 2: float, 3: float, 4: array{0: int, 1: list<string>}|null, 5?: int} $spec name, net, tax, rate, key, product id
     * @return array<string, mixed>
     */
    private static function line(array $spec): array
    {
        [$name, $net, $tax, $rate, $key] = $spec;
        $product = isset($spec[5]) ? ['product_id' => $spec[5]] : [];
        $rates = $key === null ? null : array_map(static function (string $rate): array {
            $parts = explode(':', $rate);

            return ['id' => (int) $parts[0], 'rate' => (float) $parts[1], 'type' => $parts[2] ?? 'P'];
        }, $key[1]);

        return ['name' => $name, 'quantity' => 1, 'price' => $net, 'total' => $net, 'tax' => $tax, 'rate' => $rate, 'type' => 'PHYSICAL', 'tax_key' => $key === null ? null : ['class' => $key[0], 'rates' => $rates]] + $product;
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function order(array $over = []): array
    {
        return $over + [
            'order_id' => 42, 'currency' => 'EUR', 'currency_value' => 1.0, 'total' => 0, 'tax_total' => 0,
            'date_added' => '2026-10-24 10:00:00', 'comment' => '', 'carrier_name' => '',
            'company' => ['company_name' => 'Acme SARL', 'organization_number' => '123', 'country_code' => 'FR'],
            'buyer' => ['email' => 'buyer@example.com', 'first_name' => 'A', 'last_name' => 'B', 'phone_number' => '+33100000000'],
            'billing_address' => ['street_address' => 'Rue 1', 'postal_code' => '75001', 'city' => 'Paris', 'region' => '', 'country' => 'FR'],
            'shipping_address' => null,
            'merchant_urls' => [],
        ];
    }
}
