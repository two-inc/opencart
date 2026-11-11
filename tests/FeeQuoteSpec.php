<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\FeeQuote;
use Opencart\System\Library\Extension\Two\Core\FxRates;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\PaymentTerms;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\Surcharge;

/**
 * Two's FX rates and the fee quote (Build Guide section 5): conversion at
 * Two's EUR-pivot rates with serve-stale, the quote request and its key,
 * failing closed, the charged term, and the gate's currency half.
 */
final class FeeQuoteSpec
{
    private const NOW = 1_800_000_000;
    /** One EUR is 11.5 NOK and 0.85 GBP. */
    private const TABLE = '{"base":"EUR","as_of":"2026-10-25","fetched_at":1800000000,"rates":{"EUR":1,"NOK":0.0869565,"GBP":1.1764706}}';
    private const GRID = '{"30":{"percentage":"2","fixed":"10","cap":"50"},"60":{"percentage":"3","fixed":"","cap":""}}';

    public static function runAll(): void
    {
        self::testConvert();
        self::testRefresh();
        self::testQuote();
        self::testCharged();
        self::testCurrencyFailure();
        self::testQuoteAll();
        self::testLineValue();
        self::testPlacementRefusal();
        self::testPlacedFee();
    }

    /** An admin edit puts back the fee the order was placed with, or none: never a new quote. */
    private static function testPlacedFee(): void
    {
        $fee = ['code' => 'two_fee', 'title' => 'Payment terms fee - 30 days', 'value' => 10.0, 'tax' => 2.5, 'rate' => 0.25, 'tax_rates' => [86 => ['amount' => 2.5, 'name' => 'VAT 25%']]];
        $placed = FeeQuote::encodePlaced([['code' => 'sub_total', 'value' => 100], $fee], 1.0, 'GBP');
        $line = ['title' => 'Payment terms fee - 30 days', 'value' => 10.0, 'tax' => 2.5, 'rate' => 0.25, 'tax_rates' => [86 => ['amount' => 2.5, 'name' => 'VAT 25%']]];
        $legacy = '{"title":"Payment terms fee - 30 days","net":10,"tax":2.5,"rate":0.25,"tax_rates":{"86":{"amount":2.5,"name":"VAT 25%"}}}';
        $cases = [
            [['payment_fee' => '1', 'placed_fee' => $placed], $line, 'placed with a fee: that fee, its tax rows and their names as stored'],
            [['payment_fee' => '0', 'placed_fee' => FeeQuote::encodePlaced([], 1.0, 'GBP')], null, 'placed without a fee (a shop with no surcharge, or a term with none): none, and nothing to quote'],
            [['payment_fee' => '1', 'placed_fee' => ''], null, 'a fee flag with no stored fee: none'],
            [['payment_fee' => '1', 'placed_fee' => $legacy], null, 'a fee stored without its currency (before the currency was kept): none, so the edit is refused as one that lost its fee'],
            [['payment_fee' => '0', 'placed_fee' => $placed], null, 'a stored fee on an order flagged without one: none'],
            [[], null, 'no Two record: none'],
        ];
        foreach ($cases as [$record, $expected, $description]) {
            TinyAssert::same($expected, FeeQuote::editLine($record, 1.0, 'GBP'), 'edit line: ' . $description);
        }
        TinyAssert::same('{"currency":"NOK","fx":11.5}', FeeQuote::encodePlaced([['code' => 'shipping', 'value' => 5]], 11.5, 'NOK'), 'placed fee: an order with no fee line keeps only its currency and value');
        TinyAssert::same(['NOK', 11.5], [json_decode(FeeQuote::encodePlaced([$fee], 11.5, 'NOK'), true)['currency'] ?? null, json_decode(FeeQuote::encodePlaced([$fee], 11.5, 'NOK'), true)['fx'] ?? null], 'placed fee: the order\'s currency code and value are stored with the fee');
        TinyAssert::same(null, FeeQuote::decodePlaced('{"currency":"GBP","fx":1,"net":0,"tax":0}', 1.0, 'GBP'), 'placed fee: a zero fee is none');

        // A shop in EUR, an order in NOK placed at 11.5 and edited when the rate is 12.0:
        // the fee in the order's currency stays exactly as placed, never drifting with the rate.
        $nok = ['value' => 100.0 / 11.5, 'tax' => 25.0 / 11.5, 'tax_rates' => [86 => ['amount' => 25.0 / 11.5, 'name' => 'MVA 25%']]] + $fee;
        $nokRecord = ['payment_fee' => '1', 'placed_fee' => FeeQuote::encodePlaced([$nok], 11.5, 'NOK')];
        $edited = FeeQuote::editLine($nokRecord, 12.0, 'NOK');
        TinyAssert::same(['100.00', '25.00', '25.00', 'MVA 25%'], [
            number_format(($edited['value'] ?? 0) * 12.0, 2, '.', ''),
            number_format(($edited['tax'] ?? 0) * 12.0, 2, '.', ''),
            number_format(($edited['tax_rates'][86]['amount'] ?? 0) * 12.0, 2, '.', ''),
            $edited['tax_rates'][86]['name'] ?? '',
        ], 'placed fee: in a non-default currency at a changed rate, the order-currency fee and tax are exactly as placed');

        // An edit that changes the order's currency: the fee keeps its worth (never the placed
        // figures relabelled), and the edit sync is refused with the currency it was placed in.
        $eurRecord = ['payment_fee' => '1', 'placed_fee' => FeeQuote::encodePlaced([['value' => 10.0, 'tax' => 2.5] + $fee], 1.0, 'EUR')];
        $changes = [
            // record, currency now, its value now, fee net and tax in that currency, refused from, description
            [$nokRecord, 'NOK', 12.0, ['100.00', '25.00'], null, 'NOK order edited in NOK: unchanged, not refused'],
            [$nokRecord, 'EUR', 1.0, ['8.70', '2.17'], 'NOK', 'NOK order switched to EUR: NOK 100.00 is EUR 8.70 at the placement rate, not EUR 100.00, and refused'],
            [$eurRecord, 'EUR', 1.0, ['10.00', '2.50'], null, 'EUR order edited in EUR: unchanged, not refused'],
            [$eurRecord, 'NOK', 11.5, ['115.00', '28.75'], 'EUR', 'EUR order switched to NOK: EUR 10.00 is NOK 115.00, not NOK 10.00, and refused'],
            [['payment_fee' => '0', 'placed_fee' => FeeQuote::encodePlaced([], 11.5, 'NOK')], 'EUR', 1.0, ['0.00', '0.00'], 'NOK', 'an order placed without a fee switched to EUR: refused too'],
            [['payment_fee' => '1', 'placed_fee' => $legacy], 'EUR', 1.0, ['0.00', '0.00'], null, 'a record kept without its currency: no currency to judge (its fee is gone, so refused as one that lost it)'],
        ];
        foreach ($changes as [$record, $currency, $fx, $expected, $refused, $description]) {
            $line = FeeQuote::editLine($record, $fx, $currency);
            TinyAssert::same($expected, [number_format(($line['value'] ?? 0) * $fx, 2, '.', ''), number_format(($line['tax'] ?? 0) * $fx, 2, '.', '')], 'currency change, fee shown: ' . $description);
            TinyAssert::same($refused, FeeQuote::placedInOtherCurrency($record, $currency), 'currency change, refused from: ' . $description);
        }
        TinyAssert::same([
            'the order was placed with Two in NOK and its currency is now EUR. Two keeps an order in the currency it was placed in.',
            null,
            null,
        ], [
            FeeQuote::currencyChange($nokRecord, 'EUR')?->english(),
            FeeQuote::currencyChange($nokRecord, 'NOK'),
            FeeQuote::currencyChange($nokRecord, ''),
        ], 'currency change: the confirm\'s reason, with no advice; none when unchanged or not known');
    }

