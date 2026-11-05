<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Availability;
use Opencart\System\Library\Extension\Two\Core\BuyerCountries;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\MinimumOrder;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\TaxCodes;

/**
 * The availability chain (Build Guide section 18), the buyer-country
 * tri-state (section 8.1) and the minimum order basis (section 6.2). Each
 * case is the merchant record, the settings, Two's answer to the key check
 * and the cart, and the first withhold reason (null when Two is offered).
 */
final class AvailabilitySpec
{
    private const NOW = 1_800_000_000;
    private const CART = ['enabled' => true, 'currency' => 'GBP', 'shop_currency' => 'GBP', 'gross' => 120.0, 'net' => 100.0, 'buyer_country' => 'GB'];

    public static function runAll(): void
    {
        $ok = new HttpResponse(200, '{"id":"m1"}');
        $floor = ['min_order_amount' => 110, 'min_order_currency' => 'GBP', 'min_order_basis' => 'net'];
        $cases = [
            [[], [], [$ok], [], null, 'everything in order offers Two'],
            [[], [], [$ok], ['enabled' => false], Availability::DISABLED, 'a disabled extension'],
            [[], ['api_key' => ''], [], [], Availability::NO_API_KEY, 'no API key'],
            [[], [], [new HttpResponse(401, '')], [], Availability::KEY_REJECTED, 'a key Two rejects'],
            [['short_name' => ''], [], [$ok], [], Availability::MERCHANT_UNIDENTIFIED, 'a record that does not name the merchant'],
            [['short_name' => null], [], [$ok], [], Availability::MERCHANT_UNIDENTIFIED, 'a record with no short name'],
            [['supported_buyer_countries' => ['NO']], [], [$ok], ['currency' => 'CHF'], Availability::CURRENCY, 'the currency is checked before the buyer country'],
            [[], [], [HttpResponse::unreachable('timeout')], [], null, 'an unreachable Two does not withhold'],
            [[], [], [new HttpResponse(503, '')], [], null, 'a Two error does not withhold'],
            [['supported_buyer_countries' => []], [], [$ok], [], Availability::BUYER_COUNTRIES_EMPTY, 'a record naming no buyer country'],
            [['supported_buyer_countries' => 'GB'], [], [$ok], [], Availability::BUYER_COUNTRIES_MALFORMED, 'a buyer country field that is not a list'],
            [['supported_buyer_countries' => ['NO', 'se']], [], [$ok], [], Availability::BUYER_COUNTRY, 'a buyer outside the allowlist'],
            [['supported_buyer_countries' => ['NO', 'gb']], [], [$ok], [], null, 'a buyer on the allowlist, matched whatever its case'],
            [['supported_buyer_countries' => ['GB']], [], [$ok], ['buyer_country' => null], Availability::BUYER_COUNTRY, 'an allowlist and no buyer country'],
            [['supported_buyer_countries' => null], [], [$ok], ['buyer_country' => null], null, 'a null field is no restriction'],
            [[], [], [$ok], ['currency' => 'CHF'], Availability::CURRENCY, 'a cart currency Two does not invoice in'],
            [[], [], [$ok], ['currency' => 'EUR', 'shop_currency' => 'CHF'], null, 'a supported cart currency in a shop whose default is not'],
            [[], ['company_search_in_address' => '0'], [$ok], ['buyer_country' => null], Availability::NO_COUNTRY, 'the search in the tile with no country'],
            [[], ['company_search_in_address' => '1'], [$ok], ['buyer_country' => null], null, 'the search in the address forms with no country'],
            [['available_terms' => [14]], [], [$ok], [], Availability::NO_TERMS, 'no offered term: the record offers none the merchant ticked'],
            [['available_terms' => [14, 90]], ['payment_term_type' => 'EOM'], [$ok], [], Availability::NO_TERMS, 'no offered term: end of month with none of 30, 45 or 60'],
            [[], ['surcharge_type' => 'percentage'], [$ok], ['fee_total' => false], Availability::FEE_TOTAL_OFF, 'a surcharge the disabled fee total would not charge'],
            [[], ['surcharge_type' => 'tiered'], [$ok], ['fee_total' => false], Availability::FEE_TOTAL_OFF, 'an unknown surcharge method with the fee total off'],
            [[], ['surcharge_type' => 'none'], [$ok], ['fee_total' => false], null, 'no surcharge needs no fee total'],
            [[], [TaxCodes::MAP_KEY => '{"3|none":"e"}'], [$ok], [], Availability::TAX_CODE_MAP, 'a tax code mapping the form could not have written'],
            [[], [TaxCodes::MAP_KEY => '[1'], [$ok], [], Availability::TAX_CODE_MAP, 'a tax code mapping that is not JSON'],
            [[], [TaxCodes::MAP_KEY => '{"3|none":"E"}'], [$ok], [], null, 'a readable tax code mapping'],
            [[], [TaxCodes::MAP_KEY => ''], [$ok], [], null, 'no tax code mapping'],
            [$floor, [], [$ok], [], Availability::BELOW_MINIMUM, 'net 100 below a net platform floor of 110'],
            [$floor, [], [$ok], ['net' => 110.0], null, 'net exactly at the platform floor'],
            [['min_order_basis' => 'gross'] + $floor, [], [$ok], [], null, 'gross 120 above a gross platform floor of 110'],
            [$floor, [], [$ok], ['currency' => 'EUR', 'net' => 200.0], null, 'converted at the rate given (200 EUR is 170 GBP)'],
            [$floor, [], [$ok], ['currency' => 'SEK', 'net' => 5000.0], Availability::BELOW_MINIMUM, 'no rate to the platform floor\'s currency fails closed'],
            [[], ['min_order_amount' => '130'], [$ok], [], Availability::BELOW_MINIMUM, 'the merchant floor defaults to gross (120 below 130)'],
            [[], ['min_order_amount' => '110', 'min_order_basis' => 'net'], [$ok], [], Availability::BELOW_MINIMUM, 'the merchant floor on net (100 below 110)'],
            [['min_order_amount' => 50, 'min_order_currency' => 'GBP', 'min_order_basis' => 'net'], ['min_order_amount' => '110'], [$ok], [], Availability::BELOW_MINIMUM, 'an unset merchant basis follows the platform floor\'s basis'],
            [[], ['min_order_amount' => '110'], [$ok], ['currency' => 'SEK', 'gross' => 1.0], null, 'a merchant floor that cannot be judged does not withhold'],
            [[], ['min_order_amount' => '0'], [$ok], [], null, 'a zero merchant floor is none'],
            [$floor, [], [$ok], ['currency' => 'EUR', 'value_currency' => 'GBP'], Availability::BELOW_MINIMUM, 'amounts kept in the shop currency are judged in it, whatever the cart shows'],
            [['min_order_amount' => 500, 'min_order_currency' => 'GBP', 'min_order_basis' => 'tax'], [], [$ok], [], null, 'a platform floor with an unknown basis is none'],
        ];
        $convert = static fn (float $amount, string $from, string $to): ?float => $from === 'EUR' && $to === 'GBP' ? round($amount * 0.85, 2) : null;
        foreach ($cases as [$record, $settings, $responses, $cart, $expected, $description]) {
            [$availability] = self::availability($record, $settings, $responses);
            $reason = $availability->withholdReason($cart + self::CART, $convert);
            TinyAssert::same($expected, $reason['reason'] ?? null, 'availability: ' . $description . ($reason === null ? '' : ' (' . $reason['detail'] . ')'));
        }

        self::testPlacement();
        self::testBuyerCountryStates();
        self::testHealth();
        self::testFeeGate();
    }

