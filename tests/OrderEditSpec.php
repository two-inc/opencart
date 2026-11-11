<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\BuyerFields;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\FeeQuote;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderEdit;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * Order-edit sync against a scripted Two. The order was placed with 2
 * Widgets; the edit makes it 3. Each case gives the stored Two state, the
 * rows and order the shop holds now, Two's answers, then the outcome
 * (null: nothing to sync), the requests and what is stored afterwards.
 */
final class OrderEditSpec
{
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';
    private const TOKEN_HASH = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public static function runAll(): void
    {
        self::testVatNumberCreateOnly();
        self::testBuyerFields();
        self::testSync();
        self::testKeyChain();
        self::testSequences();
        self::testFulfilledNoteOnce();
    }

    private static function testSync(): void
    {
        $read = 'GET /v1/order/' . self::ID;
        $put = 'PUT /v1/order/' . self::ID;
        $refuses = ['m' => static function (array $p, array $c): array {
            if ($c['request_type'] === Postprocessing::ORDER_UPDATE) {
                throw new \RuntimeException('no');
            }

            return $p;
        }];
        $cases = [
            ['stored' => 'CONFIRMED', 'rows' => 2, 'input' => 2, 'responses' => [], 'outcome' => null, 'calls' => [], 'after' => 'placed', 'description' => 'not edited: nothing asked'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::get('CONFIRMED'), self::get('CONFIRMED')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $put], 'after' => 'accepted', 'description' => 'edited before fulfilment: sent, its key and hashes stored'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 2, 'responses' => [self::get('CONFIRMED')], 'outcome' => null, 'calls' => [$read], 'after' => 'rows_only', 'description' => 'the rows changed but not what Two holds: nothing sent, rows hash moves on'],
            ['stored' => 'FULFILLED', 'rows' => 3, 'input' => 3, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'after' => 'rows_only', 'reason' => 'invoiced all or part', 'description' => 'edited after fulfilment: refused with a note, written once'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::get('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'after' => 'rows_only', 'description' => 'fulfilled at Two meanwhile: refused'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::get('CONFIRMED', 'PARTIAL')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'after' => 'rows_only', 'description' => 'partly fulfilled in the merchant portal: refused'],
            ['stored' => 'CANCELLED', 'rows' => 3, 'input' => 3, 'responses' => [], 'outcome' => null, 'calls' => [], 'after' => 'placed', 'description' => 'cancelled: nothing to sync'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::json(500, [])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'after' => 'placed', 'description' => 'Two\'s state cannot be read: not sent, retried on the next save'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::get('CONFIRMED'), self::json(400, ['error_code' => 'X'])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $put], 'after' => 'placed', 'description' => 'Two refuses the edit: nothing stored, fulfil stays blocked'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::get('CONFIRMED'), HttpResponse::unreachable('timeout')], 'outcome' => OrderEdit::UNKNOWN, 'calls' => [$read, $put], 'after' => 'cleared', 'description' => 'a timeout: the payload hash is cleared so the next save sends it'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::get('CONFIRMED'), self::json(503, [])], 'outcome' => OrderEdit::UNKNOWN, 'calls' => [$read, $put], 'after' => 'cleared', 'description' => 'a 5xx: unknown, hash cleared'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::get('CONFIRMED'), self::json(429, [])], 'outcome' => OrderEdit::UNKNOWN, 'calls' => [$read, $put], 'after' => 'cleared', 'description' => 'a 429: unknown, hash cleared'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'responses' => [self::get('CONFIRMED'), self::json(401, [])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $put], 'after' => 'placed', 'description' => 'a 401: refused for now, nothing stored'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'handlers' => $refuses, 'responses' => [self::get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'after' => 'placed', 'description' => 'the hook refuses the update: nothing sent'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => null, 'responses' => [self::get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'after' => 'placed', 'description' => 'the order can no longer be read: refused'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'input' => 3, 'currency_refusal' => Message::of('refusal_currency_changed', 'NOK', 'EUR'), 'responses' => [self::get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'after' => 'placed', 'reason' => 'placed with Two in NOK and its currency is now EUR', 'description' => 'the edit changed the order\'s currency: refused with the reason, no PUT'],
            ['stored' => 'CONFIRMED', 'rows' => 2, 'currency' => 'EUR', 'input' => 2, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'after' => 'placed', 'reason' => 'not sent to Two: the order was placed with Two in NOK and its currency is now EUR', 'description' => 'a currency-only edit (the rows alike, in the default currency): refused with the currency before the rows shortcut, nothing asked'],
            ['stored' => 'CONFIRMED', 'rows' => 3, 'currency' => 'EUR', 'input' => 3, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'after' => 'placed', 'reason' => 'its currency is now EUR', 'description' => 'a currency change with another edit: refused with the currency, nothing asked'],
            ['stored' => 'CONFIRMED', 'rows' => 2, 'currency' => 'NOK', 'input' => 2, 'responses' => [], 'outcome' => null, 'calls' => [], 'after' => 'placed', 'description' => 'the currency switched back, the rows as placed: nothing to send'],
            ['stored' => 'CONFIRMED', 'unhashed' => true, 'rows' => 3, 'input' => 3, 'responses' => [], 'outcome' => null, 'calls' => [], 'after' => 'unhashed', 'description' => 'no rows hash kept: nothing to compare, the fulfil check refuses instead'],
        ];
        foreach ($cases as $case) {
            [$edit, $transport, $records] = self::edit($case['responses'], $case['stored'], $case['handlers'] ?? [], !empty($case['unhashed']));
            $result = $edit->sync(42, self::rows($case['rows']) + (isset($case['currency']) ? ['currency' => $case['currency']] : []), static fn (array $record): ?array => $case['input'] === null ? null : array_replace_recursive(self::input($case['input']), ['order' => ['currency_refusal' => $case['currency_refusal'] ?? null]]));
            $d = 'edit: ' . $case['description'];
            TinyAssert::same($case['outcome'], $result['outcome'] ?? null, $d . ': outcome');
            TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
            $row = $records->rows[42];
            $expected = match ($case['after']) {
                'placed' => [self::placedHash(), OrderLifecycle::rowsHash(self::rows(2)), ''],
                'accepted' => [self::snapshot(3), OrderLifecycle::rowsHash(self::rows(3)), $transport->calls[1]['headers']['Idempotency-Key'] ?? 'missing'],
                'rows_only' => [self::placedHash(), OrderLifecycle::rowsHash(self::rows(3)), ''],
                'cleared' => ['', OrderLifecycle::rowsHash(self::rows(2)), ''],
                'unhashed' => [self::placedHash(), '', ''],
            };
            TinyAssert::same($expected, [$row['payload_hash'], $row['rows_hash'], $row['edit_key'] ?? ''], $d . ': payload hash, rows hash and edit key stored');
            if (isset($case['reason'])) {
                TinyAssert::true(str_contains($result['reason'] ?? '', $case['reason']), $d . ': the note says why');
            }
            if (in_array($put, self::calls($transport), true)) {
                $body = json_decode((string) $transport->calls[1]['body'], true);
                TinyAssert::true(!isset($body['merchant_urls']) && !isset($body['buyer']), $d . ': the edit carries no callback URLs or buyer');
                TinyAssert::true(!array_key_exists('invoice_type', $body), $d . ': the edit carries no invoice type');
                TinyAssert::same([true, true], [array_key_exists('discount_amount', $body), array_key_exists('billing_address', $body)], $d . ': the edit still carries the order-level discount_amount and billing_address');
                TinyAssert::same(3, $body['line_items'][0]['quantity'] ?? null, $d . ': the edited line');
                TinyAssert::same(IdempotencyKey::edit(self::ID, IdempotencyKey::orderCreate(42, self::TOKEN_HASH), OrderFlow::payloadHash($body)), $transport->calls[1]['headers']['Idempotency-Key'] ?? null, $d . ': the first edit chains on the create key');
            }
        }
    }