    /** The chips' preview: terms asked together, a kept quote reused, the fresh ones handed back to keep. */
    private static function testQuoteAll(): void
    {
        [$quotes, $transport, $settings] = self::quotes(['surcharge_type' => 'percentage'], [new HttpResponse(200, '{"currency":"NOK","buyer_fee_share":"3"}'), new HttpResponse(503, '')]);
        $surcharge = Surcharge::from($settings);
        $terms = PaymentTerms::from(['available_terms' => [30, 60, 90]], $settings);
        $kept = $quotes->payload($surcharge, $terms, 90, 100.0, 'NO', 'NOK', 'NOK');
        $quotes->remember([hash('sha256', (string) json_encode($kept)) => ['net' => 9.0, 'currency' => 'NOK']]);
        $result = $quotes->quoteAll($surcharge, $terms, [30, 60, 90], 100.0, 'NO', 'NOK', 'NOK', FeeQuote::PREVIEW_SECONDS);
        TinyAssert::same([30 => ['net' => 3.0, 'currency' => 'NOK'], 60 => null, 90 => ['net' => 9.0, 'currency' => 'NOK']], [30 => $result[30], 60 => $result[60], 90 => $result[90]], 'quote all: each term answered, a failure null, a kept quote reused');
        TinyAssert::same(2, count($transport->calls), 'quote all: only the terms not kept are asked');
        TinyAssert::same(FeeQuote::PREVIEW_SECONDS, $transport->calls[0]['timeout'], 'quote all: the preview\'s whole wait is capped');
        TinyAssert::same([['net' => 3.0, 'currency' => 'NOK'], null], array_values($quotes->fresh()), 'quote all: Two\'s new answers are handed back to keep, a failure as null');

        // A failure kept from an earlier request answers without asking Two again.
        [$quotes, $transport, $settings] = self::quotes(['surcharge_type' => 'percentage'], []);
        $surcharge = Surcharge::from($settings);
        $terms = PaymentTerms::from(['available_terms' => [30, 60, 90]], $settings);
        $failed = $quotes->payload($surcharge, $terms, 30, 100.0, 'NO', 'NOK', 'NOK');
        $quotes->remember([hash('sha256', (string) json_encode($failed)) => null]);
        TinyAssert::same(null, $quotes->quote($surcharge, $terms, 30, 100.0, 'NO', 'NOK', 'NOK'), 'quote all: a kept failure answers null');
        TinyAssert::same(0, count($transport->calls), 'quote all: a kept failure is not asked again, so an outage costs one wait');

        // Outside the checkout only quotes already held answer.
        [$quotes, $transport, $settings] = self::quotes(['surcharge_type' => 'percentage'], [new HttpResponse(200, '{"currency":"NOK","buyer_fee_share":"3"}')]);
        $quotes->askTwo = false;
        TinyAssert::same(null, $quotes->quote(Surcharge::from($settings), PaymentTerms::from(['available_terms' => [30]], $settings), 30, 100.0, 'NO', 'NOK', 'NOK'), 'quote all: outside the checkout an unheld quote is none');
        TinyAssert::same(0, count($transport->calls), 'quote all: outside the checkout Two is never asked');
    }