    /** The fee-quote gate closes the chain, records its failure for the health row, and a later quote clears it. */
    private static function testFeeGate(): void
    {
        $convert = static fn (float $amount, string $from, string $to): ?float => null;
        $cases = [
            [null, '', null, '', 'no fee gate passed (no surcharge) offers Two'],
            [static fn (): ?string => null, 'x', null, '', 'a fee that quotes offers Two and clears the stored failure'],
            [static fn (): string => 'the fee quote failed (HTTP 503)', '', Availability::FEE_QUOTE, '{"at":1800000000,"detail":"the fee quote failed (HTTP 503)"}', 'a fee that cannot be quoted withholds Two and is recorded'],
            [static fn (): string => 'the fee quote failed (HTTP 503)', '{"at":1700000000,"detail":"the fee quote failed (HTTP 503)"}', Availability::FEE_QUOTE, '{"at":1700000000,"detail":"the fee quote failed (HTTP 503)"}', 'the same failure again writes nothing: the time is when it began'],
            [static fn (): string => 'no FX rate', '{"at":1700000000,"detail":"the fee quote failed (HTTP 503)"}', Availability::FEE_QUOTE, '{"at":1800000000,"detail":"no FX rate"}', 'a different failure is recorded afresh'],
        ];
        foreach ($cases as [$gate, $stored, $expected, $after, $description]) {
            [$availability, , $store] = self::availability([], [Availability::FEE_FAILURE_KEY => $stored], [new HttpResponse(200, '{"id":"m1"}')]);
            $reason = $availability->withholdReason(self::CART, $convert, $gate);
            TinyAssert::same($expected, $reason['reason'] ?? null, 'fee gate: ' . $description);
            TinyAssert::same($after, $gate === null ? '' : (string) $store->values[Availability::FEE_FAILURE_KEY], 'fee gate stored failure: ' . $description);
        }
    }

