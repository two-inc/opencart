<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\BuyerFields;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CompanyLookup;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\OrderLock;
use Opencart\System\Library\Extension\Two\Core\PostcodeFormat;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\RateLimiter;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;

/**
 * Checkout capture (Build Guide sections 1.1, 6.1 and 6.3): the server-side
 * order intent, the optional buyer fields, the company search relay and the
 * sole-trader token mint, against a scripted Two.
 */
final class CheckoutCaptureSpec
{
    private const SESSION = '0123456789abcdef0123456789abcdef';

    public static function runAll(): void
    {
        self::testIntent();
        self::testAfterIntent();
        self::testOnePlacementAtATime();
        self::testOptionalFields();
        self::testSearch();
        self::testSoleTrader();
        self::testKeys();
        self::testRateLimits();
        self::testDisplayLabel();
        self::testSettings();
        self::testSave();
    }

    private static function testIntent(): void
    {
        $cases = [
            [[self::json(200, ['approved' => true])], [], self::SESSION, OrderFlow::APPROVED, [], 1, 'approved'],
            [[self::json(200, ['approved' => false])], [], self::SESSION, OrderFlow::DECLINED, [], 1, 'declined'],
            [[HttpResponse::unreachable('timeout')], [], self::SESSION, OrderFlow::UNDECIDED, [OrderFlow::INTENT_CONNECTION], 1, 'no answer: the connection sentence'],
            [[self::json(500, [])], [], self::SESSION, OrderFlow::UNDECIDED, [OrderFlow::INTENT_UNAVAILABLE], 1, 'a 5xx: the provider-unavailable sentence'],
            [[self::json(422, ['error_code' => 'SCHEMA_ERROR'])], [], self::SESSION, OrderFlow::UNDECIDED, [OrderFlow::INTENT_UNPROCESSABLE], 1, 'a 4xx: the unable-to-process sentence, never Two\'s text'],
            [[self::json(200, ['approved' => 'yes'])], [], self::SESSION, OrderFlow::UNDECIDED, [OrderFlow::INTENT_UNPROCESSABLE], 1, 'an answer without a boolean decision: unable to process'],
            [[], [], '', OrderFlow::UNDECIDED, [OrderFlow::INTENT_UNPROCESSABLE], 0, 'no session: nothing sent, so no key is ever shared'],
            [[], [], 'not-a-hash', OrderFlow::UNDECIDED, [OrderFlow::INTENT_UNPROCESSABLE], 0, 'a session value that is not a hash: nothing sent'],
            [[], ['company' => ['company_name' => 'Acme AS', 'organization_number' => '', 'country_code' => 'NO']], self::SESSION, OrderFlow::REFUSED, [ErrorMapper::COMPANY_REQUIRED], 0, 'no organisation number: asked for, nothing sent'],
            [[], ['total' => 300], self::SESSION, OrderFlow::REFUSED, [ErrorMapper::GENERIC], 0, 'a failed shop-match check: nothing sent'],
        ];
        foreach ($cases as [$responses, $order, $session, $outcome, $buyer, $calls, $description]) {
            [$flow, $transport] = self::flow($responses);
            $result = $flow->intent($order + self::order(), self::lines(), [], $session, ['merchant_short_name' => 'acme']);
            TinyAssert::same([$outcome, $buyer, $calls], [$result['outcome'], $result['buyer'], count($transport->calls)], 'order intent: ' . $description);
        }

        [$flow, $transport] = self::flow([self::json(200, ['approved' => true]), self::json(200, ['approved' => true]), self::json(200, ['approved' => true])]);
        $flow->intent(self::order(), self::lines(), [], self::SESSION, ['merchant_short_name' => 'acme']);
        $sent = json_decode((string) $transport->calls[0]['body'], true);
        TinyAssert::same('/v1/order_intent', parse_url($transport->calls[0]['url'], PHP_URL_PATH), 'order intent: sent to the intent endpoint');
        TinyAssert::same([], array_diff(array_keys($sent), array_merge(OrderFlow::INTENT_KEYS, ['merchant_short_name'])), 'order intent: carries only what the intent asks about');
        TinyAssert::true(!array_key_exists('invoice_type', $sent), 'order intent: carries no invoice type');
        TinyAssert::same([false, false], [array_key_exists('discount_amount', $sent), array_key_exists('billing_address', $sent)], 'order intent: carries no order-level discount_amount or billing_address');
        TinyAssert::true(array_key_exists('discount_amount', $sent['line_items'][0] ?? []), 'order intent: a line keeps its own discount_amount');
        TinyAssert::same(['acme', '123456789'], [$sent['merchant_short_name'], $sent['buyer']['company']['organization_number']], 'order intent: names the merchant and the buyer company');
        $flow->intent(self::order(), self::lines(), [], self::SESSION, ['merchant_short_name' => 'acme']);
        $flow->intent(['company' => ['company_name' => 'Other AS', 'organization_number' => '987654321', 'country_code' => 'NO']] + self::order(), self::lines(), [], self::SESSION, ['merchant_short_name' => 'acme']);
        $keys = array_map(static fn (array $call): ?string => IdempotencyKey::in($call['headers']), $transport->calls);
        TinyAssert::true(str_starts_with((string) $keys[0], 'intent_0123456789abcdef_'), 'order intent: keyed on the buyer\'s session');
        TinyAssert::same([true, false], [$keys[0] === $keys[1], $keys[1] === $keys[2]], 'order intent: the same question repeats its key, another company asks a new one');
    }