    /** Two keeps the buyer's VAT number given at create (TWO-27058): an edit of a Spanish merchant's order with one never sends it. */
    private static function testVatNumberCreateOnly(): void
    {
        [$edit, $transport] = self::edit([self::get('CONFIRMED'), self::get('CONFIRMED')], 'CONFIRMED');
        $edit->sync(42, self::rows(3), static fn (array $record): array => array_replace_recursive(self::input(3), ['order' => ['buyer_vat_number' => 'FR40303265045']]));
        TinyAssert::same(['GET /v1/order/' . self::ID, 'PUT /v1/order/' . self::ID], self::calls($transport), 'edit with a VAT number: sent');
        $body = json_decode((string) ($transport->calls[1]['body'] ?? '{}'), true);
        TinyAssert::same(false, array_key_exists('buyer_vat_number', $body), 'edit with a VAT number: the body carries no buyer_vat_number');
    }

    /**
     * An order placed with optional buyer fields: the shared rebuild carries
     * them from the record, so rows that do not change what Two holds send
     * nothing, and an edit keeps them.
     */
    private static function testBuyerFields(): void
    {
        $fields = ['invoice_email' => 'ap@example.com', 'purchase_order_number' => 'PO-1', 'project' => 'Roof', 'department' => 'Ops'];
        $withFields = static function (int $quantity) use ($fields): array {
            $input = self::input($quantity);
            $input['order']['buyer_fields'] = $fields;

            return $input;
        };
        $placed = OrderFlow::payloadHash((new OrderBuilder())->build($withFields(2)['order'], $withFields(2)['lines'], []));
        $read = 'GET /v1/order/' . self::ID;
        $cases = [
            [2, [self::get('CONFIRMED')], [$read], 'rows changed, the order Two holds did not: nothing sent'],
            [3, [self::get('CONFIRMED'), self::get('CONFIRMED')], [$read, 'PUT /v1/order/' . self::ID], 'an edit: sent, the buyer fields kept'],
        ];
        foreach ($cases as [$quantity, $responses, $calls, $description]) {
            [$edit, $transport, $records] = self::edit($responses, 'CONFIRMED');
            $records->rows[42]['payload_hash'] = $placed;
            $records->rows[42]['buyer_fields'] = BuyerFields::encode($fields);
            $rebuild = OrderFlow::rebuildFrom(static function (array $company, array $stored) use ($withFields, $quantity): array {
                $input = $withFields($quantity);
                $input['order']['buyer_fields'] = $stored;

                return $input;
            });
            $edit->sync(42, self::rows(3), $rebuild);
            TinyAssert::same($calls, self::calls($transport), 'edit with buyer fields: ' . $description);
            if (count($transport->calls) > 1) {
                $body = json_decode((string) $transport->calls[1]['body'], true);
                TinyAssert::same(['PO-1', 'Roof', 'Ops', ['ap@example.com']], [$body['buyer_purchase_order_number'] ?? null, $body['buyer_project'] ?? null, $body['buyer_department'] ?? null, $body['invoice_details']['invoice_emails'] ?? null], 'edit with buyer fields: ' . $description . ' (body)');
            }
        }
    }

