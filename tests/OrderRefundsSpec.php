<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\OrderRefunds;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\RefundRecords;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * Refunds against a scripted Two: the rest of the order off the status map,
 * the panel's partial refund, the retry of a refund whose outcome is
 * unknown, and the credit note. The Two order is 2 Widgets at 100.00 net and
 * 25.00 tax each (250.00 gross, 25%). Each case gives Two's answers, then the
 * outcome, the requests that reached Two and the refund row's state.
 */
final class OrderRefundsSpec
{
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';
    private const LINE = '9a0b1c2d-0000-4000-8000-0000000000aa';

    public static function runAll(): void
    {
        self::testUntaxed();
        self::testRemainder();
        self::testPartial();
        self::testRetry();
        self::testCreditNote();
        self::testPanel();
        self::testZeroRateCodes();
    }

    /**
     * A refund line that references no order line (the typed amount, the
     * rest after a partial refund) at 0% takes the code Two holds on the
     * order's 0% lines when they share one (TWO-27058). The order here adds
     * an export line at 0% (100.00 net) to the Widgets.
     */
    private static function testZeroRateCodes(): void
    {
        $order = static fn (array $codes, array $refunds = []): HttpResponse => self::json(200, [
            'id' => self::ID, 'state' => $refunds === [] ? 'FULFILLED' : 'REFUNDED', 'status' => 'APPROVED', 'currency' => 'NOK', 'gross_amount' => '350.00',
            'line_items' => [
                ['id' => self::LINE, 'name' => 'Widget', 'quantity' => 2, 'unit_price' => '100.00', 'net_amount' => '200.00', 'tax_amount' => '50.00', 'tax_rate' => '0.25'],
                ...array_map(static fn (?string $code, int $i): array => ['id' => 'x' . $i, 'name' => 'Export', 'quantity' => 1, 'unit_price' => '50.00', 'net_amount' => '50.00', 'tax_amount' => '0.00', 'tax_rate' => '0'] + ($code === null ? [] : ['tax_code' => $code]), $codes, array_keys($codes)),
            ],
            'refunds' => $refunds,
            'tax_subtotals' => [['tax_rate' => '0.25', 'taxable_amount' => '200.00', 'tax_amount' => '50.00'], ['tax_rate' => '0', 'taxable_amount' => '100.00', 'tax_amount' => '0.00']],
        ]);
        $partly = [self::refund('r1', '100.00', '80.00', '20.00')];
        $done = static fn (string $gross): array => [['id' => 'r9', 'total_amount' => '-' . $gross]];
        $cases = [
            ['amount', ['net' => '10.00', 'tax' => '0', 'rate' => '0'], ['E', 'E'], ['E'], 'an amount at 0%: the code the order\'s 0% lines share'],
            ['amount', ['net' => '10.00', 'tax' => '0', 'rate' => '0'], ['E', null], ['E'], 'an amount at 0%: an uncoded 0% line does not break the share'],
            ['amount', ['net' => '10.00', 'tax' => '0', 'rate' => '0'], ['E', 'Z'], [], 'an amount at 0%: no code when the order\'s 0% lines disagree'],
            ['amount', ['net' => '10.00', 'tax' => '0', 'rate' => '0'], [null], [], 'an amount at 0%: no code when the order\'s 0% lines carry none'],
            ['amount', ['net' => '10.00', 'tax' => '2.50', 'rate' => '0.25'], ['E'], [], 'an amount at 25%: never coded'],
            ['rest', [], ['E', 'E'], ['E'], 'the rest after a partial refund: its 0% line takes the shared code'],
            ['rest', [], ['E', 'Z'], [], 'the rest after a partial refund: no code when the 0% lines disagree'],
        ];
        foreach ($cases as [$kind, $request, $codes, $expected, $description]) {
            $d = 'refund codes: ' . $description;
            if ($kind === 'amount') {
                $shop = self::shop([$order($codes), self::json(201, ['id' => 'r9']), $order($codes, $done('100.00'))], 'FULFILLED');
                $shop['refunds']->partial(42, $request);
            } else {
                $shop = self::shop([$order($codes, $partly), self::json(201, ['id' => 'r9']), $order($codes, [...$partly, ...$done('250.00')])], 'REFUNDED');
                $shop['refunds']->remainder(42);
            }
            $lines = json_decode((string) ($shop['transport']->calls[1]['body'] ?? '{}'), true)['line_items'] ?? [];
            TinyAssert::true($lines !== [], $d . ': lines sent');
            TinyAssert::same($expected, array_column($lines, 'tax_code'), $d . ': the codes sent, only ever on the 0% line');
        }
    }