    /**
     * Two confirms for one order at once (a second press, a retry while the
     * first is still talking to Two) create one Two order: the second is
     * refused while the first holds the order's lock.
     */
    private static function testOnePlacementAtATime(): void
    {
        $created = self::json(201, ['id' => '5f1c2a7e-0000-4000-8000-000000000001', 'state' => 'UNVERIFIED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1']);
        [$flow, $transport] = self::flow([$created]);
        $lock = new class implements OrderLock {
            /** @var array<int, bool> */
            public array $held = [];

            public function acquire(int $orderId): bool
            {
                if (isset($this->held[$orderId])) {
                    return false;
                }
                $this->held[$orderId] = true;

                return true;
            }

            public function release(int $orderId): void
            {
                unset($this->held[$orderId]);
            }
        };
        $second = null;
        $place = static fn (): array => $flow->create(self::order(), self::lines(), [], str_repeat('a', 48));
        $first = OrderFlow::exclusively($lock, 42, static function () use ($lock, $place, &$second): array {
            // The second confirm arrives while the first is still placing the order.
            $second = OrderFlow::exclusively($lock, 42, $place);

            return $place();
        });
        $creates = array_filter($transport->calls, static fn (array $call): bool => $call['method'] === 'POST' && str_ends_with((string) parse_url($call['url'], PHP_URL_PATH), '/v1/order'));
        TinyAssert::same([true, false, [OrderFlow::IN_PROGRESS], 1], [$first['ok'], $second['ok'] ?? null, $second['buyer'] ?? null, count($creates)], 'one placement at a time: two concurrent confirms create one Two order');
        TinyAssert::same([], $lock->held, 'one placement at a time: the lock is released after the placement');
        TinyAssert::throws(static fn () => OrderFlow::exclusively($lock, 42, static function (): array {
            throw new \RuntimeException('boom');
        }), \RuntimeException::class, 'one placement at a time: a placement that throws');
        TinyAssert::same([], $lock->held, 'one placement at a time: the lock is released even when the placement throws');
    }