    /** What placement re-checks, since the page may be stale: the currency, then the buyer country. */
    private static function testPlacement(): void
    {
        $cases = [
            [[], ['currency' => 'GBP', 'buyer_country' => 'GB'], null, 'unchanged since the method list'],
            [[], ['currency' => 'CHF', 'buyer_country' => 'GB'], Availability::CURRENCY, 'switched to a currency Two does not invoice in'],
            [['supported_buyer_countries' => ['NO']], ['currency' => 'NOK', 'buyer_country' => 'GB'], Availability::BUYER_COUNTRY, 'changed to an address outside the allowlist'],
            [['supported_buyer_countries' => ['NO']], ['currency' => 'CHF', 'buyer_country' => 'GB'], Availability::CURRENCY, 'both: the currency is named first'],
        ];
        foreach ($cases as [$record, $checkout, $expected, $description]) {
            [$availability] = self::availability($record, [], []);
            TinyAssert::same($expected, $availability->placementReason($checkout)['reason'] ?? null, 'placement re-check: ' . $description);
        }
    }

    private static function testBuyerCountryStates(): void
    {
        $cases = [
            [null, BuyerCountries::ABSENT, [], 'no record'],
            [[], BuyerCountries::ABSENT, [], 'a record without the field'],
            [['supported_buyer_countries' => []], BuyerCountries::EMPTY, [], 'an empty list'],
            [['supported_buyer_countries' => ['', 'GBR', 7]], BuyerCountries::EMPTY, [], 'a list of nothing usable'],
            [['supported_buyer_countries' => ['gb', 'GB', ' no ']], BuyerCountries::ALLOWLIST, ['GB', 'NO'], 'codes uppercased, trimmed and deduplicated'],
            [['supported_buyer_countries' => ['a' => 'GB']], BuyerCountries::MALFORMED, [], 'a map, not a list'],
            [['supported_buyer_countries' => 'GB,NO'], BuyerCountries::MALFORMED, [], 'a string'],
        ];
        foreach ($cases as [$record, $state, $allowed, $description]) {
            $countries = BuyerCountries::fromRecord($record);
            TinyAssert::same([$state, $allowed], [$countries->state, $countries->allowed], 'buyer countries: ' . $description);
        }
    }

