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

/**
 * Whole-order fulfil and cancel off the status maps, and the invoice
 * download, against a scripted Two. Each case is the stored Two state,
 * Two's answers, the outcome, the requests that reached Two in order
 * ("METHOD /path") and the state stored afterwards.
 */
final class OrderLifecycleSpec
{
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';

    public static function runAll(): void
    {
        self::testAction();
        self::testRowsHash();
        self::testFulfil();
        self::testCancel();
        self::testInvoice();
    }

    private static function testAction(): void
    {
        $cases = [
            [3, [3, 5], 7, OrderLifecycle::FULFIL, 'a mapped fulfilment status'],
            [5, [3, 5], 7, OrderLifecycle::FULFIL, 'the second mapped fulfilment status'],
            [7, [3, 5], 7, OrderLifecycle::CANCEL, 'the mapped cancel status'],
            [3, [3], 3, OrderLifecycle::FULFIL, 'a status in both maps fulfils'],
            [2, [3, 5], 7, null, 'an unmapped status asks nothing'],
            [7, [3], null, null, 'no cancel status mapped'],
            [0, [0], 0, null, 'status 0, an unplaced order, never acts'],
            [11, [3], 7, OrderLifecycle::REFUND, 'the mapped refund status refunds the rest', 11],
            [7, [3], 7, OrderLifecycle::CANCEL, 'a status in the cancel and refund maps cancels', 7],
            [3, [3], 7, OrderLifecycle::FULFIL, 'a status in the fulfil and refund maps fulfils', 3],
            [11, [3], 7, null, 'no refund status mapped', null],
        ];
        foreach ($cases as $case) {
            [$status, $fulfil, $cancel, $expected, $description] = $case;
            TinyAssert::same($expected, OrderLifecycle::action($status, $fulfil, $cancel, $case[5] ?? null), 'action: ' . $description);
        }
    }

    /** Only a change in the order's own rows counts. */
    private static function testRowsHash(): void
    {
        $base = OrderLifecycle::rowsHash(self::rows());
        $two = self::rows();
        $two['products'][] = ['product_id' => '8', 'name' => 'Gadget', 'model' => 'G', 'quantity' => '1', 'price' => '10.0000', 'total' => '10.0000', 'tax' => '2.5000'];
        $reordered = $two;
        $reordered['products'] = array_reverse($reordered['products']);
        $cases = [
            [self::rows(), true, 'the same rows'],
            [self::rows(['price' => '101.0000']), false, 'a price edited'],
            [self::rows(['quantity' => '3']), false, 'a quantity edited'],
            [self::rows([], ['value' => '260.0000']), false, 'a total edited'],
            [self::rows([], [], ['shipping_city' => 'Bergen']), false, 'the shipping address edited'],
            [self::rows([], [], ['payment_postcode' => '0151']), false, 'the billing address edited'],
        ];
        foreach ($cases as [$rows, $same, $description]) {
            TinyAssert::same($same, OrderLifecycle::rowsHash($rows) === $base, 'rows hash: ' . $description);
        }
        TinyAssert::same(OrderLifecycle::rowsHash($two), OrderLifecycle::rowsHash($reordered), 'rows hash: rows re-written in another order hash the same');
    }