    /** Placement (section 6.3): only an approved intent lets the order be created. */
    private static function testAfterIntent(): void
    {
        $cases = [
            [OrderFlow::APPROVED, [], null, 'approved: the order is created'],
            [OrderFlow::DECLINED, [], [OrderFlow::INTENT_DECLINED], 'declined: refused with the declined notice'],
            [OrderFlow::UNDECIDED, [OrderFlow::INTENT_CONNECTION], [OrderFlow::INTENT_CONNECTION], 'no answer: refused, try again'],
            [OrderFlow::UNDECIDED, [OrderFlow::INTENT_UNAVAILABLE], [OrderFlow::INTENT_UNAVAILABLE], 'a 5xx: refused, try later'],
            [OrderFlow::UNDECIDED, [], [OrderFlow::INTENT_UNPROCESSABLE], 'undecided with no sentence: unable to process'],
            [OrderFlow::REFUSED, [ErrorMapper::COMPANY_REQUIRED], [ErrorMapper::COMPANY_REQUIRED], 'no company: asked for'],
        ];
        foreach ($cases as [$outcome, $buyer, $expected, $description]) {
            TinyAssert::same($expected, OrderFlow::afterIntent(['outcome' => $outcome, 'reason' => '', 'buyer' => $buyer]), 'placement after the intent: ' . $description);
        }
    }

    private static function testOptionalFields(): void
    {
        $enabled = ['invoice_email', 'purchase_order_number', 'project', 'department'];
        $cases = [
            [['invoice_email' => ' ap@example.com ', 'project' => 'Roof'], $enabled, ['invoice_email' => 'ap@example.com', 'project' => 'Roof'], [], 'filled fields, trimmed'],
            [['invoice_email' => 'not-an-email'], $enabled, [], ['invoice_email'], 'an invoice email that is not one is refused'],
            [['department' => str_repeat('d', 256)], $enabled, [], ['department'], 'longer than 255 characters is refused'],
            [['purchase_order_number' => "PO\x01"], $enabled, [], ['purchase_order_number'], 'a control character is refused'],
            [['project' => 'Roof', 'department' => 'Ops'], ['department'], ['department' => 'Ops'], [], 'a field switched off is ignored'],
        ];
        foreach ($cases as [$posted, $on, $fields, $refused, $description]) {
            TinyAssert::same(['fields' => $fields, 'refused' => $refused], BuyerFields::fromPost($posted, $on), 'optional fields: ' . $description);
        }
        $fields = ['invoice_email' => 'ap@example.com', 'purchase_order_number' => 'PO-1', 'project' => 'Roof', 'department' => 'Ops'];
        TinyAssert::same($fields, BuyerFields::decode(BuyerFields::encode($fields)), 'optional fields: stored and read back');
        TinyAssert::same('', BuyerFields::encode(['project' => ' ']), 'optional fields: none stores nothing');

        $payload = (new OrderBuilder())->build(['buyer_fields' => $fields] + self::order(), self::lines(), []);
        TinyAssert::same(
            ['Ops', 'Roof', 'PO-1', ['invoice_emails' => ['ap@example.com']]],
            [$payload['buyer_department'], $payload['buyer_project'], $payload['buyer_purchase_order_number'], $payload['invoice_details']],
            'optional fields: on the order create payload'
        );
        $plain = (new OrderBuilder())->build(self::order(), self::lines(), []);
        TinyAssert::same([], array_intersect(['buyer_department', 'buyer_project', 'buyer_purchase_order_number', 'invoice_details'], array_keys($plain)), 'optional fields: none given, none sent');

        $records = new MemoryRecords();
        [$flow] = self::flow([self::json(201, ['id' => '5f1c2a7e-0000-4000-8000-000000000001', 'state' => 'UNVERIFIED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1'])], $records);
        $flow->create(['buyer_fields' => $fields] + self::order(), self::lines(), [], str_repeat('a', 48));
        TinyAssert::same($fields, BuyerFields::decode($records->rows[42]['buyer_fields'] ?? ''), 'optional fields: kept with the order for the confirm rebuild');
    }

