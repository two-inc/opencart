<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\FeeQuote;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderEdit;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\OrderRefunds;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Opencart\OrderStatusEvent;

/**
 * The order history event handler against a scripted shop and Two. Maps:
 * Shipped (3) fulfils, Canceled (7) cancels; the shop's void status is 16.
 * Each case is the order's payment code and status and the stored Two
 * state, then the requests that reached Two and the history notes written.
 */
final class OrderStatusEventSpec
{
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';
    private const VOID = 16;

    public static function runAll(): void
    {
        self::testCases();
        self::testRefundAndEdit();
        self::testRecursionGuard();
        self::testThrowable();
    }

    private static function testCases(): void
    {
        $cancelled = [new HttpResponse(200, '{}'), new HttpResponse(200, '{"state":"CANCELLED"}')];
        $cancel = ['POST /v1/order/' . self::ID . '/cancel', 'GET /v1/order/' . self::ID];
        $cases = [
            ['code' => 'two.two', 'status' => 7, 'stored' => 'CONFIRMED', 'responses' => $cancelled, 'calls' => $cancel, 'notes' => ['7|Two order ' . self::ID . ' cancelled.'], 'description' => 'the mapped cancel status cancels, noted at that status'],
            ['code' => 'two.two', 'status' => self::VOID, 'stored' => 'CONFIRMED', 'cancel' => self::VOID, 'responses' => $cancelled, 'calls' => [], 'notes' => [], 'description' => 'the void status (an unplaced order) never cancels, even when mapped'],
            ['code' => 'two.two', 'status' => 0, 'stored' => 'CONFIRMED', 'responses' => $cancelled, 'calls' => [], 'notes' => [], 'description' => 'status 0 (a refused confirm\'s note) does nothing'],
            ['code' => 'cod.cod', 'status' => 7, 'stored' => 'CONFIRMED', 'responses' => $cancelled, 'calls' => [], 'notes' => [], 'description' => 'an order paid another way is left alone'],
            ['code' => 'two.two', 'status' => 2, 'stored' => 'CONFIRMED', 'responses' => $cancelled, 'calls' => [], 'notes' => [], 'description' => 'an unmapped status does nothing'],
            ['code' => 'two.two', 'status' => 3, 'stored' => 'FULFILLED', 'responses' => [], 'calls' => [], 'notes' => [], 'description' => 'a re-save of a fulfilled order: nothing sent, no note'],
            ['code' => 'two.two', 'status' => 3, 'stored' => 'CONFIRMED', 'responses' => [new HttpResponse(200, '{"state":"FULFILLED"}')], 'calls' => ['GET /v1/order/' . self::ID], 'notes' => ['3|Two order ' . self::ID . ' is already FULFILLED.'], 'description' => 'a re-save after an earlier refusal, already fulfilled at Two: a short note'],
        ];
        foreach ($cases as $case) {
            $shop = self::shop($case['code'], $case['status'], $case['stored'], $case['responses'], $case['cancel'] ?? 7);
            $shop['event']->handle(42);
            $d = 'status event: ' . $case['description'];
            TinyAssert::same($case['calls'], self::calls($shop['transport']), $d . ': requests');
            TinyAssert::same($case['notes'], $shop['notes']->list, $d . ': history notes');
        }
    }