    private static function testHealth(): void
    {
        $cases = [
            [[], [], ['GBP'], ['shown' => true, 'reason' => null, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => [], 'fee_quote_failure' => null], 'shown, no constraints'],
            [[], ['api_key' => ''], ['GBP'], ['shown' => false, 'reason' => Availability::NO_API_KEY, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => [], 'fee_quote_failure' => null], 'no key names the reason'],
            [[], [], ['CHF'], ['shown' => false, 'reason' => Availability::CURRENCY, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => [], 'fee_quote_failure' => null], 'no enabled currency Two invoices in'],
            [[], [], ['CHF', 'EUR'], ['shown' => true, 'reason' => null, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => ['CHF'], 'fee_quote_failure' => null], 'a default currency Two does not invoice in, but another it does: shown, the default named as a constraint'],
            [['short_name' => ''], [], ['GBP'], ['shown' => false, 'reason' => Availability::MERCHANT_UNIDENTIFIED, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => [], 'fee_quote_failure' => null], 'a merchant not yet identified'],
            [
                ['supported_buyer_countries' => ['GB'], 'min_order_amount' => 50, 'min_order_currency' => 'GBP', 'min_order_basis' => 'gross'],
                ['min_order_amount' => '80', 'min_order_basis' => 'gross'],
                ['GBP', 'CHF', 'EUR'],
                ['shown' => true, 'reason' => null, 'floors' => [['amount' => 80.0, 'currency' => 'GBP', 'basis' => 'gross']], 'buyer_countries' => ['GB'], 'refused_currencies' => ['CHF'], 'fee_quote_failure' => null],
                'the constraints, with the higher of two floors in one currency and basis',
            ],
            [[], [Availability::FEE_FAILURE_KEY => '{"at":1800000000,"detail":"the fee quote failed (HTTP 503)"}'], ['GBP'], ['shown' => true, 'reason' => null, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => [], 'fee_quote_failure' => ['at' => 1_800_000_000, 'detail' => 'the fee quote failed (HTTP 503)']], 'the last fee-quote withhold is named'],
            [['available_terms' => []], [], ['GBP'], ['shown' => false, 'reason' => Availability::NO_TERMS, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => [], 'fee_quote_failure' => null], 'no offered term names the reason'],
            [[], [TaxCodes::MAP_KEY => '{"x":"E"}'], ['GBP'], ['shown' => false, 'reason' => Availability::TAX_CODE_MAP, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => [], 'fee_quote_failure' => null], 'an unreadable tax code mapping names the reason'],
            [[], [Availability::FEE_FAILURE_KEY => ''], ['GBP'], ['shown' => true, 'reason' => null, 'floors' => [], 'buyer_countries' => [], 'refused_currencies' => [], 'fee_quote_failure' => null], 'a cleared fee-quote failure is not'],
        ];
        foreach ($cases as [$record, $settings, $currencies, $expected, $description]) {
            [$availability] = self::availability($record, $settings, [new HttpResponse(200, '{"id":"m1"}')]);
            $health = $availability->health(true, $currencies[0], $currencies);
            TinyAssert::same($expected, $health, 'health row: ' . $description);
        }
        TinyAssert::same(
            [['amount' => 10.0, 'currency' => 'EUR', 'basis' => 'net'], ['amount' => 5.0, 'currency' => 'GBP', 'basis' => 'gross']],
            (new MinimumOrder(['amount' => 10.0, 'currency' => 'EUR', 'basis' => 'net'], ['amount' => 5.0, 'currency' => 'GBP', 'basis' => 'gross']))->bindingFloors(),
            'health row: floors in different currencies are both named'
        );
    }

    /**
     * @param array<string, mixed>  $record
     * @param array<string, string> $settings
     * @param list<HttpResponse>    $responses
     * @return array{0: Availability, 1: ScriptedTransport, 2: MemoryStore}
     */
    private static function availability(array $record, array $settings, array $responses): array
    {
        $store = new MemoryStore($settings + ['api_key' => 'k']);
        $provider = new SettingsProvider($store);
        $store->values[RecordProvider::CACHE_KEY] = (string) json_encode(['fingerprint' => $provider->credentialFingerprint(), 'fetched_at' => self::NOW - 60, 'record' => $record + ['id' => 'm1', 'short_name' => 'acme', 'available_terms' => [30]]]);
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), $provider, new CustomHeaders(), $transport, $logger, '1.0.0');
        $clock = static fn (): int => self::NOW;
        $verdict = new ApiKeyVerdict($client, $provider, $clock);

        return [new Availability($provider, new RecordProvider($client, $provider, $verdict, $logger, $clock), $verdict, $clock), $transport, $store];
    }
}