    private static function testSearch(): void
    {
        $items = ['items' => [
            ['name' => 'Acme AS', 'national_identifier' => ['id' => '123456789'], 'highlight' => '<mark>Acme</mark> AS', 'lookup_id' => 'bEB0d2'],
            ['name' => 'Acme Ltd', 'company_number' => '01234567'],
            ['name' => ' ', 'national_identifier' => ['id' => '1']],
        ]];
        $cases = [
            [[self::json(200, $items)], 'acme', 'no', [
                ['name' => 'Acme AS', 'organization_number' => '123456789', 'highlight' => '<mark>Acme</mark> AS', 'lookup_id' => 'bEB0d2'],
                ['name' => 'Acme Ltd', 'organization_number' => '01234567', 'highlight' => '', 'lookup_id' => ''],
            ], ['/companies/v2/company?country=NO&q=acme&limit=50&offset=0'], 'results mapped, a nameless row dropped'],
            [[self::json(500, [])], 'acme', 'NO', null, ['/companies/v2/company?country=NO&q=acme&limit=50&offset=0'], 'a failed search is not an empty one'],
            [[self::json(200, ['unexpected' => true])], 'acme', 'NO', null, ['/companies/v2/company?country=NO&q=acme&limit=50&offset=0'], 'an answer without items is a failure'],
            [[], 'ac ', 'NO', [], [], 'a term under three characters asks nothing'],
            [[], 'acme', 'Norway', [], [], 'a country that is not a code asks nothing'],
        ];
        foreach ($cases as [$responses, $term, $country, $expected, $paths, $description]) {
            [$lookup, $transport] = self::lookup($responses);
            TinyAssert::same($expected, $lookup->search($term, $country), 'company search: ' . $description);
            TinyAssert::same($paths, self::paths($transport), 'company search: ' . $description . ' (requests)');
        }
        $details = [
            [[self::json(200, ['national_identifier' => ['type' => 'GB_CRN', 'id' => '07844329'], 'addresses' => [['type' => 'BUSINESS_ADDRESS', 'street_address' => '1 High St', 'postal_code' => 'TW9 1AA', 'city' => 'Richmond', 'region' => null, 'country' => 'GB']]])], 'bEB0d2', ['organization_number' => '07844329', 'addresses' => [['type' => 'BUSINESS_ADDRESS', 'street_address' => '1 High St', 'postal_code' => 'TW9 1AA', 'city' => 'Richmond', 'country' => 'GB']]], 1, 'the number and addresses a search result left out'],
            [[self::json(200, ['national_identifier' => ['id' => '12345678'], 'country' => 'NL', 'addresses' => [['type' => 'REGISTERED', 'street_address' => 'Dam 1', 'postal_code' => '1012jS', 'city' => 'Amsterdam', 'phone_number' => '+31 20 555 0100'], ['type' => 'VISITING', 'postal_code' => '00950', 'country' => 'PL']]])], 'bEB0d2', ['organization_number' => '12345678', 'addresses' => [['type' => 'REGISTERED', 'street_address' => 'Dam 1', 'postal_code' => '1012 jS', 'city' => 'Amsterdam', 'phone_number' => '+31 20 555 0100'], ['type' => 'VISITING', 'postal_code' => '00-950', 'country' => 'PL']]], 1, 'the registry phone is kept and each postcode fitted, to its own country else the company\'s (TWO-27110)'],
            [[self::json(404, [])], 'bEB0d2', null, 1, 'a failed lookup'],
            [[], '../v1/merchant', null, 0, 'a lookup id that is not one: nothing asked'],
            [[], '..', null, 0, 'two dots, which a path would normalise away: nothing asked'],
            [[], '-abc', null, 0, 'a lookup id must start with a letter or digit'],
            [[], 'a.b', null, 0, 'a dot is not in Two\'s lookup id alphabet'],
        ];
        $fits = [
            ['1234AB', 'NL', '1234 AB', 'the separator the country writes is put back'],
            ['1234-ab', 'nl', '1234 ab', 'a hyphen is swapped for the space, letter case kept'],
            ['00950', 'PL', '00-950', 'a hyphenated format'],
            ['11122', 'SE', '111 22', 'a spaced format'],
            ['1000-001', 'PT', '1000-001', 'already fitted'],
            ['123 4AB', 'NL', '1234 AB', 'a stray space moves'],
            ['12345', 'NL', '12345', 'one that does not fit the format is left alone'],
            ['1234ABC', 'NL', '1234ABC', 'one character too many is left alone'],
            ['EC1A1AA', 'GB', 'EC1A1AA', 'a country with no one format is left alone'],
            ['', 'NL', '', 'nothing stays nothing'],
        ];
        foreach ($fits as [$postcode, $country, $expected, $description]) {
            TinyAssert::same($expected, PostcodeFormat::fit($postcode, $country), 'postcode fit: ' . $description);
        }
        foreach ($details as [$responses, $id, $expected, $calls, $description]) {
            [$lookup, $transport] = self::lookup($responses);
            TinyAssert::same([$expected, $calls], [$lookup->details($id), count($transport->calls)], 'company details: ' . $description);
        }
        $countries = [
            [[self::json(200, ['supported_countries' => ['gb', 'NO', 'x', 'GB']])], ['GB', 'NO'], 'the covered countries, uppercased and deduplicated'],
            [[self::json(503, [])], null, 'no answer is unknown, not none'],
        ];
        foreach ($countries as [$responses, $expected, $description]) {
            [$lookup] = self::lookup($responses);
            TinyAssert::same($expected, $lookup->supportedCountries(), 'supported countries: ' . $description);
        }
    }