    private static function testFulfil(): void
    {
        $get = self::get(...);
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/fulfillments';
        $throws = ['m' => static function (array $p, array $c): array {
            if ($c['request_type'] === Postprocessing::CAPTURE) {
                throw new \RuntimeException('x');
            }

            return $p;
        }];
        $cases = [
            ['stored' => 'CONFIRMED', 'responses' => [$get('CONFIRMED'), self::json(201, []), $get('FULFILLED')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => 'FULFILLED', 'description' => 'confirmed: fulfilled and read back'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('CONFIRMED'), self::json(201, []), self::json(500, [])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => 'FULFILLING', 'description' => 'fulfilled, the read back fails: marked as asked for, so a repeat skips'],
            ['stored' => 'FULFILLED', 'responses' => [], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [], 'state' => 'FULFILLED', 'description' => 'already fulfilled: a repeat event or re-save sends nothing'],
            ['stored' => 'DELIVERED', 'responses' => [], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [], 'state' => 'DELIVERED', 'description' => 'delivered counts as fulfilled: nothing sent'],
            ['stored' => 'FULFILLING', 'responses' => [], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [], 'state' => 'FULFILLING', 'description' => 'fulfilment already asked for: nothing sent'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('FULFILLED')], 'outcome' => OrderLifecycle::ALREADY, 'calls' => [$read], 'state' => 'FULFILLED', 'description' => 'fulfilled at Two after an earlier refusal: nothing sent, noted'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('DELIVERED')], 'outcome' => OrderLifecycle::ALREADY, 'calls' => [$read], 'state' => 'DELIVERED', 'description' => 'delivered at Two: nothing sent, noted'],
            ['stored' => 'CANCELLED', 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'CANCELLED', 'description' => 'cancelled: refused, nothing sent'],
            ['stored' => null, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => null, 'description' => 'no Two order linked: refused, nothing sent'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(500, [])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'state' => 'CONFIRMED', 'description' => 'Two\'s state cannot be read: refused, nothing sent'],
            ['stored' => 'VERIFIED', 'responses' => [$get('VERIFIED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'state' => 'VERIFIED', 'description' => 'not confirmed at Two: refused'],
            ['stored' => 'CONFIRMED', 'rows' => self::rows(['quantity' => '3']), 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'CONFIRMED', 'reason' => 'edited after it was placed', 'description' => 'the order was edited after it was placed: refused, nothing asked'],
            ['stored' => 'CONFIRMED', 'rows' => self::rows() + ['currency' => 'EUR'], 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'CONFIRMED', 'reason' => 'not fulfilled: the order was placed with Two in NOK and its currency is now EUR', 'description' => 'a currency-only edit (the rows alike): refused with the currency, nothing asked'],
            ['stored' => 'CONFIRMED', 'rows' => self::rows() + ['currency' => 'NOK'], 'responses' => [$get('CONFIRMED'), self::json(201, []), $get('FULFILLED')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => 'FULFILLED', 'description' => 'the currency switched back: fulfilled as usual'],
            ['stored' => 'CONFIRMED', 'rows' => null, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'CONFIRMED', 'description' => 'the shop order is gone: refused'],
            ['stored' => 'CONFIRMED', 'unknown_edit' => true, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'CONFIRMED', 'reason' => 'fulfil the order in the Two merchant portal', 'description' => 'an edit with an unknown outcome: refused until Two has the order, even with the rows back as placed'],
            ['stored' => 'CONFIRMED', 'unhashed' => true, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'CONFIRMED', 'reason' => 'merchant portal', 'description' => 'no rows hash kept (placed before it was stored): refused, never fulfilled unchecked'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('CONFIRMED'), self::json(400, ['error_code' => 'X'])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $post], 'state' => 'CONFIRMED', 'description' => 'Two refuses the fulfil: refused, state kept so a re-save retries'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('CONFIRMED'), HttpResponse::unreachable('timeout')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $post], 'state' => 'CONFIRMED', 'description' => 'Two not reached: refused'],
            ['stored' => 'CONFIRMED', 'handlers' => $throws, 'responses' => [$get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'state' => 'CONFIRMED', 'description' => 'the hook refuses the capture: nothing sent'],
        ];
        foreach ($cases as $case) {
            [$lifecycle, $transport, $records, $logger] = self::lifecycle($case['responses'], $case['stored'], $case['handlers'] ?? [], !empty($case['unhashed']));
            if (!empty($case['unknown_edit'])) {
                $records->rows[42]['payload_hash'] = '';
            }
            $result = $lifecycle->fulfil(42, array_key_exists('rows', $case) ? $case['rows'] : self::rows());
            $d = 'fulfil: ' . $case['description'];
            self::check($d, $case, $result, $transport, $records, $logger);
            if (isset($case['reason'])) {
                TinyAssert::true(str_contains($result['reason'], $case['reason']), $d . ': the history note says why');
            }
            if (in_array($post, self::calls($transport), true)) {
                TinyAssert::same('fulfil_' . self::ID, $transport->calls[1]['headers']['Idempotency-Key'] ?? null, $d . ': idempotency key');
                TinyAssert::same('{}', $transport->calls[1]['body'], $d . ': the body is an empty object');
            }
        }
    }

    private static function testCancel(): void
    {
        $get = self::get(...);
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/cancel';
        $cases = [
            ['stored' => 'CONFIRMED', 'responses' => [self::json(200, []), $get('CANCELLED')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$post, $read], 'state' => 'CANCELLED', 'description' => 'confirmed: cancelled and read back'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(409, []), $get('CANCELLED')], 'outcome' => OrderLifecycle::ALREADY, 'calls' => [$post, $read], 'state' => 'CANCELLED', 'description' => 'already cancelled at Two: counts as cancelled, noted'],
            ['stored' => 'DELIVERED', 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'DELIVERED', 'description' => 'delivered: refused, no cancel attempted'],
            ['stored' => 'CONFIRMED', 'responses' => [new HttpResponse(202, '""'), $get('CANCELLED')], 'outcome' => OrderLifecycle::ALREADY, 'reason' => ' is already cancelled.', 'calls' => [$post, $read], 'state' => 'CANCELLED', 'description' => 'Two answers 202 (already cancelled): noted as already cancelled'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(200, []), $get('CANCELLED')], 'outcome' => OrderLifecycle::DONE, 'reason' => ' cancelled.', 'calls' => [$post, $read], 'state' => 'CANCELLED', 'description' => 'Two answers 200: noted as cancelled'],
            ['stored' => 'CANCELLED', 'responses' => [], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [], 'state' => 'CANCELLED', 'description' => 'already cancelled: a repeat event sends nothing'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(409, ['error_message' => 'Not allowed']), $get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'reason' => ' not cancelled: HTTP 409: Not allowed. Two holds it as CONFIRMED. Save the status again to retry.', 'calls' => [$post, $read], 'state' => 'CONFIRMED', 'description' => 'refused and read back: Two\'s reason, one full stop, then the state'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(409, ['error_message' => 'Not allowed']), self::json(500, [])], 'outcome' => OrderLifecycle::REFUSED, 'reason' => ' not cancelled: HTTP 409: Not allowed. Two could not be asked for its state. Save the status again to retry.', 'calls' => [$post, $read], 'state' => 'CONFIRMED', 'description' => 'refused and not read back: the note names no state'],
            ['stored' => 'FULFILLED', 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'FULFILLED', 'description' => 'fulfilled: refused, nothing sent'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(200, []), $get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$post, $read], 'state' => 'CONFIRMED', 'description' => 'Two still holds it confirmed: refused'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(400, ['error_code' => 'X']), $get('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$post, $read], 'state' => 'FULFILLED', 'description' => 'fulfilled at Two meanwhile: refused'],
            ['stored' => null, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => null, 'description' => 'no Two order linked: refused, nothing sent'],
        ];
        foreach ($cases as $case) {
            [$lifecycle, $transport, $records, $logger] = self::lifecycle($case['responses'], $case['stored']);
            $result = $lifecycle->cancel(42);
            self::check('cancel: ' . $case['description'], $case, $result, $transport, $records, $logger);
            if (isset($case['reason'])) {
                TinyAssert::true(str_ends_with($result['reason'], $case['reason']), 'cancel: ' . $case['description'] . ': the history note (got ' . $result['reason'] . ')');
            }
            if (in_array($post, self::calls($transport), true)) {
                TinyAssert::same('cancel_' . self::ID, $transport->calls[0]['headers']['Idempotency-Key'] ?? null, 'cancel: ' . $case['description'] . ': idempotency key');
                TinyAssert::same('{}', $transport->calls[0]['body'], 'cancel: ' . $case['description'] . ': the body is an empty object');
            }
        }
    }

