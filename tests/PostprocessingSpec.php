<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\OrderRefused;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;

/**
 * The section 21 choke point. Each case is the merchant handlers, the
 * request's shop-match failures (null for a request with none), and what
 * comes out: the payload sent or the refusal, plus the log lines.
 */
final class PostprocessingSpec
{
    public static function runAll(): void
    {
        $payload = ['gross_amount' => '125.00', 'buyer' => ['email' => 'a@example.com'], 'line_items' => [['net_amount' => '100.00', 'tax_amount' => '25.00', 'gross_amount' => '125.00', 'tax_rate' => '0.25']]];
        $identity = static fn (array $p): array => $p;
        $edited = $payload;
        $edited['line_items'][0]['net_amount'] = '90.00';
        $cases = [
            ['handlers' => [], 'failures' => [], 'sent' => $payload, 'refused' => null, 'info' => 0, 'errors' => [], 'description' => 'no handler, checks pass: the payload goes as built'],
            ['handlers' => [], 'failures' => [Message::of('refusal_shop_match_gross', '1.00', '2.00')], 'sent' => null, 'refused' => 'shop_match_failed', 'info' => 0, 'errors' => [Postprocessing::SHOP_MATCH_FAILED], 'description' => 'no handler, a check fails: the default handler refuses'],
            ['handlers' => ['merchant.fix' => $identity], 'failures' => [Message::of('refusal_shop_match_gross', '1.00', '2.00')], 'sent' => $payload, 'refused' => null, 'info' => 1, 'errors' => [], 'description' => 'a handler that changes nothing still takes over the checks'],
            ['handlers' => ['merchant.fix' => $identity], 'failures' => null, 'sent' => $payload, 'refused' => null, 'info' => 0, 'errors' => [], 'description' => 'a request with no checks logs no delegation'],
            ['handlers' => ['merchant.edit' => static function (array $p): array { $p['line_items'][0]['net_amount'] = '90.00'; return $p; }], 'failures' => [], 'sent' => $edited, 'refused' => null, 'info' => 1, 'errors' => [], 'description' => 'an edit goes out as the handler returns it, never recomputed'],
            ['handlers' => ['a' => static fn (array $p): array => ['step' => 'a'] + $p, 'b' => static fn (array $p): array => ['step' => $p['step'] . 'b'] + $p], 'failures' => [], 'sent' => ['step' => 'ab'] + $payload, 'refused' => null, 'info' => 1, 'errors' => [], 'description' => 'handlers chain in order'],
            ['handlers' => ['merchant.throws' => static function (array $p): array { throw new \LogicException('secret detail'); }], 'failures' => [], 'sent' => null, 'refused' => 'postprocessing_hook_failed', 'info' => 0, 'errors' => [Postprocessing::HOOK_FAILED], 'description' => 'a handler that throws refuses the request'],
            ['handlers' => ['merchant.string' => static fn (array $p): string => 'oops'], 'failures' => [], 'sent' => null, 'refused' => 'postprocessing_hook_failed', 'info' => 0, 'errors' => [Postprocessing::HOOK_FAILED], 'description' => 'a handler that returns a non-array refuses the request'],
            ['handlers' => ['merchant.nan' => static fn (array $p): array => ['gross_amount' => NAN] + $p], 'failures' => [], 'sent' => null, 'refused' => 'postprocessing_hook_failed', 'info' => 0, 'errors' => [Postprocessing::HOOK_FAILED], 'description' => 'a handler that returns an unencodable payload refuses the request'],
        ];
        foreach ($cases as $case) {
            $logger = new RecordingLogger();
            $hook = new Postprocessing(new ScriptedSubscribers($case['handlers']), $logger);
            $refused = null;
            $sent = null;
            try {
                $sent = $hook->apply(Postprocessing::ORDER_CREATE, 'checkout', '/v1/order', $payload, [], $case['failures']);
            } catch (OrderRefused $e) {
                $refused = $e->reason;
            }
            $d = $case['description'];
            TinyAssert::same($case['refused'], $refused, $d . ': refusal');
            TinyAssert::same($case['sent'], $sent, $d . ': payload sent');
            TinyAssert::same($case['info'], count($logger->at('info')), $d . ': delegation lines');
            TinyAssert::same($case['errors'], $logger->at('error'), $d . ': error lines');
            TinyAssert::true(!str_contains($logger->dump(), 'secret detail'), $d . ': a subscriber exception message is never logged');
        }

        self::testContext();
        self::testTaxSubtotalsSwitch();
        self::testDiffRedactsBuyerData();
        self::testUnknownRequestType();
        self::testRecomputeTotals();
    }

    private static function testContext(): void
    {
        $subscribers = new ScriptedSubscribers(['seen' => static fn (array $p): array => $p]);
        (new Postprocessing($subscribers, new RecordingLogger()))->apply(Postprocessing::CANCEL, 'buyer_cancel', '/v1/order/x/cancel', [], ['order_id' => 7]);
        $context = $subscribers->contexts[0];
        foreach (['request_type' => 'cancel', 'trigger' => 'buyer_cancel', 'endpoint' => '/v1/order/x/cancel', 'contract_version' => 1, 'order_id' => 7, 'shipping_tax_rate' => null, 'fallback_shipping_tax_rate' => null] as $key => $expected) {
            TinyAssert::same($expected, array_key_exists($key, $context) ? $context[$key] : 'missing', 'context carries ' . $key);
        }
    }