    private static function testSoleTrader(): void
    {
        $cases = [
            [[self::json(200, ['supported_company_types' => ['LIMITED_COMPANY', 'SOLE_TRADER']])], 'gb', true, 'a country the registry lists sole traders for'],
            [[self::json(200, ['supported_company_types' => ['LIMITED_COMPANY']])], 'NO', false, 'a business-only country'],
            [[HttpResponse::unreachable('timeout')], 'GB', null, 'no answer is unknown'],
            [[], 'GBR', false, 'not a country code: nothing asked'],
        ];
        foreach ($cases as [$responses, $country, $expected, $description]) {
            [$lookup] = self::lookup($responses);
            TinyAssert::same($expected, $lookup->soleTraderAvailable($country), 'sole trader availability: ' . $description);
        }

        $minted = static fn (string $token): HttpResponse => new HttpResponse(201, '{}', ['two-delegated-authority-token' => $token]);
        $base = IdempotencyKey::soleTraderAttempt(self::SESSION, 2);
        $mints = [
            [[$minted('d1'), $minted('a1')], ['delegation_token' => 'd1', 'autofill_token' => 'a1'], 2, 'both tokens minted'],
            [[$minted('d1'), new HttpResponse(403, '{}')], null, 2, 'one mint refused: neither is handed out'],
            [[new HttpResponse(201, '{}')], null, 1, 'a mint answered without its token header: refused'],
        ];
        foreach ($mints as [$responses, $expected, $calls, $description]) {
            [$lookup, $transport] = self::lookup($responses);
            TinyAssert::same($expected, $lookup->soleTraderTokens($base), 'sole trader tokens: ' . $description);
            TinyAssert::same($calls, count($transport->calls), 'sole trader tokens: ' . $description . ' (requests)');
        }
        [$lookup, $transport] = self::lookup([$minted('d1'), $minted('a1')]);
        $lookup->soleTraderTokens($base);
        TinyAssert::same(
            [['/registry/v1/delegation', 'soletrader_delegation_0123456789abcdef_2', ['create_proposal' => true, 'read_current_business' => true]], ['/autofill/v1/delegation', 'soletrader_autofill_0123456789abcdef_2', ['read_current_buyer' => true, 'write_current_buyer' => true]]],
            array_map(static fn (array $call): array => [parse_url($call['url'], PHP_URL_PATH), IdempotencyKey::in($call['headers']), json_decode((string) $call['body'], true)], $transport->calls),
            'sole trader tokens: each mint keyed on the attempt, with its scopes'
        );
        TinyAssert::same('https://checkout.sandbox.two.inc/soletrader/signup', $lookup->signupUrl(), 'sole trader: the signup page for the environment');
    }