    private static function testLineValue(): void
    {
        $quoted = ['applies' => true, 'days' => 30, 'net' => 25.0];
        $cases = [
            [$quoted, 'two.two', 2.5, 10.0, 'the net in the shop currency: the session currency\'s net over its value'],
            [$quoted, 'cod.cod', 1.0, null, 'no fee line while another method is chosen'],
            [['net' => null] + $quoted, 'two.two', 1.0, null, 'no fee line when the quote failed'],
            [['net' => 0.0] + $quoted, 'two.two', 1.0, null, 'no fee line for a zero fee'],
            [['applies' => false] + $quoted, 'two.two', 1.0, null, 'no fee line when none applies'],
            [$quoted, 'two.two', 0.0, null, 'no fee line without a currency value'],
        ];
        foreach ($cases as [$charged, $code, $value, $expected, $description]) {
            TinyAssert::same($expected, FeeQuote::lineValue($charged, $code, $value), 'line value: ' . $description);
        }
    }

    private static function testPlacementRefusal(): void
    {
        $settings = new SettingsProvider(new MemoryStore(['payment_terms' => '[30,60]']));
        $offered = PaymentTerms::from(['available_terms' => [30, 60]], $settings);
        $none = PaymentTerms::from(['available_terms' => [14]], $settings);
        $fee = ['applies' => true, 'days' => 30, 'net' => 12.34];
        $cases = [
            [$offered, 30, $fee, 1234, null, 'the stored fee is the fee for the term'],
            [$offered, null, ['applies' => false, 'days' => 30, 'net' => null], 0, null, 'no fee applies and none is stored'],
            [$none, null, $fee, 1234, 'terms', 'no term offered'],
            [$offered, 90, $fee, 1234, 'terms', 'a chosen term the record does not offer'],
            [$offered, 30, ['net' => null] + $fee, 0, 'fee', 'a fee that cannot be quoted'],
            [$offered, 60, ['days' => 60, 'net' => 20.0] + $fee, 1234, 'fee_changed', 'the order was written before the buyer changed term'],
            [$offered, 30, $fee, 0, 'fee_changed', 'the order carries no fee line (the fee total did not run)'],
            [$offered, 30, ['applies' => false] + $fee, 1234, 'fee_changed', 'a stored fee no term prices'],
        ];
        foreach ($cases as [$terms, $chosen, $charged, $stored, $expected, $description]) {
            $refusal = FeeQuote::placementRefusal($terms, $chosen, $charged, 'the quote failed', $stored);
            TinyAssert::same($expected, $refusal['reason'] ?? null, 'placement: ' . $description);
        }
    }

