<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\FeeQuote;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\TaxCodes;

/**
 * The hosted checkout flow against a scripted Two. Each case is Two's
 * answers, the outcome, and the requests that reached Two in order
 * ("METHOD /path").
 */
final class OrderFlowSpec
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';

    public static function runAll(): void
    {
        self::testCreate();
        self::testRecreate();
        self::testCreateKeyPerAttempt();
        self::testCreateKeepsTermsAndFee();
        self::testTaxCodeRecord();
        self::testConfirm();
        self::testCancel();
        self::testNewToken();
        self::testAfterCallback();
        self::testOwns();
        self::testAfterLockTimeout();
    }

    /** A hit that waited out the lock while another hit on the order was still talking to Two. */
    private static function testAfterLockTimeout(): void
    {
        $cases = [
            [true, 'success|', 'the other hit placed the order: the success page'],
            [false, 'cart|pending', 'still unplaced: the neutral notice, never the failure sentence'],
        ];
        foreach ($cases as [$placed, $expected, $description]) {
            $after = OrderFlow::afterLockTimeout($placed);
            TinyAssert::same($expected, $after['goto'] . '|' . ($after['notice'] ?? ''), 'lock timeout: ' . $description);
        }
    }

    private static function testCreate(): void
    {
        $created = self::json(201, ['id' => self::ID, 'state' => 'UNVERIFIED', 'status' => 'APPROVED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1']);
        $throws = ['m' => static function (array $p): array { throw new \RuntimeException('x'); }];
        $cases = [
            ['responses' => [$created], 'order' => [], 'handlers' => [], 'ok' => true, 'buyer' => null, 'calls' => ['POST /v1/order'], 'state' => 'UNVERIFIED', 'description' => 'created: the buyer goes to Two\'s payment URL'],
            ['responses' => [self::json(400, ['error_json' => [['loc' => ['buyer', 'representative', 'phone_number'], 'msg' => 'bad']]])], 'order' => [], 'handlers' => [], 'ok' => false, 'buyer' => ['error_two_field_phone_number'], 'calls' => ['POST /v1/order'], 'state' => '', 'description' => 'refused over a field: the field sentence'],
            ['responses' => [HttpResponse::unreachable('timeout')], 'order' => [], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => ['POST /v1/order'], 'state' => '', 'description' => 'Two not reached: the generic sentence'],
            ['responses' => [self::json(201, ['id' => self::ID, 'state' => 'UNVERIFIED'])], 'order' => [], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => ['POST /v1/order', 'POST /v1/order/' . self::ID . '/cancel'], 'state' => 'UNVERIFIED', 'description' => 'created without a payment URL: cancelled again'],
            ['responses' => [self::json(201, ['payment_url' => 'https://checkout.sandbox.two.inc/o/1'])], 'order' => [], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => ['POST /v1/order'], 'state' => '', 'description' => 'created without an order id: refused'],
            ['responses' => [], 'order' => ['company' => ['company_name' => 'Acme AS', 'organization_number' => ' ', 'country_code' => 'NO']], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::COMPANY_REQUIRED], 'calls' => [], 'state' => null, 'description' => 'no organisation number: asked for, nothing sent'],
            ['responses' => [], 'order' => ['total' => 300], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => [], 'state' => null, 'description' => 'a failed shop-match check: nothing sent'],
            ['responses' => [$created], 'order' => ['total' => 300], 'handlers' => ['m' => static fn (array $p): array => $p], 'ok' => true, 'buyer' => null, 'calls' => ['POST /v1/order'], 'state' => 'UNVERIFIED', 'description' => 'a merchant handler owns the shop-match checks'],
            ['responses' => [], 'order' => [], 'handlers' => $throws, 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => [], 'state' => null, 'description' => 'a faulting handler: nothing sent'],
            ['responses' => [], 'order' => ['currency_value' => 0], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => [], 'state' => null, 'description' => 'an order that cannot be built: nothing sent'],
            ['responses' => [], 'order' => ['company' => ['company_name' => str_repeat('A', 256), 'organization_number' => '123456789', 'country_code' => 'NO']], 'handlers' => [], 'ok' => false, 'buyer' => ['error_two_field_company_name'], 'calls' => [], 'state' => null, 'description' => 'a company name longer than the table holds: refused, never truncated'],
            ['responses' => [], 'order' => ['company' => ['company_name' => 'Acme AS', 'organization_number' => str_repeat('9', 65), 'country_code' => 'NO']], 'handlers' => [], 'ok' => false, 'buyer' => ['error_two_field_organization_number'], 'calls' => [], 'state' => null, 'description' => 'an organisation number longer than the table holds: refused'],
            ['responses' => [$created], 'order' => [], 'handlers' => [], 'lossy' => true, 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => [], 'state' => '', 'description' => 'a table that cannot hold the token: nothing sent'],
        ];
        foreach ($cases as $case) {
            $records = empty($case['lossy']) ? new MemoryRecords() : new TokenlessRecords();
            [$flow, $transport] = self::flow($case['responses'], $records, $case['handlers']);
            $result = $flow->create($case['order'] + self::order(), self::lines(2), [], self::TOKEN);
            $d = 'create: ' . $case['description'];
            TinyAssert::same($case['ok'], $result['ok'], $d . ': ok');
            TinyAssert::same($case['buyer'], $result['ok'] ? null : $result['buyer'], $d . ': buyer keys');
            TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
            TinyAssert::same($case['state'], $records->rows[42]['two_order_state'] ?? null, $d . ': stored state');
            self::assertKeys($transport, $d);
            foreach ($transport->calls as $call) {
                if ($call['method'] === 'POST' && parse_url($call['url'], PHP_URL_PATH) === '/v1/order') {
                    $body = json_decode((string) $call['body'], true) ?? [];
                    TinyAssert::true(!array_key_exists('invoice_type', $body), $d . ': the create body carries no invoice type');
                    TinyAssert::same([true, true], [array_key_exists('discount_amount', $body), array_key_exists('billing_address', $body)], $d . ': the create body still carries the order-level discount_amount and billing_address');
                }
            }
            if ($case['state'] !== null && empty($case['lossy'])) {
                TinyAssert::same(OrderFlow::tokenHash(self::TOKEN), $records->rows[42]['token_hash'], $d . ': the token hash is stored, not the token');
            }
            if ($result['ok']) {
                TinyAssert::same('https://checkout.sandbox.two.inc/o/1', $result['redirect'], $d . ': redirect');
            }
        }
    }

    /** The buyer placed the order again: the earlier Two order is cancelled before its id is overwritten. */
    private static function testRecreate(): void
    {
        $earlier = '5f1c2a7e-0000-4000-8000-000000000000';
        $created = self::json(201, ['id' => self::ID, 'state' => 'UNVERIFIED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1']);
        $cancel = 'POST /v1/order/' . $earlier . '/cancel';
        $read = 'GET /v1/order/' . $earlier;
        $cases = [
            ['state' => 'UNVERIFIED', 'responses' => [self::json(200, []), $created], 'ok' => true, 'calls' => [$cancel, 'POST /v1/order'], 'id' => self::ID, 'description' => 'the earlier order is cancelled, then the new one created'],
            ['state' => 'UNVERIFIED', 'responses' => [self::json(409, []), self::json(200, ['state' => 'CANCELLED']), $created], 'ok' => true, 'calls' => [$cancel, $read, 'POST /v1/order'], 'id' => self::ID, 'description' => 'the cancel is refused but Two holds it cancelled: created'],
            ['state' => 'UNVERIFIED', 'responses' => [self::json(500, []), self::json(200, ['state' => 'VERIFIED'])], 'ok' => false, 'calls' => [$cancel, $read], 'id' => $earlier, 'description' => 'the earlier order stays open at Two: refused, its id kept'],
            ['state' => 'CANCELLED', 'responses' => [$created], 'ok' => true, 'calls' => ['POST /v1/order'], 'id' => self::ID, 'description' => 'the earlier order is already cancelled: no cancel sent'],
        ];
        foreach ($cases as $case) {
            $records = new MemoryRecords([42 => ['token_hash' => 'old', 'two_order_id' => $earlier, 'two_order_state' => $case['state']]]);
            [$flow, $transport] = self::flow($case['responses'], $records);
            $result = $flow->create(self::order(), self::lines(2), [], self::TOKEN);
            $d = 're-create: ' . $case['description'];
            TinyAssert::same($case['ok'], $result['ok'], $d . ': ok');
            TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
            TinyAssert::same($case['id'], $records->rows[42]['two_order_id'], $d . ': stored Two order id');
            self::assertKeys($transport, $d, $earlier);
        }
    }

    /**
     * TWO-27110: create keeps the codes its 0% lines were sent with, and the
     * confirm check rebuilds with them, so a mapping changed while the buyer
     * is at Two's checkout no longer refuses the order. Each case: the record
     * the rebuild is given, and the outcome.
     */
    private static function testTaxCodeRecord(): void
    {
        $line = ['name' => 'Widget', 'quantity' => 2, 'price' => 100, 'total' => 200, 'tax' => 0, 'rate' => 0.0, 'type' => 'PHYSICAL', 'product_id' => 7, 'tax_key' => ['class' => 9, 'rates' => [['id' => 5, 'rate' => 0.0, 'type' => 'P']]]];
        $order = static fn (string $code, ?array $recorded): array => ['total' => 200, 'tax_total' => 0, 'tax_codes' => ['map' => (string) json_encode(['9|rate:5' => $code]), 'exempt' => false, 'recorded' => $recorded]] + self::order();
        $get = static fn (string $state): HttpResponse => self::json(200, ['id' => self::ID, 'state' => $state, 'status' => 'APPROVED']);
        $created = self::json(201, ['id' => self::ID, 'state' => 'UNVERIFIED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1']);
        $cases = [
            [true, [$created, $get('VERIFIED'), self::json(200, ['state' => 'CONFIRMED'])], OrderFlow::CONFIRMED, 'the record kept at create: the remapped order still confirms'],
            [false, [$created, self::json(200, []), $get('CANCELLED')], OrderFlow::CHANGED, 'control, an order with no record: the remapped rebuild differs and is refused'],
        ];
        foreach ($cases as [$withRecord, $responses, $outcome, $description]) {
            $records = new MemoryRecords();
            [$flow] = self::flow($responses, $records);
            $flow->create($order('ES_IVA_CANARY', null), [$line], [], self::TOKEN);
            $stored = $records->rows[42]['tax_codes'] ?? null;
            TinyAssert::same('{"product:7":"ES_IVA_CANARY"}', $stored, 'tax code record: ' . $description . ' (kept at create)');
            $recorded = $withRecord ? TaxCodes::decodeRecord((string) $stored) : null;
            $rebuild = static fn (array $record): array => ['order' => $order('ES_IVA_EXPORT', $recorded), 'lines' => [$line], 'totals' => []];
            $result = $flow->confirm(42, self::TOKEN, $rebuild, ['products' => [], 'totals' => [], 'addresses' => []]);
            TinyAssert::same($outcome, $result['outcome'], 'tax code record: ' . $description);
        }
    }

    /** One key per checkout attempt: each attempt has its own token, so the same cart and company never share a create key. */
    /** The order's term and whether it was placed with a fee are kept, so a rebuild judges the fee by what was placed. */
    private static function testCreateKeepsTermsAndFee(): void
    {
        $created = self::json(201, ['id' => self::ID, 'state' => 'UNVERIFIED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1']);
        $fee = ['code' => 'two_fee', 'title' => 'Payment terms fee - 30 days', 'value' => 10, 'tax' => 0, 'rate' => 0.0, 'tax_rates' => []];
        $cases = [
            [['terms' => ['type' => 'NET_TERMS', 'duration_days' => 30]], [$fee], ['{"type":"NET_TERMS","duration_days":30}', '1'], 'placed on a term with a fee'],
            [['terms' => ['type' => 'NET_TERMS', 'duration_days' => 60]], [], ['{"type":"NET_TERMS","duration_days":60}', '0'], 'placed on a term without a fee'],
            [[], [], ['', '0'], 'placed with no term'],
        ];
        foreach ($cases as [$over, $totals, $expected, $description]) {
            $records = new MemoryRecords();
            [$flow] = self::flow([$created], $records);
            $order = $over + self::order();
            $order['total'] = (float) $order['total'] + array_sum(array_column($totals, 'value'));
            $flow->create($order, self::lines(2), $totals, self::TOKEN);
            TinyAssert::same($expected, [$records->rows[42]['payment_terms'] ?? null, $records->rows[42]['payment_fee'] ?? null], 'create keeps the term and the fee: ' . $description);
            TinyAssert::same($totals === [] ? null : ['title' => 'Payment terms fee - 30 days', 'value' => 10.0, 'tax' => 0.0, 'rate' => 0.0, 'tax_rates' => []], FeeQuote::decodePlaced($records->rows[42]['placed_fee'] ?? '', (float) $order['currency_value'], (string) $order['currency']), 'create keeps the placed fee line: ' . $description);
            TinyAssert::same($order['currency'], FeeQuote::placedInOtherCurrency($records->rows[42] ?? [], 'XXX'), 'create keeps the order\'s currency code: ' . $description);
        }
    }

    private static function testCreateKeyPerAttempt(): void
    {
        $created = static fn (): HttpResponse => self::json(201, ['id' => self::ID, 'state' => 'UNVERIFIED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1']);
        $keys = [];
        foreach ([self::TOKEN, self::TOKEN, str_repeat('c', 48)] as $token) {
            [$flow, $transport] = self::flow([$created()], new MemoryRecords());
            $flow->create(self::order(), self::lines(2), [], $token);
            $keys[] = $transport->calls[0]['headers']['Idempotency-Key'] ?? '';
        }
        $cases = [
            [$keys[0], 'create_42_' . substr(OrderFlow::tokenHash(self::TOKEN), 0, 16), 'the shape: create, the shop order id, the start of the token hash'],
            [$keys[1], $keys[0], 'a retry of the same attempt (same token) reuses the key'],
            [$keys[2] !== $keys[0], true, 'a new attempt on the same cart and company gets a new key'],
            [str_contains($keys[0], self::TOKEN), false, 'the token itself never appears in the key'],
        ];
        foreach ($cases as [$actual, $expected, $description]) {
            TinyAssert::same($expected, $actual, 'create key: ' . $description);
        }
    }

    /**
     * Every write carries Idempotency-Key in its operation's shape; reads carry none.
     */
    private static function assertKeys(ScriptedTransport $transport, string $d, string $cancelled = self::ID): void
    {
        foreach ($transport->calls as $call) {
            $path = (string) parse_url($call['url'], PHP_URL_PATH);
            $expected = match (true) {
                $call['method'] === 'GET' => null,
                $path === '/v1/order' => 'create_42_' . substr(OrderFlow::tokenHash(self::TOKEN), 0, 16),
                str_ends_with($path, '/confirm') => 'confirm_' . self::ID,
                str_ends_with($path, '/cancel') => 'cancel_' . (str_contains($path, $cancelled) ? $cancelled : self::ID),
                default => 'unexpected write ' . $path,
            };
            TinyAssert::same($expected, $call['headers']['Idempotency-Key'] ?? null, $d . ': ' . $call['method'] . ' ' . $path . ' idempotency key');
        }
    }

    private static function testConfirm(): void
    {
        $get = static fn (string $state): HttpResponse => self::json(200, ['id' => self::ID, 'state' => $state, 'status' => 'APPROVED']);
        $paths = ['GET /v1/order/' . self::ID, 'POST /v1/order/' . self::ID . '/confirm'];
        $cancelPaths = ['POST /v1/order/' . self::ID . '/cancel', 'GET /v1/order/' . self::ID];
        $cases = [
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('VERIFIED'), self::json(200, ['state' => 'CONFIRMED'])], 'outcome' => OrderFlow::CONFIRMED, 'calls' => $paths, 'state' => 'CONFIRMED', 'description' => 'verified: confirmed with Two'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('CONFIRMED')], 'outcome' => OrderFlow::CONFIRMED, 'calls' => [$paths[0]], 'state' => 'CONFIRMED', 'description' => 'already confirmed: nothing more sent'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('CANCELLED')], 'outcome' => OrderFlow::CANCELLED, 'calls' => [$paths[0]], 'state' => 'CANCELLED', 'description' => 'cancelled at Two: the order is not confirmed'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('UNVERIFIED')], 'outcome' => OrderFlow::REFUSED, 'calls' => [$paths[0]], 'state' => 'UNVERIFIED', 'description' => 'not verified: the URL alone confirms nothing'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(500, [])], 'outcome' => OrderFlow::REFUSED, 'calls' => [$paths[0]], 'state' => '', 'description' => 'the state cannot be read'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('VERIFIED'), self::json(400, ['error_code' => 'X'])], 'outcome' => OrderFlow::REFUSED, 'calls' => $paths, 'state' => 'VERIFIED', 'description' => 'Two refuses the confirm'],
            ['row' => true, 'token' => str_repeat('b', 48), 'responses' => [], 'outcome' => OrderFlow::UNAUTHORISED, 'calls' => [], 'state' => '', 'description' => 'another token: unauthorised, nothing sent'],
            ['row' => true, 'token' => '', 'responses' => [], 'outcome' => OrderFlow::UNAUTHORISED, 'calls' => [], 'state' => '', 'description' => 'no token: unauthorised'],
            ['row' => false, 'token' => self::TOKEN, 'responses' => [], 'outcome' => OrderFlow::UNAUTHORISED, 'calls' => [], 'state' => null, 'description' => 'no record for the order: unauthorised'],
            ['row' => 'no-id', 'token' => self::TOKEN, 'responses' => [], 'outcome' => OrderFlow::REFUSED, 'calls' => [], 'state' => '', 'description' => 'no Two order was created: nothing to confirm'],
            ['row' => true, 'token' => self::TOKEN, 'rebuild' => [3, ['total' => 375, 'tax_total' => 75]], 'responses' => [self::json(200, []), $get('CANCELLED')], 'outcome' => OrderFlow::CHANGED, 'calls' => $cancelPaths, 'state' => 'CANCELLED', 'description' => 'the cart grew after create: not confirmed, the Two order cancelled'],
            ['row' => true, 'token' => self::TOKEN, 'rebuild' => [1, ['total' => 125, 'tax_total' => 25]], 'responses' => [self::json(200, []), $get('CANCELLED')], 'outcome' => OrderFlow::CHANGED, 'calls' => $cancelPaths, 'state' => 'CANCELLED', 'description' => 'the cart shrank after create: not confirmed'],
            ['row' => true, 'token' => self::TOKEN, 'rebuild' => [2, ['billing_address' => ['city' => 'Bergen'] + self::order()['billing_address']]], 'responses' => [self::json(200, []), $get('CANCELLED')], 'outcome' => OrderFlow::CHANGED, 'calls' => $cancelPaths, 'state' => 'CANCELLED', 'description' => 'the address changed after create: not confirmed'],
            ['row' => true, 'token' => self::TOKEN, 'rebuild' => null, 'responses' => [self::json(200, []), $get('CANCELLED')], 'outcome' => OrderFlow::REFUSED, 'calls' => $cancelPaths, 'state' => 'CANCELLED', 'description' => 'the shop order is gone: not confirmed'],
        ];
        self::runCallbackCases('confirm', $cases);
    }

    private static function testCancel(): void
    {
        $cancelled = self::json(200, ['id' => self::ID, 'state' => 'CANCELLED']);
        $paths = ['POST /v1/order/' . self::ID . '/cancel', 'GET /v1/order/' . self::ID];
        $cases = [
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(200, []), $cancelled], 'outcome' => OrderFlow::CANCELLED, 'calls' => $paths, 'state' => 'CANCELLED', 'description' => 'cancelled and read back as cancelled'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(200, []), self::json(200, ['state' => 'VERIFIED'])], 'outcome' => OrderFlow::REFUSED, 'calls' => $paths, 'state' => 'VERIFIED', 'description' => 'Two still holds it verified: not cancelled'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(409, []), self::json(200, ['state' => 'VERIFIED'])], 'outcome' => OrderFlow::REFUSED, 'calls' => $paths, 'state' => 'VERIFIED', 'description' => 'Two refuses the cancel and holds the order verified'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(409, []), $cancelled], 'outcome' => OrderFlow::CANCELLED, 'calls' => $paths, 'state' => 'CANCELLED', 'description' => 'already cancelled at Two: the buyer is told it is cancelled'],
            ['row' => 'no-id', 'token' => self::TOKEN, 'responses' => [], 'outcome' => OrderFlow::CANCELLED, 'calls' => [], 'state' => '', 'description' => 'no Two order was created: nothing to cancel'],
            ['row' => true, 'token' => str_repeat('b', 48), 'responses' => [], 'outcome' => OrderFlow::UNAUTHORISED, 'calls' => [], 'state' => '', 'description' => 'another token: unauthorised, nothing sent'],
        ];
        self::runCallbackCases('cancel', $cases);
    }

    /** @param list<array<string, mixed>> $cases */
    private static function runCallbackCases(string $kind, array $cases): void
    {
        foreach ($cases as $case) {
            $rows = [];
            if ($case['row'] !== false) {
                $rows[42] = ['token_hash' => OrderFlow::tokenHash(self::TOKEN), 'payload_hash' => OrderFlow::payloadHash((new OrderBuilder())->build(self::order(), self::lines(2), [])), 'two_order_id' => $case['row'] === 'no-id' ? '' : self::ID, 'two_order_state' => '', 'company_name' => 'Acme AS'];
            }
            $records = new MemoryRecords($rows);
            [$flow, $transport] = self::flow($case['responses'], $records);
            $spec = array_key_exists('rebuild', $case) ? $case['rebuild'] : [2, []];
            $rebuild = static fn (array $record): ?array => $spec === null ? null : ['order' => $spec[1] + self::order(), 'lines' => self::lines($spec[0]), 'totals' => []];
            $rows = ['products' => [['product_id' => '7', 'quantity' => '2']], 'totals' => [], 'addresses' => ['payment_city' => 'Oslo']];
            $result = $kind === 'confirm' ? $flow->confirm(42, $case['token'], $rebuild, $rows) : $flow->cancel(42, $case['token']);
            $d = $kind . ': ' . $case['description'];
            TinyAssert::same($case['outcome'], $result['outcome'], $d . ': outcome');
            TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
            TinyAssert::same($case['state'], $records->rows[42]['two_order_state'] ?? null, $d . ': stored state');
            foreach ($transport->calls as $call) {
                if ($call['method'] === 'POST') {
                    TinyAssert::same('{}', $call['body'], $d . ': ' . (string) parse_url($call['url'], PHP_URL_PATH) . ' sends an empty object');
                }
            }
            self::assertKeys($transport, $d);
            if ($kind === 'confirm') {
                $kept = $result['outcome'] === OrderFlow::CONFIRMED ? OrderLifecycle::rowsHash($rows) : null;
                TinyAssert::same($kept, $records->rows[42]['rows_hash'] ?? null, $d . ': the rows hash is kept once Two holds the order as placed');
            }
        }
    }

    /** What the adapter does after a callback; the double-hit rows are the second hit on an order the first placed. */
    private static function testAfterCallback(): void
    {
        $c = OrderFlow::CALLBACK_CONFIRM;
        $x = OrderFlow::CALLBACK_CANCEL;
        $cases = [
            [$c, OrderFlow::CONFIRMED, false, 'place|success|', 'confirmed: place the order'],
            [$c, OrderFlow::CONFIRMED, true, 'none|success|', 'double hit, Two confirmed: nothing written'],
            [$c, OrderFlow::REFUSED, true, 'none|success|', 'double hit, the second confirm failed: nothing written over the placed order'],
            [$c, OrderFlow::REFUSED, false, 'note|cart|generic', 'refused: noted on the unplaced order'],
            [$c, OrderFlow::CANCELLED, false, 'note|cart|cancelled', 'cancelled at Two on return'],
            [$c, OrderFlow::CHANGED, false, 'note|cart|changed', 'the cart changed: noted, the buyer told to review the cart'],
            [$c, OrderFlow::UNAUTHORISED, true, 'none|cart|generic', 'unauthorised, even on a placed order'],
            [$x, OrderFlow::CANCELLED, false, 'note|cart|cancelled', 'cancel: noted, back to the cart'],
            [$x, OrderFlow::REFUSED, false, 'note|cart|generic', 'cancel refused: noted'],
            [$x, OrderFlow::CANCELLED, true, 'none|cart|generic', 'cancel on a placed order writes nothing'],
            [$x, OrderFlow::UNAUTHORISED, false, 'none|cart|generic', 'cancel unauthorised'],
        ];
        foreach ($cases as [$kind, $outcome, $placed, $expected, $description]) {
            $after = OrderFlow::afterCallback($kind, $outcome, $placed);
            $action = $after['place'] ? 'place' : ($after['note'] ? 'note' : 'none');
            TinyAssert::same($expected, $action . '|' . $after['goto'] . '|' . ($after['notice'] ?? ''), 'after callback: ' . $description);
        }
    }

    private static function testOwns(): void
    {
        [$flow, $transport] = self::flow([], new MemoryRecords([42 => ['token_hash' => OrderFlow::tokenHash(self::TOKEN), 'two_order_id' => self::ID]]));
        TinyAssert::true($flow->owns(42, self::TOKEN), 'owns: the order\'s own token');
        TinyAssert::true(!$flow->owns(42, str_repeat('b', 48)), 'owns: another token');
        TinyAssert::true(!$flow->owns(43, self::TOKEN), 'owns: another order');
        TinyAssert::same([], self::calls($transport), 'owns asks Two nothing');
    }

    /** @return list<array<string, mixed>> */
    private static function lines(int $quantity): array
    {
        return [['name' => 'Widget', 'quantity' => $quantity, 'price' => 100, 'total' => 100 * $quantity, 'tax' => 25, 'rate' => 0.25, 'type' => 'PHYSICAL']];
    }

    private static function testNewToken(): void
    {
        $token = OrderFlow::newToken();
        TinyAssert::true(preg_match('~^[0-9a-f]{48}\z~', $token) === 1, 'a token is 48 hex characters, the shape the callbacks accept');
        TinyAssert::true($token !== OrderFlow::newToken(), 'tokens are fresh each time');
    }

    /**
     * @param list<HttpResponse>                                       $responses
     * @param array<string, \Closure(array<string, mixed>, array<string, mixed>): mixed> $handlers
     * @return array{0: OrderFlow, 1: ScriptedTransport}
     */
    private static function flow(array $responses, \Opencart\System\Library\Extension\Two\Core\OrderRecords $records, array $handlers = []): array
    {
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
        $flow = new OrderFlow($client, new OrderBuilder(), new Postprocessing(new ScriptedSubscribers($handlers), $logger), new ErrorMapper(), $records, $logger);

        return [$flow, $transport];
    }

    /** @return list<string> */
    private static function calls(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
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

/** An order table missing the token column, as a legacy table would be. */
final class TokenlessRecords implements \Opencart\System\Library\Extension\Two\Core\OrderRecords
{
    /** @var array<int, array<string, string>> */
    public array $rows = [];

    public function get(int $orderId): ?array
    {
        return $this->rows[$orderId] ?? null;
    }

    public function put(int $orderId, array $fields): void
    {
        unset($fields['token_hash']);
        $this->rows[$orderId] = $fields + ($this->rows[$orderId] ?? []);
    }
}