    /** A retry after an unknown outcome repeats the key; the next edit chains on the last accepted one. */
    private static function testKeyChain(): void
    {
        $ok = self::get('CONFIRMED');
        [$edit, $transport, $records] = self::edit([$ok, HttpResponse::unreachable('timeout'), $ok, $ok, $ok, $ok], 'CONFIRMED');
        $rebuild = static fn (int $quantity): \Closure => static fn (array $record): array => self::input($quantity);
        $edit->sync(42, self::rows(3), $rebuild(3));
        $edit->sync(42, self::rows(3), $rebuild(3));
        $accepted = $records->rows[42]['edit_key'] ?? '';
        $edit->sync(42, self::rows(4), $rebuild(4));
        $keys = array_values(array_map(static fn (array $call): string => $call['headers']['Idempotency-Key'], array_filter($transport->calls, static fn (array $call): bool => $call['method'] === 'PUT')));
        TinyAssert::same(3, count($keys), 'key chain: three edits sent');
        TinyAssert::same($keys[0], $keys[1], 'key chain: the retry after a timeout repeats the key');
        TinyAssert::same($keys[1], $accepted, 'key chain: the accepted retry\'s key is stored');
        $body = json_decode((string) end($transport->calls)['body'], true);
        TinyAssert::same(IdempotencyKey::edit(self::ID, $keys[1], OrderFlow::payloadHash($body)), $keys[2], 'key chain: the next edit chains on the last accepted key');
        TinyAssert::same($keys[2], $records->rows[42]['edit_key'], 'key chain: the latest accepted key is stored');
    }

