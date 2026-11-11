<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The API-key verdict heartbeat. The first table pins how one live answer is
 * categorised; the second pins the cache: what is stored, how old it is,
 * whether a live call may be made, and what the gates then read.
 */
final class ApiKeyVerdictSpec
{
    private const NOW = 1_800_000_000;

    public static function runAll(): void
    {
        self::testCategories();
        self::testCache();
        self::testCandidates();
    }

    /** check() asks about typed credentials and caches nothing; lastDefinitiveStatus() keeps the last ok or rejection through outages. */
    private static function testCandidates(): void
    {
        $checks = [
            ['key' => ' cand ', 'environment' => 'production', 'responses' => [new HttpResponse(200, '{"id":"m2"}')], 'status' => ApiKeyVerdict::OK, 'url' => 'https://api.two.inc' . ApiKeyVerdict::VERIFY_PATH, 'description' => 'a candidate Two accepts, in its own environment, trimmed'],
            ['key' => 'cand', 'environment' => 'sandbox', 'responses' => [new HttpResponse(403, '')], 'status' => ApiKeyVerdict::INVALID, 'url' => 'https://api.sandbox.two.inc' . ApiKeyVerdict::VERIFY_PATH, 'description' => 'a candidate Two rejects'],
            ['key' => '  ', 'environment' => 'sandbox', 'responses' => [], 'status' => ApiKeyVerdict::NOT_CONFIGURED, 'url' => null, 'description' => 'a blank candidate makes no call'],
        ];
        foreach ($checks as $case) {
            [$verdict, $transport, $store] = self::verdict(['api_key' => 'stored'], $case['responses']);
            $result = $verdict->check($case['key'], $case['environment']);
            TinyAssert::same($case['status'], $result['status'], 'check: ' . $case['description']);
            TinyAssert::same($case['url'], isset($transport->calls[0]) ? strstr($transport->calls[0]['url'], '?', true) : null, 'check: ' . $case['description'] . ' (endpoint)');
            TinyAssert::same(isset($transport->calls[0]) ? trim($case['key']) : null, $transport->calls[0]['headers']['X-API-Key'] ?? null, 'check: ' . $case['description'] . ' (key sent)');
            TinyAssert::same(null, $store->get(ApiKeyVerdict::CACHE_KEY), 'check: ' . $case['description'] . ' (nothing cached)');
        }

        $ok = new HttpResponse(200, '{"id":"m"}');
        $rejected = new HttpResponse(401, '');
        $down = HttpResponse::unreachable('timeout');
        $sequences = [
            ['answers' => [], 'rekey' => false, 'expected' => null, 'description' => 'never verified'],
            ['answers' => [$ok], 'rekey' => false, 'expected' => ApiKeyVerdict::OK, 'description' => 'an ok'],
            ['answers' => [$ok, $down], 'rekey' => false, 'expected' => ApiKeyVerdict::OK, 'description' => 'an outage after an ok keeps the ok'],
            ['answers' => [$ok, new HttpResponse(503, ''), new HttpResponse(200, '<html>')], 'rekey' => false, 'expected' => ApiKeyVerdict::OK, 'description' => 'a 5xx or a non-merchant 200 after an ok keeps the ok'],
            ['answers' => [$ok, $rejected], 'rekey' => false, 'expected' => ApiKeyVerdict::INVALID, 'description' => 'a rejection after an ok replaces it'],
            ['answers' => [$rejected, $down, $ok], 'rekey' => false, 'expected' => ApiKeyVerdict::OK, 'description' => 'an ok after a rejection replaces it'],
            ['answers' => [$down], 'rekey' => false, 'expected' => null, 'description' => 'an outage alone says nothing'],
            ['answers' => [$ok], 'rekey' => true, 'expected' => null, 'description' => 'an ok for other credentials says nothing'],
        ];
        foreach ($sequences as $case) {
            [$verdict, , $store] = self::verdict(['api_key' => 'k'], $case['answers']);
            foreach ($case['answers'] as $answer) {
                $verdict->verify();
            }
            if ($case['rekey']) {
                $store->values['api_key'] = 'other';
            }
            TinyAssert::same($case['expected'], $verdict->lastDefinitiveStatus(), 'lastDefinitiveStatus: ' . $case['description']);
        }
    }