    private static function testConvert(): void
    {
        $fresh = self::table(self::NOW - 60);
        $answer = new HttpResponse(200, '{"base":"EUR","rates":{"EUR":1,"NOK":0.1,"GBP":1.2}}');
        $due = (string) (self::NOW - FxRates::TTL - 1);
        $cases = [
            [[], 100.0, 'NOK', 'NOK', [], 100.0, 0, 'the same currency needs no rate'],
            [[FxRates::CACHE_KEY => $fresh, FxRates::STAMP_KEY => (string) (self::NOW - 60)], 100.0, 'GBP', 'NOK', [], 1352.94, 0, 'a fresh table converts through the EUR pivot, rounded once, with no fetch'],
            [[FxRates::CACHE_KEY => $fresh, FxRates::STAMP_KEY => $due], 100.0, 'GBP', 'NOK', [$answer], 1200.0, 1, 'a table past its TTL is refreshed, and the fresh rate used'],
            [[FxRates::CACHE_KEY => self::table(self::NOW - FxRates::TTL - 1), FxRates::STAMP_KEY => $due], 100.0, 'GBP', 'NOK', [new HttpResponse(503, '')], 1352.94, 1, 'a failed refresh serves the last good table'],
            [[FxRates::CACHE_KEY => self::table(self::NOW - 90 * 86_400), FxRates::STAMP_KEY => $due], 100.0, 'GBP', 'NOK', [new HttpResponse(503, '')], 1352.94, 1, 'a months-old table is still served while refreshes fail, as the reference plugin does'],
            [[FxRates::STAMP_KEY => $due], 100.0, 'GBP', 'NOK', [new HttpResponse(503, '')], null, 1, 'no table ever fetched fails closed'],
            [[], 100.0, 'GBP', 'NOK', [$answer], 1200.0, 1, 'a missing table is fetched on demand'],
            [[FxRates::CACHE_KEY => $fresh, FxRates::STAMP_KEY => (string) (self::NOW - 60)], 100.0, 'GBP', 'SEK', [], null, 0, 'a currency the table lacks'],
        ];
        foreach ($cases as [$stored, $amount, $from, $to, $responses, $expected, $fetches, $description]) {
            [$fx, , $transport] = self::fx($stored, $responses);
            TinyAssert::same($expected, $fx->convert($amount, $from, $to), 'convert: ' . $description);
            TinyAssert::same($fetches, count($transport->calls), 'convert fetches: ' . $description);
        }
    }

    /** A cached table fetched at $fetchedAt: one EUR is 11.5 NOK and 0.85 GBP. */
    private static function table(int $fetchedAt): string
    {
        return '{"base":"EUR","as_of":"2026-10-25","fetched_at":' . $fetchedAt . ',"rates":{"EUR":1,"NOK":0.0869565,"GBP":1.1764706}}';
    }

