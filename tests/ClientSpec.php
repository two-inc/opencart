<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\SettingsOverlay;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The one outbound client. Each case is the stored settings, one call, and
 * what reached the transport; every case also proves the API key never
 * reaches the log or the URL, whatever the outcome.
 */
final class ClientSpec
{
    private const KEY = 'secret-key-0123456789';
    private const VERSION = '1.2.3+abc';

    /** @var list<array{stored: array<string, string>, flag: bool, call: array{0: string, 1: string, 2?: array<string, mixed>|null, 3?: int, 4?: bool, 5?: array<string, string>}, response: HttpResponse|null, url: string, headers: array<string, string>, body: string|null, tls: bool, timeout: int, errors: int, description: string}> */
    private static array $cases = [];

    public static function runAll(): void
    {
        $base = ['api_key' => self::KEY, 'environment' => 'sandbox'];
        $std = ['Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json'];
        $query = '?client=OC&client_v=1.2.3%2Babc';
        self::$cases = [
            ['stored' => $base, 'flag' => false, 'call' => ['GET', '/v1/merchant/m1'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/merchant/m1' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'GET on sandbox with the key, client params and TLS on'],
            ['stored' => ['api_key' => self::KEY, 'environment' => 'production'], 'flag' => false, 'call' => ['post', '/v1/order', ['a' => 'b/c', 'n' => "\u{e9}"], 30, true, ['Idempotency-Key' => 'create_1_abc']], 'response' => null, 'url' => 'https://api.two.inc/v1/order' . $query, 'headers' => $std + ['Idempotency-Key' => 'create_1_abc', 'X-API-Key' => self::KEY], 'body' => '{"a":"b/c","n":"' . "\u{e9}" . '"}', 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'POST on production sends the body as JSON'],
            ['stored' => ['api_key' => self::KEY, 'environment' => 'staging'], 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => null, 'url' => 'https://api.staging.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'a stored staging value is honoured (the health row flags it)'],
            ['stored' => ['api_key' => self::KEY, 'environment' => 'garbage'], 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'an unknown environment calls sandbox, never production'],
            ['stored' => $base, 'flag' => false, 'call' => ['GET', '/v1/x?page=2'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x?page=2&client=OC&client_v=1.2.3%2Babc', 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'client params join an existing query'],
            ['stored' => $base + ['disable_ssl_verify' => '1'], 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => false, 'timeout' => 30, 'errors' => 0, 'description' => 'the bypass switches TLS verification off'],
            ['stored' => $base, 'flag' => false, 'call' => ['GET', '/v1/x', null, 10], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 10, 'errors' => 0, 'description' => 'a caller timeout is passed through'],
            ['stored' => $base, 'flag' => false, 'call' => ['GET', '/v1/x', null, 0], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 1, 'errors' => 0, 'description' => 'a timeout below one second is raised to one'],
            ['stored' => $base, 'flag' => false, 'call' => ['POST', '/v1/order_intent', ['x' => 1], 30, false, ['Idempotency-Key' => 'intent_1']], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/order_intent' . $query, 'headers' => $std + ['Idempotency-Key' => 'intent_1'], 'body' => '{"x":1}', 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'an unauthenticated call never carries the key'],
            ['stored' => ['environment' => 'sandbox'], 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std, 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'no key stored, no key header'],
            ['stored' => $base + ['vendor_name' => ' North site '], 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY, 'X-Vendor-Name' => 'North site'], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'a vendor name goes beside the key, trimmed'],
            ['stored' => $base + ['vendor_name' => '  '], 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'a blank vendor name sends no header'],
            ['stored' => $base + ['vendor_name' => 'North site'], 'flag' => false, 'call' => ['POST', '/v1/order_intent', ['x' => 1], 30, false, ['Idempotency-Key' => 'intent_1']], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/order_intent' . $query, 'headers' => $std + ['Idempotency-Key' => 'intent_1'], 'body' => '{"x":1}', 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'an unauthenticated call carries no vendor name'],
            ['stored' => ['environment' => 'sandbox', 'vendor_name' => 'North site'], 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std, 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'no key stored, no vendor name either'],
            ['stored' => $base + ['custom_headers' => '[{"name":"X-Firewall","value":"fw-secret"},{"name":"X-API-Key","value":"forged"},{"name":"X-Bad","value":"a\r\nb"}]'], 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => ['X-Firewall' => 'fw-secret'] + $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'valid custom headers are sent, forged and broken rows are not'],
            ['stored' => $base + ['custom_headers' => '[{"name":"X-Firewall","value":"fw-secret"}]'], 'flag' => false, 'call' => ['POST', '/v1/order_intent', [], 30, false, ['Idempotency-Key' => 'intent_1']], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/order_intent' . $query, 'headers' => ['X-Firewall' => 'fw-secret'] + $std + ['Idempotency-Key' => 'intent_1'], 'body' => '{}', 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'custom headers reach unauthenticated calls too (they clear the firewall); an empty body goes as {}'],
            ['stored' => $base, 'flag' => false, 'call' => ['POST', '/v1/x', [], 30, true, ['Idempotency-Key' => 'fulfil_1']], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['Idempotency-Key' => 'fulfil_1', 'X-API-Key' => self::KEY], 'body' => '{}', 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'a per-request header (the idempotency key) is sent, and an empty body goes as an object'],
            ['stored' => $base, 'flag' => false, 'call' => ['GET', '/v1/x', null, 30, true, ['Accept' => 'application/pdf', 'X-API-Key' => 'forged']], 'response' => null, 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/pdf', 'X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 0, 'description' => 'a per-request header overrides Accept but never the API key'],
            ['stored' => $base, 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => HttpResponse::unreachable('Could not resolve host'), 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 1, 'description' => 'a transport failure is returned and logged as an error'],
            ['stored' => $base, 'flag' => false, 'call' => ['GET', '/v1/x'], 'response' => new HttpResponse(401, '{"error":"' . self::KEY . '"}'), 'url' => 'https://api.sandbox.two.inc/v1/x' . $query, 'headers' => $std + ['X-API-Key' => self::KEY], 'body' => null, 'tls' => true, 'timeout' => 30, 'errors' => 1, 'description' => 'an HTTP error is logged as an error line'],
        ];

        foreach (self::$cases as $case) {
            self::runCase($case);
        }

        self::testBadCallsAreRefused();
        self::testScrubFloor();
        self::testWithCredentials();
    }

    /** @param array{stored: array<string, string>, flag: bool, call: array<int, mixed>, response: HttpResponse|null, url: string, headers: array<string, string>, body: string|null, tls: bool, timeout: int, errors: int, description: string} $case */
    private static function runCase(array $case): void
    {
        $transport = new ScriptedTransport([$case['response'] ?? new HttpResponse(200, '{}')]);
        $logger = new RecordingLogger();
        $client = self::client($case['stored'], $transport, $logger, $case['flag']);
        $response = $client->request(...$case['call']);
        $label = $case['description'];

        TinyAssert::same(1, count($transport->calls), $label . ' (one call)');
        $sent = $transport->calls[0];
        TinyAssert::same(strtoupper((string) $case['call'][0]), $sent['method'], $label . ' (method)');
        TinyAssert::same($case['url'], $sent['url'], $label . ' (url)');
        TinyAssert::same($case['headers'], $sent['headers'], $label . ' (headers)');
        TinyAssert::same($case['body'], $sent['body'], $label . ' (body)');
        TinyAssert::same($case['tls'], $sent['verifyTls'], $label . ' (TLS)');
        TinyAssert::same($case['timeout'], $sent['timeout'], $label . ' (timeout)');
        TinyAssert::same($case['response'] ?? $response, $response, $label . ' (response returned as received)');

        $errors = array_filter($logger->lines, static fn (array $line): bool => $line['level'] === 'error');
        TinyAssert::same($case['errors'], count($errors), $label . ' (error lines)');
        TinyAssert::same(1, count($logger->lines) - count($errors), $label . ' (one debug line)');
        // The 401 case echoes the key back in the response body: it is scrubbed there too.
        TinyAssert::same(false, str_contains($logger->dump(), self::KEY), $label . ' (key never logged)');
        TinyAssert::same(false, str_contains($logger->dump(), 'fw-secret'), $label . ' (custom header values never logged)');
        TinyAssert::same(false, str_contains($sent['url'], self::KEY), $label . ' (key never in the URL)');
    }

    private static function testBadCallsAreRefused(): void
    {
        $bad = [
            ['call' => ['TRACE', '/v1/x'], 'description' => 'an unsupported method'],
            ['call' => ['GET', 'v1/x'], 'description' => 'a path without a leading slash'],
            ['call' => ['GET', 'https://evil.example/v1/x'], 'description' => 'an absolute URL'],
            ['call' => ['GET', "/v1/x\r\nHost: evil"], 'description' => 'a path with CR LF'],
            ['call' => ['GET', '/v1/x#frag'], 'description' => 'a path with a fragment'],
            ['call' => ['POST', '/v1/order', []], 'description' => 'a POST without an idempotency key'],
            ['call' => ['PUT', '/v1/order/x', [], 30, true, ['Idempotency-Key' => '']], 'description' => 'a PUT with an empty idempotency key'],
            ['call' => ['DELETE', '/v1/x'], 'description' => 'a DELETE without an idempotency key'],
        ];
        foreach ($bad as $case) {
            $transport = new ScriptedTransport();
            $client = self::client(['api_key' => self::KEY], $transport, new RecordingLogger(), false);
            TinyAssert::throws(static fn () => $client->request(...$case['call']), InvalidArgumentException::class, 'refuses ' . $case['description']);
            TinyAssert::same([], $transport->calls, 'refuses ' . $case['description'] . ' before the wire');
        }
    }

    /**
     * A custom header value is scrubbed from log text only when it is at
     * least MIN_SCRUB_LENGTH long; the header itself is always redacted by name.
     */
    private static function testScrubFloor(): void
    {
        $cases = [
            ['value' => 'ab', 'scrubbed' => false, 'description' => 'a two-character value is left in the text'],
            ['value' => 'abc', 'scrubbed' => false, 'description' => 'a three-character value is left in the text'],
            ['value' => 'abcd', 'scrubbed' => true, 'description' => 'a four-character value is scrubbed'],
            ['value' => 'fw-secret', 'scrubbed' => true, 'description' => 'a longer value is scrubbed'],
        ];
        foreach ($cases as $case) {
            $logger = new RecordingLogger();
            $stored = ['api_key' => self::KEY, 'custom_headers' => (string) json_encode([['name' => 'X-Short', 'value' => $case['value']]])];
            $echo = 'status ' . $case['value'] . ' accepted';
            self::client($stored, new ScriptedTransport([new HttpResponse(200, $echo)]), $logger, false)->request('GET', '/v1/x');
            $context = $logger->lines[0]['context'];
            TinyAssert::same('[REDACTED]', $context['request']['headers']['X-Short'], $case['description'] . ' (header redacted by name)');
            TinyAssert::same($case['scrubbed'] ? 'status [REDACTED] accepted' : $echo, $context['response']['body'], $case['description']);
        }
    }

    /** Candidate credentials reach the wire; the stored ones and the store are untouched. */
    private static function testWithCredentials(): void
    {
        $transport = new ScriptedTransport();
        $store = new MemoryStore(['api_key' => self::KEY, 'environment' => 'sandbox', 'disable_ssl_verify' => '1']);
        $client = new Client(new Environment(false), new SettingsProvider($store), new CustomHeaders(), $transport, new RecordingLogger(), self::VERSION);
        $client->withCredentials('candidate-key', 'production')->request('GET', '/v1/x');
        $sent = $transport->calls[0];
        TinyAssert::same('candidate-key', $sent['headers']['X-API-Key'], 'withCredentials sends the candidate key');
        TinyAssert::same(true, str_starts_with($sent['url'], 'https://api.two.inc/v1/x?'), 'withCredentials calls the candidate environment');
        TinyAssert::same(false, $sent['verifyTls'], 'withCredentials keeps every other stored setting');
        TinyAssert::same(['api_key' => self::KEY, 'environment' => 'sandbox', 'disable_ssl_verify' => '1'], $store->values, 'withCredentials leaves the store as it was');
        $overlay = new SettingsOverlay($store, ['api_key' => 'x']);
        TinyAssert::throws(static fn () => $overlay->setMany(['api_key' => 'y']), LogicException::class, 'the overlay refuses writes');
    }

    /** @param array<string, string> $stored */
    private static function client(array $stored, ScriptedTransport $transport, RecordingLogger $logger, bool $flag): Client
    {
        return new Client(new Environment($flag), new SettingsProvider(new MemoryStore($stored)), new CustomHeaders(), $transport, $logger, self::VERSION);
    }
}