    /**
     * Edits in a row against one stored order. Each step is the Widgets the
     * order holds then and Two's answer to the edit (null: nothing should
     * be sent); every step also reads Two's state first when it sends.
     */
    private static function testSequences(): void
    {
        $ok = self::get('CONFIRMED');
        $timeout = HttpResponse::unreachable('timeout');
        $cases = [
            [[[3, $timeout], [2, $ok]], [3, 2], 2, 'a timeout (Two may hold 3), then back to 2 as placed: 2 is sent, not skipped'],
            [[[3, self::json(502, [])], [2, $ok]], [3, 2], 2, 'a 5xx, then back to 2 as placed: 2 is sent'],
            [[[3, $timeout], [3, $ok]], [3, 3], 3, 'a timeout, then the same order: sent again'],
            [[[3, self::json(400, ['error_code' => 'X'])], [3, null]], [3], 2, 'an edit Two refused: the same edit is not sent or noted again'],
            [[[3, self::json(400, ['error_code' => 'X'])], [4, $ok]], [3, 4], 4, 'an edit Two refused, then another edit: the new one is sent'],
            [[[3, self::json(422, ['error_code' => 'X'])], [3, null]], [3], 2, '422, a validation refusal: the same edit is not sent again'],
            [[[3, self::json(401, [])], [3, $ok]], [3, 3], 3, '401 (a key being changed): nothing recorded, the next save sends it'],
            [[[3, self::json(403, [])], [3, $ok]], [3, 3], 3, '403: nothing recorded, the next save sends it'],
            [[[3, self::json(409, [])], [3, $ok]], [3, 3], 3, '409, a conflict: nothing recorded, the next save sends it'],
            [[[3, self::json(429, [])], [3, $ok]], [3, 3], 3, '429, a rate limit: like no answer, the next save sends it'],
            [[[3, self::json(408, [])], [3, $ok]], [3, 3], 3, '408, a timeout at Two: like no answer, the next save sends it'],
            [[[3, self::json(429, [])], [2, $ok]], [3, 2], 2, '429, then back to 2 as placed: 2 is sent, since Two may hold 3'],
        ];
        $refusedKept = ['an edit Two refused: the same edit is not sent or noted again', '422, a validation refusal: the same edit is not sent again'];
        foreach ($cases as [$steps, $sent, $accepted, $description]) {
            $responses = [];
            foreach ($steps as [, $answer]) {
                if ($answer !== null) {
                    $responses[] = $ok;
                    $responses[] = $answer;
                }
            }
            [$edit, $transport, $records] = self::edit($responses, 'CONFIRMED');
            $outcomes = [];
            foreach ($steps as [$quantity]) {
                $outcomes[] = $edit->sync(42, self::rows($quantity), static fn (array $record): array => self::input($quantity))['outcome'] ?? null;
            }
            $d = 'edit sequence: ' . $description;
            $puts = array_values(array_filter($transport->calls, static fn (array $call): bool => $call['method'] === 'PUT'));
            TinyAssert::same($sent, array_map(static fn (array $call): int => (int) json_decode((string) $call['body'], true)['line_items'][0]['quantity'], $puts), $d . ': the quantities sent');
            TinyAssert::same(OrderLifecycle::rowsHash(self::rows($accepted)), $records->rows[42]['rows_hash'], $d . ': the rows Two last accepted');
            TinyAssert::same(count(array_filter($outcomes)), count($puts), $d . ': a note only for an edit sent');
            TinyAssert::same($records->rows[42]['edit_refused_hash'] ?? '', in_array($description, $refusedKept, true) ? OrderLifecycle::rowsHash(self::rows(3)) : '', $d . ': a refused rows hash only for a refusal of the edit itself');
        }
    }