    private static function testCategories(): void
    {
        $cases = [
            ['response' => new HttpResponse(200, '{"id":"m1","short_name":"shop"}'), 'status' => ApiKeyVerdict::OK, 'code' => 200, 'description' => 'a 200 naming a merchant is ok'],
            ['response' => new HttpResponse(401, '{}'), 'status' => ApiKeyVerdict::INVALID, 'code' => 401, 'description' => '401 is a rejected key'],
            ['response' => new HttpResponse(403, ''), 'status' => ApiKeyVerdict::INVALID, 'code' => 403, 'description' => '403 is a rejected key'],
            ['response' => new HttpResponse(503, 'down'), 'status' => ApiKeyVerdict::SERVICE_ERROR, 'code' => 503, 'description' => '5xx is a service error, not a bad key'],
            ['response' => HttpResponse::unreachable('timeout'), 'status' => ApiKeyVerdict::UNREACHABLE, 'code' => null, 'description' => 'no answer is unreachable, not a bad key'],
            ['response' => new HttpResponse(404, '{}'), 'status' => ApiKeyVerdict::ERROR, 'code' => 404, 'description' => 'any other status is an error'],
            ['response' => new HttpResponse(200, '<html>portal</html>'), 'status' => ApiKeyVerdict::ERROR, 'code' => 200, 'description' => 'a 200 that is not JSON is an error'],
            ['response' => new HttpResponse(200, '{"name":"x"}'), 'status' => ApiKeyVerdict::ERROR, 'code' => 200, 'description' => 'a 200 without a merchant id is an error'],
        ];
        foreach ($cases as $case) {
            [$verdict, $transport, $store] = self::verdict(['api_key' => 'k'], [$case['response']]);
            $result = $verdict->verify();
            TinyAssert::same([$case['status'], $case['code']], [$result['status'], $result['code']], $case['description']);
            TinyAssert::same('https://api.sandbox.two.inc/v1/merchant/verify_api_key?client=OC&client_v=1.0.0', $transport->calls[0]['url'], $case['description'] . ' (endpoint)');
            TinyAssert::same($case['status'], json_decode($store->values[ApiKeyVerdict::CACHE_KEY], true)['status'], $case['description'] . ' (recorded)');
        }

        [$verdict, $transport] = self::verdict([], []);
        TinyAssert::same(ApiKeyVerdict::NOT_CONFIGURED, $verdict->verify()['status'], 'no key is not configured, with no call');
        TinyAssert::same([], $transport->calls, 'no key makes no call');
    }