    private static function testRateLimits(): void
    {
        $now = 1_800_000_000;
        $proxies = ['10.0.0.0/8', '2001:db8:ffff::/48'];
        $callers = [
            ['192.0.2.7', '', [], '192.0.2.7', 'the socket address, with no proxies set'],
            ['192.0.2.7', '198.51.100.9', [], '192.0.2.7', 'a forwarded header from a caller that is not a trusted proxy is ignored'],
            ['10.1.2.3', '198.51.100.9', $proxies, '198.51.100.9', 'through a trusted proxy: the forwarded client'],
            ['10.1.2.3', '198.51.100.66, 198.51.100.9, 10.4.4.4', $proxies, '198.51.100.9', 'the nearest hop that is not a trusted proxy, read from the right'],
            ['10.1.2.3', '10.9.9.9', $proxies, '10.1.2.3', 'every hop trusted: the proxy itself'],
            ['10.1.2.3', '', $proxies, '10.1.2.3', 'a trusted proxy with no forwarded header'],
            ['2001:db8:1:2:3:4:5:6', '', [], '2001:db8:1:2::/64', 'an IPv6 caller counts as its /64'],
            ['', '198.51.100.9', $proxies, 'unknown', 'no socket address at all'],
        ];
        foreach ($callers as [$peer, $forwarded, $trusted, $expected, $description]) {
            TinyAssert::same($expected, RateLimiter::callerIdentity($peer, $forwarded, $trusted), 'rate limit caller: ' . $description);
        }
        $entries = [
            ['10.0.0.1', true], ['10.0.0.0/8', true], ['2001:db8::/32', true], ['::1', true],
            ['10.0.0.0/33', false], ['10.0.0.0/0', false], ['10.0.0.0/x', false], ['example.com', false], ['10.0.0', false],
        ];
        foreach ($entries as [$entry, $valid]) {
            TinyAssert::same($valid, RateLimiter::isValidProxyEntry($entry), 'trusted proxies entry ' . $entry);
        }
        TinyAssert::same(['10.0.0.1', '10.0.0.0/8', '::1'], RateLimiter::parseProxies(" 10.0.0.1,10.0.0.0/8\n\n ::1 "), 'trusted proxies: split on commas and new lines');
        TinyAssert::same(
            [[30, 60], [60, 60], [60, 60], [300, 60]],
            [RateLimiter::CALLER_LIMITS['intent'], RateLimiter::CALLER_LIMITS['soleTraderTokens'], RateLimiter::CALLER_LIMITS['countries'], RateLimiter::CALLER_LIMITS['search']],
            'rate limit per caller: intents 30, token mints and countries 60, searches 300 a minute'
        );
        $sessions = [
            [[], ['stamps' => [$now], 'allowed' => true], 'the first intent of a session'],
            [[$now - 10, $now - 20, $now - 30, $now - 40], ['stamps' => [$now - 10, $now - 20, $now - 30, $now - 40, $now], 'allowed' => true], 'the fifth in a minute'],
            [[$now - 10, $now - 20, $now - 30, $now - 40, $now - 50], ['stamps' => [$now - 10, $now - 20, $now - 30, $now - 40, $now - 50], 'allowed' => false], 'the sixth is refused and not counted'],
            [[$now - 60, $now - 70, $now - 80, $now - 90, $now - 100], ['stamps' => [$now], 'allowed' => true], 'older than a minute no longer counts'],
        ];
        foreach ($sessions as [$stamps, $expected, $description]) {
            TinyAssert::same($expected, RateLimiter::sessionIntent($stamps, $now), 'rate limit per session: ' . $description);
        }
        TinyAssert::true(RateLimiter::key('intent', '192.0.2.1') !== RateLimiter::key('intent', '192.0.2.2') && RateLimiter::key('intent', '192.0.2.1') !== RateLimiter::key('search', '192.0.2.1'), 'rate limit: one count per caller and action');
    }