    /** The refund status refunds the rest; an edit is synced before the status action, each with its own note. */
    private static function testRefundAndEdit(): void
    {
        $two = static fn (string $state, array $refunds): HttpResponse => new HttpResponse(200, (string) json_encode(['id' => self::ID, 'state' => $state, 'currency' => 'NOK', 'gross_amount' => '250.00', 'refunds' => $refunds]));
        $read = 'GET /v1/order/' . self::ID;
        $changed = 'the order was placed with Two in NOK and its currency is now EUR. Two keeps an order in the currency it was placed in: set the order\'s currency back to NOK and ';
        $editNote = 'The edit to this order was not sent to Two: ' . $changed . 'save it again to send the edit.';
        $cases = [
            ['status' => 11, 'stored' => 'FULFILLED', 'edited' => false, 'responses' => [$two('FULFILLED', []), new HttpResponse(201, '{"id":"r1"}'), $two('REFUNDED', [['id' => 'r1', 'total_amount' => '-250.00']])],
                'calls' => [$read, 'POST /v1/order/' . self::ID . '/refund', $read], 'notes' => ['11|Two refunded 250.00 NOK on order ' . self::ID . '. The credit note is on the order page under Two.'], 'description' => 'the mapped refund status refunds the rest, noted'],
            ['status' => 3, 'stored' => 'CONFIRMED', 'edited' => true, 'responses' => [new HttpResponse(200, '{"state":"CONFIRMED","status":"APPROVED"}')],
                'calls' => [$read], 'notes' => ['3|The edit to this order was not sent to Two: the order can no longer be read.', '3|Two order ' . self::ID . ' not fulfilled: the order was edited after it was placed with Two. Two still holds the order as placed.'], 'description' => 'an edit that cannot be sent is noted, and the fulfil then refuses'],
            ['status' => 2, 'stored' => 'FULFILLED', 'edited' => true, 'responses' => [],
                'calls' => [], 'notes' => ['2|Two has invoiced all or part of order ' . self::ID . ', so this order\'s edit was not sent to Two. Two invoices the order as it holds it; refund any difference from the Two tab.'], 'description' => 'an edit after fulfilment is refused with a note, even at an unmapped status'],
            ['status' => 3, 'stored' => 'CONFIRMED', 'edited' => false, 'currency' => 'EUR', 'responses' => [],
                'calls' => [], 'notes' => ['3|' . $editNote, '3|Two order ' . self::ID . ' not fulfilled: ' . $changed . 'save the status again.'], 'description' => 'a currency-only edit (the rows alike, in the default currency): the edit and the fulfil are both refused with the currency, nothing asked'],
            ['status' => 7, 'stored' => 'CONFIRMED', 'edited' => false, 'currency' => 'EUR', 'responses' => [new HttpResponse(200, '{}'), new HttpResponse(200, '{"state":"CANCELLED"}')],
                'calls' => ['POST /v1/order/' . self::ID . '/cancel', $read], 'notes' => ['7|' . $editNote, '7|Two order ' . self::ID . ' cancelled.'], 'description' => 'a currency-only edit then the cancel status: the edit is refused, the cancel still goes through'],
            ['status' => 3, 'stored' => 'CONFIRMED', 'edited' => false, 'currency' => 'NOK', 'responses' => [new HttpResponse(200, '{"state":"CONFIRMED","status":"APPROVED"}'), new HttpResponse(201, '{}'), new HttpResponse(200, '{"state":"FULFILLED","status":"APPROVED"}')],
                'calls' => [$read, 'POST /v1/order/' . self::ID . '/fulfillments', $read], 'notes' => 'fulfilled', 'description' => 'the currency switched back: no edit to send, and the fulfil goes through'],
        ];
        foreach ($cases as $case) {
            $shop = self::shop('two.two', $case['status'], $case['stored'], $case['responses'], 7, refundStatus: 11, edited: $case['edited'], currency: $case['currency'] ?? '');
            $shop['event']->handle(42);
            $d = 'status event: ' . $case['description'];
            TinyAssert::same($case['calls'], self::calls($shop['transport']), $d . ': requests');
            if ($case['notes'] === 'fulfilled') {
                TinyAssert::true(count($shop['notes']->list) === 1 && str_contains($shop['notes']->list[0], 'fulfil') && !str_contains($shop['notes']->list[0], 'not fulfilled'), $d . ': only the fulfilment is noted');
            } else {
                TinyAssert::same($case['notes'], $shop['notes']->list, $d . ': history notes');
            }
        }
    }

    /** The handler's own note goes through addHistory, which fires the event again: it must not re-enter. */
    private static function testRecursionGuard(): void
    {
        $shop = self::shop('two.two', 7, 'CONFIRMED', [new HttpResponse(200, '{}'), new HttpResponse(200, '{"state":"CONFIRMED"}'), new HttpResponse(200, '{}'), new HttpResponse(200, '{"state":"CANCELLED"}')], 7, reenter: true);
        $shop['event']->handle(42);
        TinyAssert::same(2, count($shop['transport']->calls), 'recursion guard: the note\'s own event sends nothing more');
        TinyAssert::same(1, count($shop['notes']->list), 'recursion guard: one note, not a loop');
    }