    private static function testCache(): void
    {
        $cases = [
            ['slot' => null, 'live' => true, 'responses' => [new HttpResponse(200, '{"id":"m"}')], 'status' => ApiKeyVerdict::OK, 'calls' => 1, 'unusable' => false, 'description' => 'a cold cache verifies live'],
            ['slot' => null, 'live' => false, 'responses' => [], 'status' => ApiKeyVerdict::VERIFYING, 'calls' => 0, 'unusable' => false, 'description' => 'a cold cache without a live check is verifying, never ok'],
            ['slot' => ['status' => 'ok', 'age' => 299], 'live' => true, 'responses' => [], 'status' => ApiKeyVerdict::OK, 'calls' => 0, 'unusable' => false, 'description' => 'a fresh ok is served from the cache'],
            ['slot' => ['status' => 'ok', 'age' => 300], 'live' => true, 'responses' => [new HttpResponse(401, '')], 'status' => ApiKeyVerdict::INVALID, 'calls' => 1, 'unusable' => true, 'description' => 'an ok expires after five minutes'],
            ['slot' => ['status' => 'service_error', 'age' => 59], 'live' => true, 'responses' => [], 'status' => ApiKeyVerdict::SERVICE_ERROR, 'calls' => 0, 'unusable' => false, 'description' => 'a fresh failure is served, and is not definitive'],
            ['slot' => ['status' => 'service_error', 'age' => 60], 'live' => true, 'responses' => [new HttpResponse(200, '{"id":"m"}')], 'status' => ApiKeyVerdict::OK, 'calls' => 1, 'unusable' => false, 'description' => 'a failure expires after a minute'],
            ['slot' => ['status' => 'ok', 'age' => 10, 'fingerprint' => 'other'], 'live' => false, 'responses' => [], 'status' => ApiKeyVerdict::VERIFYING, 'calls' => 0, 'unusable' => false, 'description' => 'a verdict for other credentials is not served'],
            ['slot' => ['status' => 'invalid_key', 'age' => 3600], 'live' => false, 'responses' => [], 'status' => ApiKeyVerdict::VERIFYING, 'calls' => 0, 'unusable' => true, 'description' => 'an expired rejection still refuses a submit'],
            ['slot' => ['status' => 'invalid_key', 'age' => 3600, 'fingerprint' => 'other'], 'live' => false, 'responses' => [], 'status' => ApiKeyVerdict::VERIFYING, 'calls' => 0, 'unusable' => false, 'description' => 'a rejection of the previous key never refuses the new one'],
            ['slot' => ['status' => 'unreachable', 'age' => 3600], 'live' => false, 'responses' => [], 'status' => ApiKeyVerdict::VERIFYING, 'calls' => 0, 'unusable' => false, 'description' => 'a transient failure never refuses a submit'],
            ['slot' => ['status' => 'ok', 'age' => 5, 'claim' => true], 'live' => true, 'responses' => [], 'status' => ApiKeyVerdict::OK, 'calls' => 0, 'unusable' => false, 'description' => 'inside a claim window the carried verdict is served, no second call'],
            ['slot' => ['status' => 'invalid_key', 'age' => 20, 'claim' => true], 'live' => false, 'responses' => [], 'status' => ApiKeyVerdict::VERIFYING, 'calls' => 0, 'unusable' => false, 'description' => 'an expired claim is not a verdict'],
        ];
        foreach ($cases as $case) {
            [$verdict, $transport, $store] = self::verdict(['api_key' => 'k'], $case['responses']);
            if ($case['slot'] !== null) {
                $settings = new SettingsProvider(new MemoryStore(['api_key' => 'k']));
                $store->values[ApiKeyVerdict::CACHE_KEY] = (string) json_encode([
                    'status' => $case['slot']['status'], 'code' => null,
                    'fingerprint' => $case['slot']['fingerprint'] ?? $settings->credentialFingerprint(),
                    'claim' => $case['slot']['claim'] ?? false,
                    'verified_on' => self::NOW - $case['slot']['age'], 'checked_on' => self::NOW - $case['slot']['age'],
                ]);
            }
            TinyAssert::same($case['status'], $verdict->status($case['live']), $case['description']);
            TinyAssert::same($case['calls'], count($transport->calls), $case['description'] . ' (live calls)');
            [$fresh] = self::verdict(['api_key' => 'k'], [], $store);
            TinyAssert::same($case['unusable'], $fresh->isDefinitelyUnusable(), $case['description'] . ' (definitely unusable, next request)');
        }

        self::testClaimCarriesARecentVerdict();
    }

    /** While the live call is out, a concurrent reader sees the previous verdict, not "verifying". */
    private static function testClaimCarriesARecentVerdict(): void
    {
        $store = new MemoryStore(['api_key' => 'k']);
        $settings = new SettingsProvider($store);
        $store->values[ApiKeyVerdict::CACHE_KEY] = (string) json_encode(['status' => 'ok', 'code' => 200, 'fingerprint' => $settings->credentialFingerprint(), 'claim' => false, 'verified_on' => self::NOW - 400, 'checked_on' => self::NOW - 400]);
        $seen = [];
        $transport = new class ($store, $seen) extends ScriptedTransport {
            /** @param list<string> $seen */
            public function __construct(private MemoryStore $watched, public array &$seen)
            {
                parent::__construct([new HttpResponse(200, '{"id":"m"}')]);
            }

            public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls, int $timeoutSeconds): HttpResponse
            {
                $this->seen[] = (string) $this->watched->get(ApiKeyVerdict::CACHE_KEY);

                return parent::send($method, $url, $headers, $body, $verifyTls, $timeoutSeconds);
            }
        };
        [$verdict] = self::verdict(['api_key' => 'k'], [], $store, $transport);
        $verdict->status();
        $claim = json_decode($seen[0], true);
        TinyAssert::same(['ok', true, self::NOW - 400], [$claim['status'], $claim['claim'], $claim['verified_on']], 'the claim carries the recent ok with its original age');
    }

    /**
     * @param array<string, string> $stored
     * @param list<HttpResponse>    $responses
     * @return array{0: ApiKeyVerdict, 1: ScriptedTransport, 2: MemoryStore}
     */
    private static function verdict(array $stored, array $responses, ?MemoryStore $store = null, ?ScriptedTransport $transport = null): array
    {
        $store ??= new MemoryStore($stored);
        $transport ??= new ScriptedTransport($responses);
        $settings = new SettingsProvider($store);
        $client = new Client(new Environment(false), $settings, new CustomHeaders(), $transport, new RecordingLogger(), '1.0.0');

        return [new ApiKeyVerdict($client, $settings, static fn (): int => self::NOW), $transport, $store];
    }
}