    private static function testRefresh(): void
    {
        [$fx] = self::fx([FxRates::CACHE_KEY => self::TABLE], [new HttpResponse(200, '{"rates":{"SEK":0.0869,"NOK":"junk"}}')]);
        TinyAssert::true($fx->refreshIfDue(), 'refresh: a fresh table is stored');
        TinyAssert::same(['EUR' => 1.0, 'NOK' => 0.0869565, 'GBP' => 1.1764706, 'SEK' => 0.0869], $fx->table()['rates'] ?? null, 'refresh: merged over the cached table, junk rates never displace good ones');
        TinyAssert::same(self::NOW, $fx->table()['fetched_at'] ?? null, 'refresh: the table records when it was fetched');
        [$fx, $store] = self::fx([], [new HttpResponse(500, '')]);
        TinyAssert::true(!$fx->refreshIfDue(), 'refresh: a failed fetch stores nothing');
        TinyAssert::same((string) (self::NOW - FxRates::TTL + FxRates::BACKOFF), $store->values[FxRates::STAMP_KEY], 'refresh: a failure retries after the backoff, not a whole TTL');
    }

    private static function testQuote(): void
    {
        $ok = new HttpResponse(200, '{"currency":"NOK","buyer_fee_share":"25.5","total_fee":"40"}');
        $cases = [
            [['surcharge_type' => 'fixed_and_percentage'], 'NOK', [$ok], ['net' => 25.5, 'currency' => 'NOK'], '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":10,"cap":50}', 'the grid row, in the shop currency, needs no conversion'],
            [['surcharge_type' => 'fixed_and_percentage'], 'GBP', [new HttpResponse(200, '{"currency":"GBP","buyer_fee_share":"2"}')], ['net' => 2.0, 'currency' => 'GBP'], '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":0.74,"cap":3.7}', 'the fixed fee and cap converted at Two\'s rates'],
            [['surcharge_type' => 'fixed_and_percentage'], 'SEK', [], null, null, 'no FX rate fails closed, nothing sent'],
            [['surcharge_type' => 'fixed_and_percentage'], 'NOK', [new HttpResponse(503, '')], null, '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":10,"cap":50}', 'a failed quote is no quote'],
            [['surcharge_type' => 'fixed_and_percentage'], 'NOK', [new HttpResponse(200, '{"currency":"EUR","buyer_fee_share":"2"}')], null, '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":10,"cap":50}', 'an answer in another currency is refused, never converted again'],
            [['surcharge_type' => 'fixed_and_percentage'], 'NOK', [new HttpResponse(200, '{"currency":"NOK"}')], null, '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":10,"cap":50}', 'an answer with no fee is no quote'],
        ];
        foreach ($cases as [$settings, $currency, $responses, $expected, $share, $description]) {
            [$quotes, $transport, $settings] = self::quotes($settings, $responses);
            $quote = $quotes->quote(Surcharge::from($settings), PaymentTerms::from(['available_terms' => [30, 60]], $settings), 30, 1000.0, 'NO', $currency, 'NOK');
            TinyAssert::same($expected, $quote, 'quote: ' . $description);
            $body = json_decode((string) ($transport->calls[0]['body'] ?? 'null'), true);
            TinyAssert::same($share, $body === null ? null : json_encode($body['buyer_fee_share']), 'quote request: ' . $description);
            if ($body !== null) {
                TinyAssert::same(['currency' => $currency, 'gross_amount' => '1000.00', 'buyer_country_code' => 'NO', 'approved_on_recourse' => false, 'order_terms' => ['type' => 'NET_TERMS', 'duration_days' => 30]], array_diff_key($body, ['buyer_fee_share' => 1]), 'quote request shape: ' . $description);
                TinyAssert::same(IdempotencyKey::feeQuote(hash('sha256', (string) json_encode($body))), $transport->calls[0]['headers'][IdempotencyKey::HEADER] ?? null, 'quote key is the payload hash: ' . $description);
            }
        }
        [$quotes, $transport, $settings] = self::quotes(['surcharge_type' => 'percentage'], [new HttpResponse(200, '{"currency":"NOK","buyer_fee_share":"5"}')]);
        $terms = PaymentTerms::from(['available_terms' => [30]], $settings);
        $quotes->quote(Surcharge::from($settings), $terms, 30, 100.0, 'NO', 'NOK', 'NOK');
        $quotes->quote(Surcharge::from($settings), $terms, 30, 100.0, 'NO', 'NOK', 'NOK');
        TinyAssert::same(1, count($transport->calls), 'quote: the same request is asked once per request');
    }