    private static function testInvoice(): void
    {
        $pdf = new HttpResponse(200, "%PDF-1.7\n...");
        $notFulfilled = self::json(400, ['error_code' => 'ORDER_NOT_FULFILLED']);
        $read = 'GET /v1/order/' . self::ID;
        $fetch = 'GET /v1/invoice/' . self::ID . '/pdf';
        $cases = [
            ['stored' => 'FULFILLED', 'responses' => [$pdf], 'expected' => 'pdf', 'calls' => [$fetch], 'description' => 'fulfilled: the PDF'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::get('FULFILLING')], 'expected' => OrderLifecycle::INVOICE_NOT_READY, 'calls' => [$fetch, $read], 'description' => 'ORDER_NOT_FULFILLED while fulfilling: not ready yet'],
            ['stored' => 'CONFIRMED', 'responses' => [$notFulfilled, self::get('CONFIRMED')], 'expected' => OrderLifecycle::INVOICE_UNAVAILABLE_STATE . ':CONFIRMED', 'calls' => [$fetch, $read], 'description' => 'ORDER_NOT_FULFILLED, not fulfilled: names the state'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::get('FULFILLED'), $pdf], 'expected' => 'pdf', 'calls' => [$fetch, $read, $fetch], 'description' => 'ORDER_NOT_FULFILLED, fulfilled just now: asked once more'],
            ['stored' => 'FULFILLED', 'responses' => [$notFulfilled, self::get('DELIVERED'), $pdf], 'expected' => 'pdf', 'calls' => [$fetch, $read, $fetch], 'description' => 'ORDER_NOT_FULFILLED, delivered: invoiced, asked once more'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::get('FULFILMENT_NEEDS_MANUAL_RESOLUTION')], 'expected' => OrderLifecycle::INVOICE_NOT_READY, 'calls' => [$fetch, $read], 'description' => 'ORDER_NOT_FULFILLED while fulfilment needs manual resolution: not ready yet'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::get('FULFILLED'), $notFulfilled], 'expected' => OrderLifecycle::INVOICE_ERROR, 'calls' => [$fetch, $read, $fetch], 'description' => 'fulfilled but still no invoice on the retry: an error, no loop'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::json(500, [])], 'expected' => OrderLifecycle::INVOICE_ERROR, 'calls' => [$fetch, $read], 'description' => 'ORDER_NOT_FULFILLED and the state cannot be read: an error'],
            ['stored' => 'FULFILLED', 'responses' => [self::json(500, [])], 'expected' => OrderLifecycle::INVOICE_ERROR, 'calls' => [$fetch], 'description' => 'Two fails: an error'],
            ['stored' => 'FULFILLED', 'responses' => [new HttpResponse(200, '<html>login</html>')], 'expected' => OrderLifecycle::INVOICE_ERROR, 'calls' => [$fetch], 'description' => 'a 200 that is not a PDF is never served as one'],
            ['stored' => null, 'responses' => [], 'expected' => OrderLifecycle::INVOICE_NO_REFERENCE, 'calls' => [], 'description' => 'no Two order linked: nothing asked'],
        ];
        foreach ($cases as $case) {
            [$lifecycle, $transport] = self::lifecycle($case['responses'], $case['stored']);
            $result = $lifecycle->invoice(42);
            $actual = isset($result['pdf']) ? 'pdf' : $result['notice'] . ($result['notice'] === OrderLifecycle::INVOICE_UNAVAILABLE_STATE ? ':' . $result['state'] : '');
            $d = 'invoice: ' . $case['description'];
            TinyAssert::same($case['expected'], $actual, $d . ': outcome');
            TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
            foreach ($transport->calls as $call) {
                TinyAssert::true(!isset($call['headers']['Idempotency-Key']), $d . ': a read carries no idempotency key');
                if (str_contains($call['url'], '/v1/invoice/')) {
                    TinyAssert::same('application/pdf', $call['headers']['Accept'] ?? null, $d . ': asks for a PDF');
                    TinyAssert::true(str_contains($call['url'], '/pdf?v=original&proxy=true&'), $d . ': asks for the original, streamed by Two (no redirect)');
                }
            }
        }
    }

    /**
     * @param array<string, mixed>                                    $case
     * @param array{outcome: string, reason: string}                  $result
     */
    private static function check(string $d, array $case, array $result, ScriptedTransport $transport, MemoryRecords $records, RecordingLogger $logger): void
    {
        TinyAssert::same($case['outcome'], $result['outcome'], $d . ': outcome');
        TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
        TinyAssert::same($case['state'], $records->rows[42]['two_order_state'] ?? null, $d . ': stored state');
        TinyAssert::true($result['outcome'] === OrderLifecycle::SKIPPED || $result['reason'] !== '', $d . ': a reason for the order history');
        TinyAssert::true($result['outcome'] !== OrderLifecycle::REFUSED || $logger->at('error') !== [], $d . ': a refusal is logged');
    }

    /**
     * @param list<HttpResponse>                                       $responses
     * @param array<string, \Closure(array<string, mixed>, array<string, mixed>): mixed> $handlers
     * @return array{0: OrderLifecycle, 1: ScriptedTransport, 2: MemoryRecords, 3: RecordingLogger}
     */
    private static function lifecycle(array $responses, ?string $stored, array $handlers = [], bool $unhashed = false): array
    {
        $rows = $stored === null ? [] : [42 => [
            'token_hash' => OrderFlow::tokenHash(str_repeat('a', 48)),
            'payload_hash' => OrderFlow::payloadHash((new OrderBuilder())->build(self::order(), self::lines(2), [])),
            'two_order_id' => self::ID, 'two_order_state' => $stored, 'company_name' => 'Acme AS',
            'rows_hash' => $unhashed ? '' : OrderLifecycle::rowsHash(self::rows()),
            'placed_fee' => FeeQuote::encodePlaced([], 1.0, 'NOK'),
        ]];
        $records = new MemoryRecords($rows);
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
        $postprocessing = new Postprocessing(new ScriptedSubscribers($handlers), $logger);
        $flow = new OrderFlow($client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $records, $logger);

        return [new OrderLifecycle($flow, $client, $postprocessing, new ErrorMapper(), $records, $logger), $transport, $records, $logger];
    }

    /** @return list<string> */
    private static function calls(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls);
    }

    private static function get(string $state): HttpResponse
    {
        return self::json(200, ['id' => self::ID, 'state' => $state, 'status' => 'APPROVED']);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }

    /**
     * The order's own rows as the shop stores them, with overrides on the product, the total and the addresses.
     *
     * @param array<string, string> $product
     * @param array<string, string> $total
     * @param array<string, string> $addresses
     * @return array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>}
     */
    private static function rows(array $product = [], array $total = [], array $addresses = []): array
    {
        return [
            'products' => [$product + ['product_id' => '7', 'name' => 'Widget', 'model' => 'W1', 'quantity' => '2', 'price' => '100.0000', 'total' => '200.0000', 'tax' => '25.0000']],
            'totals' => [['code' => 'sub_total', 'title' => 'Sub-Total', 'value' => '200.0000'], $total + ['code' => 'total', 'title' => 'Total', 'value' => '250.0000']],
            'addresses' => $addresses + ['payment_city' => 'Oslo', 'payment_postcode' => '0150', 'payment_country_id' => '160', 'shipping_city' => 'Oslo', 'shipping_postcode' => '0150', 'shipping_country_id' => '160'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function lines(int $quantity): array
    {
        return [['name' => 'Widget', 'quantity' => $quantity, 'price' => 100, 'total' => 100 * $quantity, 'tax' => 25, 'rate' => 0.25, 'type' => 'PHYSICAL']];
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
