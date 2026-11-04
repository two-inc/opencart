<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\MerchantFeeRates;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The merchant fee beside each term on the Payment terms tab. The first
 * table is one fetch: whether a good set was stored earlier, Two's answer,
 * and what the page is told. The rest pin the cooldown and the identity a
 * stored set belongs to.
 */
final class MerchantFeeRatesSpec
{
    private const NOW = 1_800_000_000;
    private const GOOD = '{"currency":"nok","rates":[{"net_terms":30,"percentage_fee":"1.5","fixed_fee":"2"},{"net_terms":"60","percentage_fee":2,"fixed_fee":0},{"net_terms":0,"percentage_fee":9},"junk"]}';
    private const FEES = ['30' => ['percentage' => 1.5, 'fixed' => 2.0], '60' => ['percentage' => 2.0, 'fixed' => 0.0]];
    private const OLD = ['30' => ['percentage' => 1.0, 'fixed' => 0.0]];

    public static function runAll(): void
    {
        $refused = new HttpResponse(400, '{"error_message":"buyer_country_code is not supported"}');
        // [stored earlier, Two's answer (null: no call), expected answer, description]
        $cases = [
            [false, new HttpResponse(200, self::GOOD), ['success' => true, 'currency' => 'NOK', 'fees' => self::FEES, 'fetched_at' => self::NOW, 'stale' => false], 'a good answer is drawn and stored, junk rows skipped'],
            [false, new HttpResponse(503, ''), ['success' => false, 'error' => MerchantFeeRates::UPSTREAM], 'a 5xx with nothing stored is a failure'],
            [true, new HttpResponse(503, ''), ['success' => true, 'currency' => 'EUR', 'fees' => self::OLD, 'fetched_at' => self::NOW - 3600, 'stale' => true], 'a 5xx serves the last set, marked stale'],
            [false, HttpResponse::unreachable('timeout'), ['success' => false, 'error' => MerchantFeeRates::UPSTREAM], 'no answer is a failure'],
            [false, new HttpResponse(429, ''), ['success' => false, 'error' => MerchantFeeRates::UPSTREAM], 'a 429 is transient, not a refusal'],
            [false, $refused, ['success' => false, 'error' => MerchantFeeRates::REJECTED, 'http_status' => 400, 'message' => 'buyer_country_code is not supported'], 'a refusal says why'],
            [true, $refused, ['success' => true, 'currency' => 'EUR', 'fees' => self::OLD, 'fetched_at' => self::NOW - 3600, 'stale' => true, 'error' => MerchantFeeRates::REJECTED, 'http_status' => 400, 'message' => 'buyer_country_code is not supported'], 'a refusal with a stale set still says why'],
            [false, new HttpResponse(200, '{"currency":"NOK"}'), ['success' => false, 'error' => MerchantFeeRates::UPSTREAM], 'a 200 without rates is a failure'],
            [true, new HttpResponse(200, '{"rates":[{"net_terms":30,"percentage_fee":1}]}'), ['success' => true, 'currency' => 'EUR', 'fees' => self::OLD, 'fetched_at' => self::NOW - 3600, 'stale' => true], 'a 200 with no currency never displaces the stored set'],
            [false, new HttpResponse(200, '{"currency":"NOK","rates":[]}'), ['success' => false, 'error' => MerchantFeeRates::UPSTREAM], 'a 200 pricing no term is a failure'],
        ];
        foreach ($cases as [$earlier, $answer, $expected, $description]) {
            [$rates, $store, $transport] = self::rates([$answer]);
            if ($earlier) {
                self::storeOld($rates, $store);
            }
            TinyAssert::same($expected, $rates->fetch([60, '30', 30, 'x', 0], 'no'), $description);
            TinyAssert::same(1, count($transport->calls), $description . ' (one call)');
            $sent = $transport->calls[0];
            TinyAssert::true(str_starts_with($sent['url'], 'https://api.sandbox.two.inc/pricing/v1/merchant/rates?'), $description . ' (endpoint)');
            TinyAssert::same('{"buyer_country_code":"NO","recourse_pricing":false,"net_terms":[30,60]}', $sent['body'], $description . ' (payload)');
            TinyAssert::true(str_starts_with((string) ($sent['headers']['Idempotency-Key'] ?? ''), 'merchant_rates_'), $description . ' (idempotency key)');
        }

        self::testNothingToAsk();
        self::testCooldown();
        self::testIdentity();
    }