    /**
     * Fulfilled in the Two merchant portal while an edit's outcome was
     * unknown: each save notes it at most once per rows hash, and nothing is
     * sent. Each case is the saves (Widgets each time) and the notes written.
     */
    private static function testFulfilledNoteOnce(): void
    {
        $cases = [
            [[3, 3, 3], 1, 'the same rows saved three times: one note'],
            [[3, 3, 4], 2, 'another edit after the note: noted again, once'],
        ];
        foreach ($cases as [$saves, $notes, $description]) {
            [$edit, $transport, $records] = self::edit([], 'FULFILLED');
            $records->rows[42]['payload_hash'] = '';
            $written = 0;
            foreach ($saves as $quantity) {
                $written += $edit->sync(42, self::rows($quantity), static fn (array $record): array => self::input($quantity)) === null ? 0 : 1;
            }
            TinyAssert::same([$notes, []], [$written, $transport->calls], 'fulfilled with an unknown edit: ' . $description);
        }
    }

    /**
     * @param list<HttpResponse>                                                          $responses
     * @param array<string, \Closure(array<string, mixed>, array<string, mixed>): mixed> $handlers
     * @return array{0: OrderEdit, 1: ScriptedTransport, 2: MemoryRecords}
     */
    private static function edit(array $responses, string $stored, array $handlers = [], bool $unhashed = false): array
    {
        $records = new MemoryRecords([42 => [
            'two_order_id' => self::ID, 'two_order_state' => $stored, 'token_hash' => self::TOKEN_HASH,
            'payload_hash' => self::placedHash(), 'rows_hash' => $unhashed ? '' : OrderLifecycle::rowsHash(self::rows(2)),
            'placed_fee' => FeeQuote::encodePlaced([], 1.0, 'NOK'),
        ]]);
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
        $postprocessing = new Postprocessing(new ScriptedSubscribers($handlers), $logger);
        $flow = new OrderFlow($client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $records, $logger);

        return [new OrderEdit($flow, $client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $records, $logger), $transport, $records];
    }

    private static function placedHash(): string
    {
        return self::snapshot(2);
    }

    /** The create payload's hash for the order with this many Widgets. */
    private static function snapshot(int $quantity): string
    {
        $input = self::input($quantity);

        return OrderFlow::payloadHash((new OrderBuilder())->build($input['order'], $input['lines'], $input['totals']));
    }

    /** @return list<string> */
    private static function calls(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls);
    }

    private static function get(string $state, string $status = 'APPROVED'): HttpResponse
    {
        return self::json(200, ['id' => self::ID, 'state' => $state, 'status' => $status]);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }

    /** @return array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>} */
    private static function rows(int $quantity): array
    {
        return [
            'products' => [['product_id' => '7', 'name' => 'Widget', 'quantity' => (string) $quantity, 'price' => '100.0000', 'total' => (string) (100 * $quantity), 'tax' => '25.0000']],
            'totals' => [['code' => 'total', 'title' => 'Total', 'value' => (string) (125 * $quantity)]],
            'addresses' => ['payment_city' => 'Oslo'],
        ];
    }

    /** @return array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>} */
    private static function input(int $quantity): array
    {
        return [
            'order' => [
                'order_id' => 42, 'currency' => 'NOK', 'currency_value' => 1.0, 'total' => 125 * $quantity, 'tax_total' => 25 * $quantity, 'date_added' => '2026-10-24 10:00:00',
                'company' => ['company_name' => 'Acme AS', 'organization_number' => '123456789', 'country_code' => 'NO'],
                'buyer' => ['email' => 'buyer@example.com', 'first_name' => 'Kari', 'last_name' => 'Nordmann', 'phone_number' => '+4799999999'],
                'billing_address' => ['street_address' => 'Gate 1', 'postal_code' => '0150', 'city' => 'Oslo', 'region' => '', 'country' => 'NO'],
                'merchant_urls' => [],
            ],
            'lines' => [['name' => 'Widget', 'quantity' => $quantity, 'price' => 100, 'total' => 100 * $quantity, 'tax' => 25, 'rate' => 0.25, 'type' => 'PHYSICAL']],
            'totals' => [],
        ];
    }
}