    private static function testCharged(): void
    {
        $ok = new HttpResponse(200, '{"currency":"NOK","buyer_fee_share":"7"}');
        $cases = [
            [['surcharge_type' => 'percentage'], 60, 100.0, [$ok], ['applies' => true, 'days' => 60, 'net' => 7.0], 'the chosen term is quoted'],
            [['surcharge_type' => 'percentage'], 90, 100.0, [$ok], ['applies' => true, 'days' => 30, 'net' => 7.0], 'a term not offered falls back to the default'],
            [['surcharge_type' => 'none'], 30, 100.0, [], ['applies' => false, 'days' => 30, 'net' => null], 'no surcharge, no fee'],
            [['surcharge_type' => 'percentage'], 30, 0.0, [], ['applies' => false, 'days' => 30, 'net' => null], 'an empty cart is not quoted'],
            [['surcharge_type' => 'percentage', 'surcharge_grid' => '{"30":{"cap":"5"}}'], 30, 100.0, [], ['applies' => false, 'days' => 30, 'net' => null], 'a term that prices nothing is not quoted'],
            [['surcharge_type' => 'percentage'], 30, 100.0, [new HttpResponse(502, '')], ['applies' => true, 'days' => 30, 'net' => null], 'a fee that cannot be quoted'],
            [['surcharge_type' => 'tiered'], 30, 100.0, [], ['applies' => true, 'days' => 30, 'net' => null], 'an unknown stored method withholds, never charges nothing'],
        ];
        foreach ($cases as [$settings, $chosen, $gross, $responses, $expected, $description]) {
            [$quotes, , $settings] = self::quotes($settings, $responses);
            TinyAssert::same($expected, $quotes->charged(Surcharge::from($settings), PaymentTerms::from(['available_terms' => [30, 60]], $settings), $chosen, $gross, 'NO', 'NOK', 'NOK'), 'charged: ' . $description);
        }
    }

    private static function testCurrencyFailure(): void
    {
        $cases = [
            [['surcharge_type' => 'fixed'], 'SEK', true, 'a fixed fee with no rate into the cart currency fails every term'],
            [['surcharge_type' => 'percentage', 'surcharge_grid' => '{"30":{"percentage":"2"},"60":{"percentage":"3"}}'], 'SEK', false, 'a percentage-only grid needs no rate'],
            [['surcharge_type' => 'fixed'], 'NOK', false, 'the shop currency needs no rate'],
            [['surcharge_type' => 'fixed'], 'GBP', false, 'a rate Two holds'],
        ];
        foreach ($cases as [$settings, $currency, $fails, $description]) {
            [$quotes, , $settings] = self::quotes($settings, []);
            $failure = $quotes->currencyFailure(Surcharge::from($settings), PaymentTerms::from(['available_terms' => [30, 60]], $settings), $currency, 'NOK');
            TinyAssert::same($fails, $failure !== null, 'currency failure: ' . $description . ($failure === null ? '' : ' (' . $failure . ')'));
        }
    }

    /**
     * @param array<string, string> $stored
     * @param list<HttpResponse>    $responses
     * @return array{0: FxRates, 1: MemoryStore, 2: ScriptedTransport}
     */
    private static function fx(array $stored, array $responses): array
    {
        $store = new MemoryStore($stored + ['api_key' => 'k']);
        $settings = new SettingsProvider($store);
        $transport = new ScriptedTransport($responses);
        $client = new Client(new Environment(false), $settings, new CustomHeaders(), $transport, new RecordingLogger(), '1.0.0');

        return [new FxRates($client, $settings, new RecordingLogger(), static fn (): int => self::NOW), $store, $transport];
    }

    /**
     * @param array<string, string> $settings
     * @param list<HttpResponse>    $responses
     * @return array{0: FeeQuote, 1: ScriptedTransport, 2: SettingsProvider}
     */
    private static function quotes(array $settings, array $responses): array
    {
        $provider = new SettingsProvider(new MemoryStore($settings + ['api_key' => 'k', 'payment_terms' => '[30,60,90]', 'surcharge_grid' => self::GRID, FxRates::CACHE_KEY => self::TABLE, FxRates::STAMP_KEY => (string) self::NOW]));
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), $provider, new CustomHeaders(), $transport, $logger, '1.0.0');

        return [new FeeQuote($client, new FxRates($client, $provider, $logger, static fn (): int => self::NOW), $logger), $transport, $provider];
    }
}