    /** Anything thrown is logged and noted, and the guard is released for the next event. */
    private static function testThrowable(): void
    {
        $shop = self::shop('two.two', 3, 'CONFIRMED', [], 7, throwRows: true);
        $shop['event']->handle(42);
        TinyAssert::true(str_starts_with($shop['notes']->list[0] ?? '', '3|The fulfil at Two failed: RuntimeException at '), 'throwable: noted at the merchant\'s status (got ' . ($shop['notes']->list[0] ?? 'nothing') . ')');
        TinyAssert::same(1, count($shop['errors']->list), 'throwable: logged');
        TinyAssert::true(str_contains($shop['errors']->list[0] ?? '', 'rows unreadable'), 'throwable: the log carries the message');

        $next = self::shop('two.two', 7, 'CONFIRMED', [new HttpResponse(200, '{}'), new HttpResponse(200, '{"state":"CANCELLED"}')], 7);
        $next['event']->handle(42);
        TinyAssert::same(1, count($next['notes']->list), 'throwable: the guard is released, so the next event still acts');
    }

    /**
     * @param list<HttpResponse> $responses
     * @return array{event: OrderStatusEvent, transport: ScriptedTransport, notes: LineLog, errors: LineLog}
     */
    private static function shop(string $code, int $status, string $stored, array $responses, int $cancelStatus, bool $reenter = false, bool $throwRows = false, ?int $refundStatus = null, bool $edited = false, string $currency = ''): array
    {
        $placed = ['products' => [['product_id' => '7', 'quantity' => '2']], 'totals' => [], 'addresses' => []];
        // The order's currency now (placed in NOK); none given leaves it unjudged.
        $rows = ($edited ? ['products' => [['product_id' => '7', 'quantity' => '3']], 'totals' => [], 'addresses' => []] : $placed) + ($currency === '' ? [] : ['currency' => $currency]);
        $records = new MemoryRecords([42 => ['two_order_id' => self::ID, 'two_order_state' => $stored, 'payload_hash' => str_repeat('a', 64), 'rows_hash' => OrderLifecycle::rowsHash($placed), 'placed_fee' => FeeQuote::encodePlaced([], 11.5, 'NOK')]]);
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
        $postprocessing = new Postprocessing(new ScriptedSubscribers([]), $logger);
        $flow = new OrderFlow($client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $records, $logger);
        $lifecycle = new OrderLifecycle($flow, $client, $postprocessing, new ErrorMapper(), $records, $logger);
        $refunds = new OrderRefunds($client, $postprocessing, new ErrorMapper(), $records, new MemoryRefunds(), $logger);
        $edit = new OrderEdit($flow, $client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $records, $logger);

        $notes = new LineLog();
        $errors = new LineLog();
        $event = null;
        $event = new OrderStatusEvent(
            static fn (int $orderId): array => ['order_id' => $orderId, 'order_status_id' => $status, 'store_id' => 0, 'payment_method' => ['code' => $code]],
            static fn (array $order): array => ['lifecycle' => $lifecycle, 'refunds' => $refunds, 'edit' => $edit, 'rebuild' => static fn (array $record): ?array => null, 'fulfil' => [3], 'cancel' => $cancelStatus, 'refund' => $refundStatus],
            static function (int $orderId) use ($rows, $throwRows): array {
                if ($throwRows) {
                    throw new \RuntimeException('rows unreadable');
                }

                return $rows;
            },
            static function (int $orderId, int $statusId, Message $message) use ($notes, &$event, $reenter): void {
                $notes->list[] = $statusId . '|' . $message->english();
                if ($reenter && $event instanceof OrderStatusEvent) {
                    $event->handle($orderId);
                }
            },
            static function (string $line) use ($errors): void {
                $errors->list[] = $line;
            },
            self::VOID,
        );

        return ['event' => $event, 'transport' => $transport, 'notes' => $notes, 'errors' => $errors];
    }

    /** @return list<string> */
    private static function calls(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls);
    }
}

/** Lines a closure double wrote: history notes or log lines. */
final class LineLog
{
    /** @var list<string> */
    public array $list = [];
}