    /**
     * Validate tax subtotals: off leaves the plugin's split out of every
     * request type that carries one, before the subscribers see it; a
     * subscriber may still add its own. On sends it as built.
     */
    private static function testTaxSubtotalsSwitch(): void
    {
        $split = [['tax_rate' => '0.25', 'taxable_amount' => '100.00', 'tax_amount' => '25.00']];
        $payload = ['gross_amount' => '125.00', 'tax_subtotals' => $split];
        $own = [['tax_rate' => '0.25', 'taxable_amount' => '1.00', 'tax_amount' => '0.25']];
        $adds = static fn (array $p): array => $p + ['tax_subtotals' => $own];
        $cases = [];
        foreach ([Postprocessing::ORDER_INTENT, Postprocessing::ORDER_CREATE, Postprocessing::ORDER_UPDATE, Postprocessing::REFUND] as $type) {
            $cases[] = ['type' => $type, 'on' => true, 'handlers' => [], 'sent' => $payload, 'seen' => true, 'description' => $type . ': on sends the split'];
            $cases[] = ['type' => $type, 'on' => false, 'handlers' => [], 'sent' => ['gross_amount' => '125.00'], 'seen' => false, 'description' => $type . ': off leaves the split out'];
            $cases[] = ['type' => $type, 'on' => false, 'handlers' => ['merchant.adds' => $adds], 'sent' => ['gross_amount' => '125.00', 'tax_subtotals' => $own], 'seen' => false, 'description' => $type . ': off, a subscriber may add its own'];
        }
        foreach ($cases as $case) {
            $subscribers = new ScriptedSubscribers($case['handlers']);
            $sent = (new Postprocessing($subscribers, new RecordingLogger(), $case['on']))->apply($case['type'], 't', '/v1/x', $payload);
            TinyAssert::same($case['sent'], $sent, 'tax subtotals: ' . $case['description']);
            TinyAssert::same($case['seen'], array_key_exists('tax_subtotals', $subscribers->payloads[0] ?? []), 'tax subtotals: ' . $case['description'] . ' (what the subscribers are handed)');
        }
    }

    private static function testDiffRedactsBuyerData(): void
    {
        $logger = new RecordingLogger();
        $edit = static function (array $p): array { $p['buyer']['email'] = 'b@example.com'; $p['gross_amount'] = '1.00'; return $p; };
        (new Postprocessing(new ScriptedSubscribers(['e' => $edit]), $logger))->apply(Postprocessing::ORDER_CREATE, 'checkout', '/v1/order', ['gross_amount' => '2.00', 'buyer' => ['email' => 'a@example.com']], [], []);
        $changes = [];
        foreach ($logger->lines as $line) {
            if ($line['level'] === 'debug') {
                $changes = $line['context']['changes'];
            }
        }
        TinyAssert::same([['path' => '/gross_amount', 'before' => '2.00', 'after' => '1.00'], ['path' => '/buyer/email', 'before' => 'redacted', 'after' => 'redacted']], $changes, 'the debug diff shows amounts and redacts buyer data');
    }

    private static function testUnknownRequestType(): void
    {
        TinyAssert::throws(static fn () => (new Postprocessing(new ScriptedSubscribers(), new RecordingLogger()))->apply('order_whatever', 't', '/v1/x', []), \InvalidArgumentException::class, 'an unknown request type is a bug');
    }

    private static function testRecomputeTotals(): void
    {
        $line = static fn (string $net, string $tax, string $rate): array => ['net_amount' => $net, 'tax_amount' => $tax, 'gross_amount' => number_format((float) $net + (float) $tax, 2, '.', ''), 'tax_rate' => $rate];
        $cases = [
            ['lines' => [], 'sums' => '0.00|0.00|0.00|0.00', 'subtotals' => '', 'description' => 'no lines, zero totals'],
            ['lines' => [$line('100.00', '25.00', '0.25')], 'sums' => '100.00|25.00|125.00|0.00', 'subtotals' => '0.25:100.00:25.00', 'description' => 'one line'],
            ['lines' => [$line('100.00', '25.00', '0.25'), $line('100.00', '15.00', '0.15'), $line('-20.00', '-5.00', '0.25')], 'sums' => '180.00|35.00|215.00|25.00', 'subtotals' => '0.15:100.00:15.00,0.25:80.00:20.00', 'description' => 'mixed rates and a discount line'],
            ['lines' => [$line('0.10', '0.02', '0.2'), $line('0.20', '0.04', '0.2')], 'sums' => '0.30|0.06|0.36|0.00', 'subtotals' => '0.2:0.30:0.06', 'description' => 'sums in cents, no float drift'],
        ];
        foreach ($cases as $case) {
            $out = Postprocessing::recomputeTotals(['line_items' => $case['lines'], 'gross_amount' => '999.00']);
            TinyAssert::same($case['sums'], implode('|', [$out['net_amount'], $out['tax_amount'], $out['gross_amount'], $out['discount_amount']]), 'totals helper: ' . $case['description']);
            TinyAssert::same($case['subtotals'], implode(',', array_map(static fn (array $s): string => $s['tax_rate'] . ':' . $s['taxable_amount'] . ':' . $s['tax_amount'], $out['tax_subtotals'])), 'totals helper subtotals: ' . $case['description']);
        }
    }
}