    private static function testDisplayLabel(): void
    {
        $cases = [
            ['Acme Ltd', '01234567', 'Acme Ltd (01234567)', 'a registered company with its number'],
            ['Jo Bloggs', 'TWO:ST123', 'Jo Bloggs', 'a sole trader\'s TWO: number is never shown'],
            ['Jo Bloggs', 'two:st123', 'Jo Bloggs', 'whatever its case'],
            ['Acme Ltd', '', 'Acme Ltd', 'no number, no brackets'],
        ];
        foreach ($cases as [$name, $number, $expected, $description]) {
            TinyAssert::same($expected, CompanyLookup::displayLabel($name, $number), 'company label: ' . $description);
        }
    }

    private static function testKeys(): void
    {
        $refused = [
            [static fn () => IdempotencyKey::orderIntent('', str_repeat('a', 64)), 'an intent without a session'],
            [static fn () => IdempotencyKey::orderIntent('session-id', str_repeat('a', 64)), 'an intent keyed on a raw session id'],
            [static fn () => IdempotencyKey::soleTraderMint('other', 'x'), 'a token that is not one of the two'],
        ];
        foreach ($refused as [$call, $description]) {
            TinyAssert::throws($call, InvalidArgumentException::class, 'idempotency key refuses ' . $description);
        }
        TinyAssert::same('0123456789abcdef_1', IdempotencyKey::soleTraderAttempt(self::SESSION, 0), 'idempotency key: an attempt number below one counts as the first');
    }

    private static function testSettings(): void
    {
        $cases = [
            [[], true, BuyerFields::FIELDS, null, null, 'nothing stored: search in the address forms, every field on, no minimum'],
            [['company_search_in_address' => '0', 'enable_po_number' => '0', 'enable_department' => '0'], false, ['invoice_email', 'project'], null, null, 'the tile placement, two fields off'],
            [['min_order_amount' => '99.50', 'min_order_basis' => 'net'], true, BuyerFields::FIELDS, 99.5, 'net', 'a merchant minimum on net'],
            [['min_order_amount' => 'abc', 'min_order_basis' => 'both'], true, BuyerFields::FIELDS, null, null, 'unreadable minimum and basis are none'],
        ];
        TinyAssert::same([false, true], [(new SettingsProvider(new MemoryStore()))->rateLimitDisabled(), (new SettingsProvider(new MemoryStore(['disable_rate_limit' => '1'])))->rateLimitDisabled()], 'checkout settings: the per-caller rate limits are on unless switched off');
        foreach ($cases as [$stored, $address, $fields, $minimum, $basis, $description]) {
            $settings = new SettingsProvider(new MemoryStore($stored));
            TinyAssert::same(
                [$address, $fields, $minimum, $basis],
                [$settings->companySearchInAddressArea(), $settings->optionalFields(), $settings->merchantMinimumOrder(), $settings->merchantMinimumOrderBasis()],
                'checkout settings: ' . $description
            );
        }
    }