    private static function testNothingToAsk(): void
    {
        $cases = [
            [['api_key' => 'k'], ['x', 0, -30], MerchantFeeRates::NO_TERMS, 'no usable term asks nothing'],
            [[], [30], MerchantFeeRates::NOT_CONFIGURED, 'no key saved asks nothing'],
        ];
        foreach ($cases as [$stored, $days, $error, $description]) {
            [$rates, , $transport] = self::rates([], $stored);
            TinyAssert::same(['success' => false, 'error' => $error], $rates->fetch($days, 'NO'), $description);
            TinyAssert::same([], $transport->calls, $description . ' (no call)');
        }
    }

    /** A failure holds off further calls for a minute, still saying why; then Two is asked again. */
    private static function testCooldown(): void
    {
        $now = self::NOW;
        $refused = new HttpResponse(400, '{"message":"no"}');
        [$rates, , $transport] = self::rates([$refused, new HttpResponse(200, self::GOOD)], ['api_key' => 'k'], $now);
        $first = $rates->fetch([30], 'NO');
        $now += MerchantFeeRates::COOLDOWN - 1;
        $cooling = $rates->fetch([30], 'NO');
        TinyAssert::same($first, $cooling, 'cooldown: a view while cooling gives the same refusal');
        TinyAssert::same(1, count($transport->calls), 'cooldown: and asks nothing');
        $now += 1;
        TinyAssert::same(true, $rates->fetch([30], 'NO')['success'], 'cooldown: once over, Two is asked again');
        TinyAssert::same(2, count($transport->calls), 'cooldown: one more call');
    }

    /** A stored set is served only for the key, environment, country and terms it was fetched for. */
    private static function testIdentity(): void
    {
        $cases = [
            [['api_key' => 'other'], 'NO', [30, 60], 'another key'],
            [['environment' => 'production'], 'NO', [30, 60], 'another environment'],
            [[], 'SE', [30, 60], 'another buyer country'],
            [[], 'NO', [30], 'another term set'],
        ];
        foreach ($cases as [$change, $country, $terms, $description]) {
            [$rates, $store] = self::rates([new HttpResponse(503, '')]);
            self::storeOld($rates, $store);
            $store->values = $change + $store->values;
            TinyAssert::same(['success' => false, 'error' => MerchantFeeRates::UPSTREAM], $rates->fetch($terms, $country), 'identity: no stale set for ' . $description);
        }
    }

    /** Stores an EUR set an hour old for the default identity (key k, sandbox, NO, 30 and 60 days). */
    private static function storeOld(MerchantFeeRates $rates, MemoryStore $store): void
    {
        $then = self::NOW - 3600;
        [$seed, $seedStore] = self::rates([new HttpResponse(200, '{"currency":"EUR","rates":[{"net_terms":30,"percentage_fee":1,"fixed_fee":0}]}')], $store->values, $then);
        $seed->fetch([30, 60], 'NO');
        $store->values = $seedStore->values;
    }

    /**
     * @param list<HttpResponse>    $answers
     * @param array<string, string> $stored
     * @return array{0: MerchantFeeRates, 1: MemoryStore, 2: ScriptedTransport}
     */
    private static function rates(array $answers, array $stored = ['api_key' => 'k'], ?int &$now = null): array
    {
        $now ??= self::NOW;
        $store = new MemoryStore($stored);
        $settings = new SettingsProvider($store);
        $transport = new ScriptedTransport($answers);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), $settings, new CustomHeaders(), $transport, $logger, '1.0.0');
        $clock = static function () use (&$now): int {
            return $now;
        };

        return [new MerchantFeeRates($client, $settings, $logger, $clock), $store, $transport];
    }
}
