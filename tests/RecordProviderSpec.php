<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The merchant-record cache: no expiry, never evicted by a failed refresh,
 * bound to the credentials it was fetched for. Each case is what is cached,
 * what Two answers to the key check and the record fetch, and what is served
 * afterwards.
 */
final class RecordProviderSpec
{
    private const NOW = 1_800_000_000;
    private const OLD = ['id' => 'm1', 'available_terms' => [30]];
    private const NEW = ['id' => 'm1', 'available_terms' => [14, 30]];

    public static function runAll(): void
    {
        $ok = new HttpResponse(200, '{"id":"m1"}');
        $record = new HttpResponse(200, (string) json_encode(self::NEW));
        $cases = [
            ['cached' => null, 'responses' => [$ok, $record], 'refreshed' => true, 'served' => self::NEW, 'calls' => 2, 'description' => 'a cold cache is filled'],
            ['cached' => self::OLD, 'responses' => [$ok, $record], 'refreshed' => true, 'served' => self::NEW, 'calls' => 2, 'description' => 'a refresh replaces the record'],
            ['cached' => self::OLD, 'responses' => [new HttpResponse(401, '')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 1, 'description' => 'a rejected key keeps the cached record'],
            ['cached' => self::OLD, 'responses' => [HttpResponse::unreachable('timeout')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 1, 'description' => 'no answer keeps the cached record'],
            ['cached' => self::OLD, 'responses' => [$ok, new HttpResponse(500, '')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 2, 'description' => 'a failed fetch keeps the cached record'],
            ['cached' => self::OLD, 'responses' => [$ok, new HttpResponse(200, '<html>portal</html>')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 2, 'description' => 'a 200 that is not JSON keeps the cached record'],
            ['cached' => self::OLD, 'responses' => [$ok, new HttpResponse(200, '{"unrelated":true}')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 2, 'description' => 'a 200 answering none of the record fields keeps the cached record'],
            ['cached' => null, 'responses' => [new HttpResponse(503, '')], 'refreshed' => false, 'served' => null, 'calls' => 1, 'description' => 'a cold cache stays empty on failure'],
        ];
        foreach ($cases as $case) {
            [$provider, $transport] = self::provider(['api_key' => 'k'], $case['responses'], $case['cached']);
            TinyAssert::same($case['refreshed'], $provider->refresh(), $case['description']);
            TinyAssert::same($case['served'], $provider->record(), $case['description'] . ' (served)');
            TinyAssert::same($case['calls'], count($transport->calls), $case['description'] . ' (calls)');
        }

        self::testRecordIsReadByTheKeysMerchant();
        self::testRecordIsBoundToCredentials();
        self::testScheduledRefresh();
        self::testTokenRefresh();
        self::testRefreshOnRead();
    }

    /**
     * The public refresh URL: each case is what is stored (the token, the last
     * run's age, the last logged rejection's age), the token presented, and
     * the outcome, how many calls reached Two and how many lines were logged.
     */
    private static function testTokenRefresh(): void
    {
        $token = str_repeat('ab', 20);
        $ok = [new HttpResponse(200, '{"id":"m1"}'), new HttpResponse(200, '{"id":"m1"}')];
        $cases = [
            [[], $token, [], RecordProvider::CRON_REJECTED, 0, 1, 'no token stored: refused, and a request never mints one'],
            [['cron_token' => $token], '', [], RecordProvider::CRON_REJECTED, 0, 1, 'no token presented'],
            [['cron_token' => $token], 'ab', [], RecordProvider::CRON_REJECTED, 0, 1, 'a prefix of the token'],
            [['cron_token' => $token], 'k', [], RecordProvider::CRON_REJECTED, 0, 1, 'the API key is not the token'],
            [['cron_token' => $token, 'cron_reject_logged_at' => (string) (self::NOW - 60)], 'x', [], RecordProvider::CRON_REJECTED, 0, 0, 'a rejection within the hour is not logged again'],
            [['cron_token' => $token, 'cron_reject_logged_at' => (string) (self::NOW - 3_600)], 'x', [], RecordProvider::CRON_REJECTED, 0, 1, 'a rejection an hour on is logged'],
            [['cron_token' => $token], ' ' . $token . ' ', $ok, RecordProvider::CRON_REFRESHED, 2, 0, 'the token, trimmed: refreshed'],
            [['cron_token' => $token, 'cron_last_run' => (string) (self::NOW - 60)], $token, $ok, RecordProvider::CRON_THROTTLED, 0, 0, 'a second run within 15 minutes is throttled'],
            [['cron_token' => $token, 'cron_last_run' => (string) (self::NOW - 900)], $token, $ok, RecordProvider::CRON_REFRESHED, 2, 0, 'a run 15 minutes on refreshes'],
            [['cron_token' => $token, 'api_key' => ''], $token, [], RecordProvider::CRON_UNCONFIGURED, 0, 0, 'no API key: nothing to refresh'],
        ];
        foreach ($cases as [$stored, $presented, $responses, $result, $calls, $logged, $description]) {
            [$provider, $transport, $store, $logger] = self::provider($stored + ['api_key' => 'k'], $responses, null);
            TinyAssert::same([$result, $calls, $logged], [$provider->runTokenRefresh($presented), count($transport->calls), count(array_filter($logger->lines, static fn (array $line): bool => $line['level'] === 'error'))], 'token refresh: ' . $description);
            TinyAssert::same($stored['cron_token'] ?? null, $store->get(RecordProvider::CRON_TOKEN_KEY), 'token refresh: ' . $description . ' (token unchanged)');
        }

        [$provider, , $store] = self::provider([], [], null);
        $minted = $provider->cronToken();
        TinyAssert::same([1, 40, $minted], [preg_match('/^[0-9a-f]{40}$/', $minted), strlen($minted), $provider->cronToken()], 'the admin mints a 40-hex token once, then reads it back');
        TinyAssert::same($minted, $store->get(RecordProvider::CRON_TOKEN_KEY), 'the minted token is stored');
    }

    /**
     * The read-time stand-in for a schedule that is not running. Each case is
     * the cached record's age (null for none), the last attempt (its age and
     * whether it was for these credentials), and what a read then does.
     */
    private static function testRefreshOnRead(): void
    {
        $ok = new HttpResponse(200, '{"id":"m1"}');
        $record = new HttpResponse(200, (string) json_encode(self::NEW));
        $stale = RecordProvider::STALE_AFTER;
        $cases = [
            ['key' => '', 'age' => null, 'attempt' => null, 'responses' => [], 'due' => false, 'timeout' => null, 'served' => null, 'description' => 'no key: nothing to fetch'],
            ['key' => 'k', 'age' => null, 'attempt' => null, 'responses' => [$ok, $record], 'due' => true, 'timeout' => 10, 'served' => self::NEW, 'description' => 'a cold cache is fetched on read'],
            ['key' => 'k', 'age' => null, 'attempt' => [299, true], 'responses' => [], 'due' => false, 'timeout' => null, 'served' => null, 'description' => 'a cold cache waits five minutes after an attempt'],
            ['key' => 'k', 'age' => null, 'attempt' => [300, true], 'responses' => [$ok, $record], 'due' => true, 'timeout' => 10, 'served' => self::NEW, 'description' => 'a cold cache retries after five minutes'],
            ['key' => 'k', 'age' => null, 'attempt' => [10, false], 'responses' => [$ok, $record], 'due' => true, 'timeout' => 10, 'served' => self::NEW, 'description' => 'an attempt for other credentials does not hold back these'],
            ['key' => 'k', 'age' => null, 'attempt' => null, 'responses' => [new HttpResponse(503, '')], 'due' => true, 'timeout' => 10, 'served' => null, 'description' => 'a failed cold fetch serves nothing'],
            ['key' => 'k', 'age' => $stale - 1, 'attempt' => null, 'responses' => [], 'due' => false, 'timeout' => null, 'served' => self::OLD, 'description' => 'a record under 26 hours old is served as it is'],
            ['key' => 'k', 'age' => $stale, 'attempt' => null, 'responses' => [$ok, $record], 'due' => true, 'timeout' => 2, 'served' => self::NEW, 'description' => 'a record 26 hours old is refetched, with the short timeout'],
            ['key' => 'k', 'age' => $stale * 3, 'attempt' => [3_599, true], 'responses' => [], 'due' => false, 'timeout' => null, 'served' => self::OLD, 'description' => 'a stale record waits an hour after an attempt'],
            ['key' => 'k', 'age' => $stale * 3, 'attempt' => [3_600, true], 'responses' => [$ok, $record], 'due' => true, 'timeout' => 2, 'served' => self::NEW, 'description' => 'a stale record retries hourly'],
            ['key' => 'k', 'age' => $stale, 'attempt' => null, 'responses' => [HttpResponse::unreachable('timeout')], 'due' => true, 'timeout' => 2, 'served' => self::OLD, 'description' => 'a failed stale refetch keeps the record'],
        ];
        foreach ($cases as $case) {
            [$provider, $transport, $store, $logger] = self::provider(['api_key' => $case['key']], $case['responses'], $case['age'] === null ? null : self::OLD, $case['age'] ?? 0);
            $settings = new SettingsProvider($store);
            if ($case['attempt'] !== null) {
                $fingerprint = $case['attempt'][1] ? $settings->credentialFingerprint() : 'other';
                $store->values[RecordProvider::ATTEMPT_KEY] = (string) json_encode(['fingerprint' => $fingerprint, 'at' => self::NOW - $case['attempt'][0]]);
            }
            $label = 'read: ' . $case['description'];
            TinyAssert::same($case['due'], $provider->refreshIfDue(), $label);
            TinyAssert::same($case['timeout'], $transport->calls[0]['timeout'] ?? null, $label . ' (timeout)');
            $calls = count($transport->calls);
            // The attempt was stamped before the call, so the next read waits.
            TinyAssert::same($case['served'], $provider->record(), $label . ' (served)');
            TinyAssert::same($calls, count($transport->calls), $label . ' (a second read makes no call)');
            $warned = str_contains($logger->dump(), 'refreshing on read');
            TinyAssert::same($case['due'] && $case['age'] !== null, $warned, $label . ' (stale refetch logged)');
        }
    }

    private static function testRecordIsReadByTheKeysMerchant(): void
    {
        [$provider, $transport] = self::provider(['api_key' => 'k'], [new HttpResponse(200, '{"id":"m 1/x"}'), new HttpResponse(200, '{"id":"m 1/x"}')], null);
        $provider->refresh();
        TinyAssert::same('https://api.sandbox.two.inc/v1/merchant/m%201%2Fx?client=OC&client_v=1.0.0', $transport->calls[1]['url'], 'the record is fetched by the id the key resolved, encoded');
    }

    private static function testRecordIsBoundToCredentials(): void
    {
        [$provider, , $store] = self::provider(['api_key' => 'k'], [], self::OLD);
        $store->values['api_key'] = 'another';
        TinyAssert::same(null, $provider->record(), 'a record fetched for another key is not served');
        $store->values['api_key'] = 'k';
        TinyAssert::same(self::OLD, $provider->record(), 'nor is it dropped: the original key still reads it');
        $store->values['environment'] = 'production';
        TinyAssert::same(null, $provider->record(), 'a record fetched in another environment is not served');
    }

    private static function testScheduledRefresh(): void
    {
        $cases = [
            ['stored' => [], 'responses' => [], 'result' => RecordProvider::CRON_UNCONFIGURED, 'stamped' => null, 'description' => 'no key: nothing to refresh, no stamp'],
            ['stored' => ['api_key' => 'k'], 'responses' => [new HttpResponse(200, '{"id":"m1"}'), new HttpResponse(200, '{"id":"m1"}')], 'result' => RecordProvider::CRON_REFRESHED, 'stamped' => (string) self::NOW, 'description' => 'a run that refreshes is stamped'],
            ['stored' => ['api_key' => 'k'], 'responses' => [new HttpResponse(503, '')], 'result' => RecordProvider::CRON_FAILED, 'stamped' => (string) self::NOW, 'description' => 'a run that fails is stamped too (the schedule is running)'],
        ];
        foreach ($cases as $case) {
            [$provider, , $store] = self::provider($case['stored'], $case['responses'], null);
            TinyAssert::same($case['result'], $provider->runScheduledRefresh(), 'cron: ' . $case['description']);
            TinyAssert::same($case['stamped'], $store->get(RecordProvider::CRON_LAST_RUN_KEY), 'cron: ' . $case['description'] . ' (stamp)');
        }
    }

    /**
     * @param array<string, string>     $stored
     * @param list<HttpResponse>        $responses
     * @param array<string, mixed>|null $cached
     * @return array{0: RecordProvider, 1: ScriptedTransport, 2: MemoryStore, 3: RecordingLogger}
     */
    private static function provider(array $stored, array $responses, ?array $cached, int $age = 3_600): array
    {
        $store = new MemoryStore($stored);
        $settings = new SettingsProvider($store);
        if ($cached !== null) {
            $store->values[RecordProvider::CACHE_KEY] = (string) json_encode(['fingerprint' => $settings->credentialFingerprint(), 'fetched_at' => self::NOW - $age, 'record' => $cached]);
        }
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), $settings, new CustomHeaders(), $transport, $logger, '1.0.0');
        $clock = static fn (): int => self::NOW;

        return [new RecordProvider($client, $settings, new ApiKeyVerdict($client, $settings, $clock), $logger, $clock), $transport, $store, $logger];
    }
}