    /** The Checkout fields tab's save: each field written when valid, kept and named when not. */
    private static function testSave(): void
    {
        $cases = [
            [['company_search_in_address' => '0', 'enable_project' => '1'], ['company_search_in_address' => '0', 'enable_project' => '1'], [], 'switches written'],
            [['disable_rate_limit' => '1'], ['disable_rate_limit' => '1'], [], 'the rate-limit switch written'],
            [['enable_po_number' => 'yes'], [], ['enable_po_number' => SettingsSave::SWITCH_INVALID], 'a switch that is not 0 or 1 is refused'],
            [['min_order_amount' => '149.95', 'min_order_basis' => 'net'], ['min_order_amount' => '149.95', 'min_order_basis' => 'net'], [], 'a minimum on net'],
            [['min_order_amount' => '', 'min_order_basis' => ''], ['min_order_amount' => '', 'min_order_basis' => ''], [], 'both cleared'],
            [['min_order_amount' => '0'], [], ['min_order_amount' => SettingsSave::MINIMUM_INVALID], 'zero is refused: empty means no minimum'],
            [['min_order_amount' => '1.234'], [], ['min_order_amount' => SettingsSave::MINIMUM_INVALID], 'three decimals are refused'],
            [['min_order_amount' => '-5'], [], ['min_order_amount' => SettingsSave::MINIMUM_INVALID], 'a negative amount is refused'],
            [['min_order_basis' => 'both'], [], ['min_order_basis' => SettingsSave::BASIS_INVALID], 'an unknown basis is refused'],
            [['trusted_proxies' => " 10.0.0.0/8,\n192.0.2.1 "], ['trusted_proxies' => "10.0.0.0/8,\n192.0.2.1"], [], 'trusted proxies written'],
            [['trusted_proxies' => ''], ['trusted_proxies' => ''], [], 'trusted proxies cleared'],
            [['trusted_proxies' => '10.0.0.0/8, proxy.example'], [], ['trusted_proxies' => SettingsSave::PROXY_INVALID], 'an entry that is not an IP or a range is refused'],
        ];
        foreach ($cases as [$submitted, $written, $errors, $description]) {
            $store = new MemoryStore();
            $settings = new SettingsProvider($store);
            $client = self::client(new ScriptedTransport(), new RecordingLogger());
            $verdict = new ApiKeyVerdict($client, $settings);
            $save = new SettingsSave($settings, new Environment(false), $verdict, new RecordProvider($client, $settings, $verdict, new RecordingLogger()));
            $result = $save->save($submitted, [], 0);
            TinyAssert::same([$written, $errors], [$store->values, $result['errors']], 'checkout settings save: ' . $description);
        }
    }

    /**
     * @param list<HttpResponse> $responses
     * @return array{0: OrderFlow, 1: ScriptedTransport}
     */
    private static function flow(array $responses, ?MemoryRecords $records = null): array
    {
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $flow = new OrderFlow(self::client($transport, $logger), new OrderBuilder(), new Postprocessing(new ScriptedSubscribers([]), $logger), new ErrorMapper(), $records ?? new MemoryRecords(), $logger);

        return [$flow, $transport];
    }

    /**
     * @param list<HttpResponse> $responses
     * @return array{0: CompanyLookup, 1: ScriptedTransport}
     */
    private static function lookup(array $responses): array
    {
        $transport = new ScriptedTransport($responses);
        $settings = new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox']));

        return [new CompanyLookup(self::client($transport, new RecordingLogger()), new Environment(false), $settings), $transport];
    }

    private static function client(ScriptedTransport $transport, RecordingLogger $logger): Client
    {
        return new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
    }

    /** @return list<string> path and query, without the client parameters */
    private static function paths(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => (string) preg_replace('~[?&]client=.*\z~', '', (string) parse_url($call['url'], PHP_URL_PATH) . '?' . (string) parse_url($call['url'], PHP_URL_QUERY)), $transport->calls);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }

    /** @return list<array<string, mixed>> */
    private static function lines(): array
    {
        return [['name' => 'Widget', 'quantity' => 2, 'price' => 100, 'total' => 200, 'tax' => 25, 'rate' => 0.25, 'type' => 'PHYSICAL']];
    }

    /** @return array<string, mixed> */
    private static function order(): array
    {
        return [
            'order_id' => 42, 'currency' => 'NOK', 'currency_value' => 1.0, 'total' => 250, 'tax_total' => 50, 'date_added' => '2026-10-24 10:00:00',
            'company' => ['company_name' => 'Acme AS', 'organization_number' => '123456789', 'country_code' => 'NO'],
            'buyer' => ['email' => 'buyer@example.com', 'first_name' => 'Kari', 'last_name' => 'Nordmann', 'phone_number' => '+4799999999'],
            'billing_address' => ['street_address' => 'Gate 1', 'postal_code' => '0150', 'city' => 'Oslo', 'region' => '', 'country' => 'NO'],
            'merchant_urls' => ['merchant_confirmation_url' => 'https://shop.example/confirm'],
        ];
    }
}