    private static function testUntaxed(): void
    {
        $cases = [
            [1000, 0, '0.25', true, 'a net amount at 25% with no tax'],
            [8, 0, '0.25', false, 'tax under 2 cents may round to nothing'],
            [1000, 0, '0', false, 'a zero-rated line carries no tax'],
            [1000, 1, '0.25', false, 'a line carrying tax is the shop\'s to state'],
        ];
        foreach ($cases as [$net, $tax, $rate, $expected, $description]) {
            TinyAssert::same($expected, OrderRefunds::untaxed($net, $tax, $rate), 'untaxed: ' . $description);
        }
    }

    private static function testRemainder(): void
    {
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/refund';
        $partly = [self::refund('r1', '100.00', '80.00', '20.00')];
        $cases = [
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'body' => '{}', 'key' => IdempotencyKey::refundRemainder(self::ID, [], '250.00'), 'description' => 'nothing refunded yet: an empty body refunds in full'],
            ['stored' => 'REFUNDED', 'responses' => [self::two('REFUNDED', $partly), self::json(201, ['id' => 'r2']), self::two('REFUNDED', [...$partly, self::refund('r2', '150.00', '120.00', '30.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'amount' => '150.00', 'key' => IdempotencyKey::refundRemainder(self::ID, ['r1'], '150.00'), 'description' => 'after a partial refund: only the rest, one line a rate'],
            ['stored' => 'REFUNDED', 'responses' => [self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [$read], 'row' => null, 'description' => 'already refunded in full and stored so: a repeat event sends nothing, no note'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::ALREADY, 'calls' => [$read], 'row' => null, 'description' => 'refunded in full at Two meanwhile: nothing sent, noted'],
            ['stored' => 'CONFIRMED', 'responses' => [self::two('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'not fulfilled at Two: refused, nothing sent'],
            ['stored' => 'FULFILLED', 'responses' => [self::json(500, [])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'Two cannot be read: refused'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('REFUNDED', [self::refund('r1', '100.00', '0.00', '0.00')])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'Two\'s subtotals do not split the rest: refused, nothing guessed'],
            ['stored' => 'REFUNDED', 'responses' => [self::two('REFUNDED', [self::lineRefund('r1', '80.00', '20.00')], false), self::json(201, ['id' => 'r2']), self::two('REFUNDED', [self::lineRefund('r1', '80.00', '20.00'), self::lineRefund('r2', '120.00', '30.00')], false)], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'amount' => '150.00', 'description' => 'Two holds no subtotals (tax subtotals off): the rest is split from the order\'s and the refunds\' own lines'],
            ['stored' => 'REFUNDED', 'responses' => [self::two('REFUNDED', [['id' => 'r1', 'total_amount' => '-100.00']], false)], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'a refund with neither subtotals nor lines: refused, nothing guessed'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), self::json(400, ['error_code' => 'X'])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $post], 'row' => RefundRecords::REFUSED, 'description' => 'Two refuses: refused, the row says so'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), HttpResponse::unreachable('timeout'), self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'description' => 'a timeout, but the refunded total shows it: done'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), HttpResponse::unreachable('timeout'), self::two('FULFILLED')], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'row' => RefundRecords::UNKNOWN, 'description' => 'a timeout and no rise: unknown, awaiting a retry'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), self::json(502, []), self::two('FULFILLED')], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'row' => RefundRecords::UNKNOWN, 'description' => 'a 5xx and no rise: unknown'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r0']), self::two('FULFILLED')], 'outcome' => OrderRefunds::NOT_APPLIED, 'calls' => [$read, $post, $read], 'row' => RefundRecords::NOT_APPLIED, 'description' => 'Two answers 201 but the total did not rise: an earlier refund under this key, not applied'],
            ['stored' => 'FULFILLED', 'blocked' => true, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'row' => RefundRecords::UNKNOWN, 'description' => 'a refund awaits a retry: refused before asking Two'],
            ['stored' => 'FULFILLED', 'handlers' => ['ref' => static fn (array $p, array $c): array => $c['request_type'] === Postprocessing::REFUND ? $p + ['reason' => 'Returned'] : $p], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'body' => '{"reason":"Returned"}', 'description' => 'a hook adds a field to the full refund\'s empty body: still the whole rest, done'],
            ['stored' => 'FULFILLED', 'held' => true, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'row' => null, 'description' => 'a form refund holds the order\'s lock: the status-map refund is refused, never sent alongside'],
        ];
        foreach ($cases as $case) {
            $shop = self::shop($case['responses'], $case['stored'], blocked: !empty($case['blocked']), held: !empty($case['held']), handlers: $case['handlers'] ?? []);
            $result = $shop['refunds']->remainder(42);
            self::check('remainder: ' . $case['description'], $case, $result, $shop);
        }
    }

    private static function testPartial(): void
    {
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/refund';
        $after = static fn (string $gross, string $net, string $tax): HttpResponse => self::two('REFUNDED', [self::refund('r1', $gross, $net, $tax)]);
        $cases = [
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), $after('125.00', '100.00', '25.00')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'amount' => '125.00', 'line' => ['id' => self::LINE, 'quantity' => 1, 'net_amount' => '100.00', 'tax_amount' => '25.00', 'tax_rate' => '0.25'], 'description' => 'one of two Widgets: the line\'s own net and tax, referenced by id'],
            ['request' => ['net' => '10.00', 'tax' => '2.50', 'rate' => '0.25', 'reason' => 'Damaged box'], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), $after('12.50', '10.00', '2.50')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'amount' => '12.50', 'line' => ['net_amount' => '10.00', 'tax_amount' => '2.50', 'tax_rate' => '0.25'], 'description' => 'an amount line at an order rate, with a reason'],
            ['request' => ['net' => '10.00', 'tax' => '0', 'rate' => '0.25'], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'net but no tax at 25% (12.2.1): refused, nothing sent'],
            ['request' => ['net' => '10.00', 'tax' => '1.50', 'rate' => '0.15'], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'a rate no order line carries: refused'],
            ['request' => ['net' => '1,50', 'tax' => '0.30', 'rate' => '0.25'], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'an amount that is not one: refused'],
            ['request' => ['lines' => [self::LINE => 3]], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'more of a line than the order holds: refused'],
            ['request' => ['lines' => ['00000000-0000-4000-8000-000000000000' => 1]], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'a line not on the Two order: refused'],
            ['request' => ['lines' => [self::LINE => 0]], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'nothing picked: refused'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('REFUNDED', [self::refund('r0', '200.00', '160.00', '40.00')])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'more than remains (125.00 of 50.00): refused'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'not fulfilled at Two: refused'],
            ['request' => ['lines' => [self::LINE => 1]], 'store_fails' => true, 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'the row cannot be stored: no key, so nothing sent'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('FULFILLED'), HttpResponse::unreachable('timeout'), self::two('FULFILLED')], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'row' => RefundRecords::UNKNOWN, 'description' => 'a timeout and no rise: the row is unknown'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r0']), self::two('FULFILLED')], 'outcome' => OrderRefunds::NOT_APPLIED, 'calls' => [$read, $post, $read], 'row' => RefundRecords::NOT_APPLIED, 'description' => 'Two answers with an earlier refund under this key: not applied'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), $after('200.00', '160.00', '40.00')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'description' => 'a larger rise (a refund made meanwhile elsewhere) is fine'],
            ['request' => ['lines' => [self::LINE => 1]], 'blocked' => true, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'row' => RefundRecords::UNKNOWN, 'reason' => 'Retry it on the Two tab', 'description' => 'a refund awaits a retry: refused with a pointer to it'],
            ['request' => ['lines' => [self::LINE => 1]], 'earlier' => [RefundRecords::DONE => 2], 'responses' => [self::two('REFUNDED', [self::refund('r1', '50.00', '40.00', '10.00')])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => RefundRecords::DONE, 'reason' => 'left to refund (0 of 2)', 'description' => 'a second click on a line already refunded in full: refused, never refunded twice'],
            ['request' => ['lines' => [self::LINE => 2]], 'earlier' => [RefundRecords::DONE => 1], 'responses' => [self::two('REFUNDED', [self::refund('r1', '125.00', '100.00', '25.00')])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => RefundRecords::DONE, 'reason' => 'left to refund (1 of 2)', 'description' => 'more than the line has left after an earlier refund: refused'],
            ['request' => ['lines' => [self::LINE => 1]], 'earlier' => [RefundRecords::DONE => 1], 'responses' => [self::two('REFUNDED', [self::refund('r1', '125.00', '100.00', '25.00')]), self::json(201, ['id' => 'r2']), self::two('REFUNDED', [self::refund('r1', '125.00', '100.00', '25.00'), self::refund('r2', '125.00', '100.00', '25.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'line' => ['id' => self::LINE, 'quantity' => 1, 'net_amount' => '100.00', 'tax_amount' => '25.00'], 'description' => 'the last of a line takes what the earlier refund left'],
            ['request' => ['lines' => [self::LINE => 2]], 'earlier' => [RefundRecords::REFUSED => 2], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), $after('250.00', '200.00', '50.00')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'description' => 'a refused earlier refund took nothing from the line'],
            ['request' => ['lines' => [self::LINE => 1]], 'held' => true, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'row' => null, 'reason' => 'another refund on this order is being sent', 'description' => 'another refund holds the order\'s lock: refused before asking Two'],
        ];
        foreach ($cases as $case) {
            $shop = self::shop($case['responses'], 'FULFILLED', !empty($case['store_fails']), !empty($case['blocked']), $case['earlier'] ?? [], !empty($case['held']));
            $result = $shop['refunds']->partial(42, $case['request']);
            $d = 'partial: ' . $case['description'];
            self::check($d, $case, $result, $shop);
            TinyAssert::same(empty($case['held']) ? ['lock 42', 'unlock 42'] : ['lock 42'], $shop['rows']->locks, $d . ': the order\'s refund lock is taken and released');
            if (in_array($post, self::calls($shop['transport']), true)) {
                $rowId = count($shop['rows']->rows);
                TinyAssert::same(Postprocessing::money(OrderRefunds::refundedCents((array) json_decode($case['responses'][0]->body, true))), $shop['rows']->rows[$rowId]['refunded_before'] ?? null, $d . ': the refunded total before the first attempt is kept for a retry');
                TinyAssert::same('refund_' . self::ID . '_' . $rowId, $shop['transport']->calls[1]['headers']['Idempotency-Key'] ?? null, $d . ': keyed on the row created before the call');
            }
            if (isset($case['line'])) {
                $line = json_decode((string) $shop['transport']->calls[1]['body'], true)['line_items'][0] ?? [];
                TinyAssert::same($case['line'], array_intersect_key($line, $case['line']), $d . ': the line sent');
            }
        }
    }

    private static function testRetry(): void
    {
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/refund';
        $applied = self::two('REFUNDED', [self::refund('r9', '125.00', '100.00', '25.00')]);
        $cases = [
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r9']), $applied], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'description' => 'unknown, not applied before: sent again and applied'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [$applied, self::json(201, ['id' => 'r9']), $applied], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'description' => 'the earlier attempt was applied after all: Two answers with it, done, never refunded twice'],
            ['row' => RefundRecords::PENDING, 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r9']), $applied], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'description' => 'a row that never settled is retried the same way'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r5']), self::two('FULFILLED')], 'outcome' => OrderRefunds::NOT_APPLIED, 'calls' => [$read, $post, $read], 'state' => RefundRecords::NOT_APPLIED, 'description' => 'Two answers with a refund it did not hold before, and no rise: not applied'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::two('FULFILLED'), HttpResponse::unreachable('timeout'), self::two('FULFILLED')], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'state' => RefundRecords::UNKNOWN, 'description' => 'no answer again: still unknown'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::json(500, [])], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read], 'state' => RefundRecords::UNKNOWN, 'description' => 'Two cannot be read: nothing sent, still unknown'],
            ['row' => RefundRecords::DONE, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => RefundRecords::DONE, 'description' => 'a settled refund is never retried'],
            ['row' => RefundRecords::UNKNOWN, 'first' => '0.00', 'responses' => [$applied, self::json(201, []), $applied], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'reason' => 'risen by the amount since the first attempt', 'description' => 'Two\'s answer has no id, but the total rose by the amount since the first attempt: done, not "not applied"'],
            ['row' => RefundRecords::UNKNOWN, 'first' => '0.00', 'responses' => [$applied, HttpResponse::unreachable('timeout'), $applied], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'state' => RefundRecords::UNKNOWN, 'description' => 'the retry gets no answer though the total has risen since the first attempt (maybe a portal refund): still unknown'],
            ['row' => RefundRecords::UNKNOWN, 'first' => '125.00', 'responses' => [$applied, self::json(201, []), $applied], 'outcome' => OrderRefunds::NOT_APPLIED, 'calls' => [$read, $post, $read], 'state' => RefundRecords::NOT_APPLIED, 'description' => 'no id and no rise since the first attempt: not applied'],
            ['row' => RefundRecords::UNKNOWN, 'stored_row' => ['kind' => RefundRecords::KIND_REMAINDER, 'amount' => '250.00', 'payload' => '[]', 'idempotency_key' => 'refund_remainder_x'], 'key' => 'refund_remainder_x', 'body' => '{}',
                'responses' => [self::two('REFUNDED', [self::refund('p1', '100.00', '80.00', '20.00')]), self::json(201, ['id' => 'r2']), self::two('REFUNDED', [self::refund('p1', '100.00', '80.00', '20.00'), self::refund('r2', '150.00', '120.00', '30.00')])],
                'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'description' => 'a full refund retried after a portal refund: Two refunds what remains (150.00), judged against that, done'],
            ['row' => RefundRecords::UNKNOWN, 'stored_row' => ['kind' => RefundRecords::KIND_REMAINDER, 'amount' => '250.00', 'payload' => '{"reason":"Returned"}', 'idempotency_key' => 'refund_remainder_x'], 'key' => 'refund_remainder_x', 'body' => '{"reason":"Returned"}',
                'responses' => [self::two('REFUNDED', [self::refund('p1', '100.00', '80.00', '20.00')]), self::json(201, ['id' => 'r2']), self::two('REFUNDED', [self::refund('p1', '100.00', '80.00', '20.00'), self::refund('r2', '150.00', '120.00', '30.00')])],
                'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'description' => 'a hooked full refund (a body with no amount) retried after a portal refund: judged against what remains, done'],
            ['row' => RefundRecords::UNKNOWN, 'stored_row' => ['kind' => RefundRecords::KIND_REMAINDER, 'amount' => '250.00', 'payload' => '[]', 'idempotency_key' => 'refund_remainder_x'], 'key' => 'refund_remainder_x',
                'responses' => [self::two('REFUNDED', [self::refund('p1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read], 'state' => RefundRecords::DONE, 'reason' => 'Nothing was left to refund',
                'description' => 'a full refund retried with nothing left at Two: settled without a send, the note says nothing was left'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::two('REFUNDED', [self::refund('p1', '200.00', '160.00', '40.00')]), self::json(400, ['error_code' => 'REFUND_AMOUNT_TOO_HIGH'])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $post], 'state' => RefundRecords::REFUSED,
                'description' => 'more than now remains: still sent under its key, Two refuses it, the row settles refused'],
            ['row' => RefundRecords::PENDING, 'stored_row' => ['idempotency_key' => ''], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r9']), $applied], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE,
                'description' => 'a row left without its key (the request stopped after the insert): the key is rebuilt from the row id'],
        ];
        foreach ($cases as $case) {
            $key = $case['key'] ?? 'refund_' . self::ID . '_1';
            $body = $case['body'] ?? '{"amount":"125.00","currency":"NOK"}';
            $shop = self::shop($case['responses'], 'FULFILLED');
            $shop['rows']->create(42, ($case['stored_row'] ?? []) + ['two_order_id' => self::ID, 'kind' => RefundRecords::KIND_PARTIAL, 'amount' => '125.00', 'currency' => 'NOK', 'payload' => '{"amount":"125.00","currency":"NOK"}', 'idempotency_key' => $key, 'state' => $case['row'], 'refunded_before' => $case['first'] ?? '']);
            $result = $shop['refunds']->retry(42, 1);
            $d = 'retry: ' . $case['description'];
            TinyAssert::same($case['outcome'], $result['outcome'], $d . ': outcome (' . $result['reason'] . ')');
            if (isset($case['reason'])) {
                TinyAssert::true(str_contains($result['reason'], $case['reason']), $d . ': the note says how it was judged');
            }
            if ($result['outcome'] === OrderLifecycle::DONE) {
                TinyAssert::true(str_contains(implode("\n", $shop['logger']->at('info')), $key), $d . ': the key is logged at info');
            }
            TinyAssert::same($case['calls'], self::calls($shop['transport']), $d . ': requests');
            TinyAssert::same($case['state'], $shop['rows']->rows[1]['state'], $d . ': row state');
            if (in_array($post, $case['calls'], true)) {
                TinyAssert::same($key, $shop['transport']->calls[1]['headers']['Idempotency-Key'] ?? null, $d . ': the same key');
                TinyAssert::same($body, $shop['transport']->calls[1]['body'], $d . ': the same payload');
            }
            TinyAssert::same(1, count($shop['rows']->rows), $d . ': no new row');
            TinyAssert::same($key, $shop['rows']->rows[1]['idempotency_key'], $d . ': the row holds its key');
        }
    }

    /** The Two tab reads Two whatever state was stored, so a stale one never hides the refund form. */
    private static function testPanel(): void
    {
        $read = 'GET /v1/order/' . self::ID;
        $cases = [
            ['FULFILLING', [self::two('FULFILLED')], true, 'FULFILLED', [$read], 'stored FULFILLING (a fulfil read back too early), FULFILLED at Two: the form opens'],
            ['CONFIRMED', [self::two('FULFILLED')], true, 'FULFILLED', [$read], 'stored CONFIRMED, fulfilled in the Two merchant portal: the form opens'],
            ['REFUNDED', [self::two('REFUNDED', [self::refund('r1', '100.00', '80.00', '20.00')])], true, 'REFUNDED', [$read], 'partly refunded: the form stays open'],
            ['CONFIRMED', [self::two('CONFIRMED')], false, 'CONFIRMED', [$read], 'not fulfilled at Two: no form'],
            ['FULFILLED', [self::json(500, [])], false, 'FULFILLED', [$read], 'Two cannot be read: no form, the stored state stays'],
            ['CANCELLED', [], false, 'CANCELLED', [], 'cancelled: Two is not asked'],
            [null, [], false, '', [], 'no Two order linked: Two is not asked'],
        ];
        foreach ($cases as [$stored, $responses, $refundable, $state, $calls, $description]) {
            $kept = $state === '' ? null : $state;
            $shop = self::shop($responses, $stored);
            $panel = $shop['refunds']->panel(42);
            $d = 'panel: ' . $description;
            TinyAssert::same([$refundable, $state], [$panel['refundable'], $panel['state']], $d . ': refundable and state');
            TinyAssert::same($calls, self::calls($shop['transport']), $d . ': requests');
            TinyAssert::same($kept, $shop['records']->rows[42]['two_order_state'] ?? null, $d . ': the state read is stored');
        }
    }

    private static function testCreditNote(): void
    {
        $cases = [
            ['stored' => 'REFUNDED', 'responses' => [new HttpResponse(200, "%PDF-1.7\n...")], 'expected' => 'pdf', 'description' => 'the PDF, streamed by Two'],
            ['stored' => 'REFUNDED', 'responses' => [self::json(409, ['error_code' => 'REFUND_STILL_PROCESSING'])], 'expected' => OrderRefunds::CREDIT_NOTE_ERROR, 'description' => 'still processing: an error notice'],
            ['stored' => 'REFUNDED', 'responses' => [new HttpResponse(200, '<html></html>')], 'expected' => OrderRefunds::CREDIT_NOTE_ERROR, 'description' => 'a 200 that is not a PDF is never served'],
            ['stored' => null, 'responses' => [], 'expected' => OrderRefunds::CREDIT_NOTE_NO_REFERENCE, 'description' => 'no Two order linked: nothing asked'],
        ];
        foreach ($cases as $case) {
            $shop = self::shop($case['responses'], $case['stored']);
            $result = $shop['refunds']->creditNote(42);
            $d = 'credit note: ' . $case['description'];
            TinyAssert::same($case['expected'], isset($result['pdf']) ? 'pdf' : $result['notice'], $d);
            foreach ($shop['transport']->calls as $call) {
                TinyAssert::true(str_contains($call['url'], '/v1/credit_note/' . self::ID . '/pdf?proxy=true'), $d . ': asks Two to stream it (no redirect)');
            }
        }
    }

    /**
     * @param array<string, mixed>                          $case
     * @param array{outcome: string, reason: string}        $result
     * @param array{transport: ScriptedTransport, rows: MemoryRefunds, logger: RecordingLogger} $shop
     */
    private static function check(string $d, array $case, array $result, array $shop): void
    {
        TinyAssert::same($case['outcome'], $result['outcome'], $d . ': outcome (' . $result['reason'] . ')');
        TinyAssert::same($case['calls'], self::calls($shop['transport']), $d . ': requests');
        $last = $shop['rows']->rows === [] ? null : end($shop['rows']->rows)['state'];
        TinyAssert::same($case['row'], $last, $d . ': refund row state');
        TinyAssert::true($result['outcome'] === OrderLifecycle::SKIPPED || $result['reason'] !== '', $d . ': a reason for the order history');
        if ($result['outcome'] === OrderLifecycle::DONE) {
            $sent = array_values(array_filter($shop['transport']->calls, static fn (array $call): bool => $call['method'] === 'POST'));
            TinyAssert::true(str_contains(implode("\n", $shop['logger']->at('info')), (string) ($sent[0]['headers']['Idempotency-Key'] ?? 'none')), $d . ': the key is logged at info whatever the debug setting');
        }
        if (isset($case['reason'])) {
            TinyAssert::true(str_contains($result['reason'], $case['reason']), $d . ': the message says what to do');
        }
        foreach ($shop['transport']->calls as $call) {
            if ($call['method'] === 'POST') {
                $body = json_decode((string) $call['body'], true);
                if (isset($case['body'])) {
                    TinyAssert::same($case['body'], $call['body'], $d . ': body');
                }
                if (isset($case['amount'])) {
                    TinyAssert::same($case['amount'], $body['amount'] ?? null, $d . ': amount sent');
                }
                if (isset($case['key'])) {
                    TinyAssert::same($case['key'], $call['headers']['Idempotency-Key'] ?? null, $d . ': key');
                }
                TinyAssert::true(($call['headers']['Idempotency-Key'] ?? '') !== '', $d . ': every refund carries a key');
            }
        }
    }

    /**
     * @param list<HttpResponse> $responses
     * @param array<string, int>  $earlier   earlier refund rows of the Widget line: state => quantity
     * @param array<string, \Closure(array<string, mixed>, array<string, mixed>): mixed> $handlers postprocessing subscribers
     * @return array{refunds: OrderRefunds, transport: ScriptedTransport, rows: MemoryRefunds, logger: RecordingLogger, records: MemoryRecords}
     */
    private static function shop(array $responses, ?string $stored, bool $storeFails = false, bool $blocked = false, array $earlier = [], bool $held = false, array $handlers = []): array
    {
        $records = new MemoryRecords($stored === null ? [] : [42 => ['two_order_id' => self::ID, 'two_order_state' => $stored]]);
        $rows = new MemoryRefunds($storeFails, $held);
        foreach ($earlier as $state => $quantity) {
            $rows->create(42, ['two_order_id' => self::ID, 'kind' => RefundRecords::KIND_PARTIAL, 'amount' => '0.00', 'currency' => 'NOK', 'state' => (string) $state,
                'payload' => (string) json_encode(['line_items' => [['id' => self::LINE, 'quantity' => $quantity, 'net_amount' => Postprocessing::money(10000 * $quantity), 'tax_amount' => Postprocessing::money(2500 * $quantity)]]])]);
        }
        if ($blocked) {
            $rows->rows[1] = ['refund_id' => '1', 'order_id' => '42', 'date_added' => '2026-10-25 12:00:00', 'two_order_id' => self::ID, 'amount' => '10.00', 'currency' => 'NOK', 'state' => RefundRecords::UNKNOWN, 'payload' => '{}', 'idempotency_key' => 'refund_' . self::ID . '_1'];
        }
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
        $refunds = new OrderRefunds($client, new Postprocessing(new ScriptedSubscribers($handlers), $logger), new ErrorMapper(), $records, $rows, $logger);

        return ['refunds' => $refunds, 'transport' => $transport, 'rows' => $rows, 'logger' => $logger, 'records' => $records];
    }

    /** @return list<string> */
    private static function calls(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls);
    }

    /** @param list<array<string, mixed>> $refunds */
    private static function two(string $state, array $refunds = [], bool $split = true): HttpResponse
    {
        return self::json(200, [
            'id' => self::ID, 'state' => $state, 'status' => 'APPROVED', 'currency' => 'NOK', 'gross_amount' => '250.00',
            'line_items' => [['id' => self::LINE, 'name' => 'Widget', 'quantity' => 2, 'unit_price' => '100.00', 'net_amount' => '200.00', 'tax_amount' => '50.00', 'tax_rate' => '0.25']],
            'refunds' => $refunds,
        ] + ($split ? ['tax_subtotals' => [['tax_rate' => '0.25', 'taxable_amount' => '200.00', 'tax_amount' => '50.00']]] : []));
    }

    /** @return array<string, mixed> a refund as Two lists one sent without tax subtotals: its lines only */
    private static function lineRefund(string $id, string $net, string $tax): array
    {
        $gross = Postprocessing::money(Postprocessing::cents($net) + Postprocessing::cents($tax));

        return ['id' => $id, 'total_amount' => '-' . $gross, 'line_items' => [['net_amount' => $net, 'tax_amount' => $tax, 'gross_amount' => $gross, 'tax_rate' => '0.25']]];
    }

    /** @return array<string, mixed> a refund as Two lists it, its total negative */
    private static function refund(string $id, string $gross, string $net, string $tax): array
    {
        return ['id' => $id, 'total_amount' => '-' . $gross, 'tax_subtotals' => [['tax_rate' => '0.25', 'taxable_amount' => $net, 'tax_amount' => $tax]]];
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }
}
