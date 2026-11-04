<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The order postprocessing hook (Build Guide section 21): the one choke point
 * every outbound order request passes through just before it is sent,
 * whatever its type, so merchant code can change the payload. Body-less
 * requests (confirm, cancel) pass through with an empty payload.
 *
 * - The plugin never recomputes after the hook. A subscriber that edits a
 *   line calls recomputeTotals() itself (the opt-in totals helper, part of
 *   the stable contract).
 * - The default handler runs the shop-match checks only while no merchant
 *   handler is registered. With one, even one that changes nothing, they are
 *   delegated to it and one log line names it. The checks are worked out on
 *   the plugin's own payload before the hook (the caller passes the
 *   failures in), never on what a subscriber returns.
 * - A subscriber's code fault refuses the request: it threw, returned
 *   something other than an array, or returned something that cannot be
 *   JSON-encoded. The request is never sent with the pre-hook payload
 *   instead.
 */
final class Postprocessing
{
    public const CONTRACT_VERSION = 1;

    public const ORDER_INTENT = 'order_intent';
    public const ORDER_CREATE = 'order_create';
    public const ORDER_UPDATE = 'order_update';
    public const ORDER_CONFIRM = 'order_confirm';
    public const CAPTURE = 'capture';
    public const REFUND = 'refund';
    public const CANCEL = 'cancel';
    public const REQUEST_TYPES = [self::ORDER_INTENT, self::ORDER_CREATE, self::ORDER_UPDATE, self::ORDER_CONFIRM, self::CAPTURE, self::REFUND, self::CANCEL];

    public const HOOK_FAILED = 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED';
    public const SHOP_MATCH_FAILED = 'TWO_ORDER_SHOP_MATCH_FAILED';

    /** Changed fields logged per request, with debug logging on. */
    public const DIFF_LIMIT = 40;
    private const REDACTED = 'redacted';

    /**
     * @param bool $taxSubtotals the merchant's Validate tax subtotals switch (Order management): when off, the
     *                           plugin's own tax_subtotals are left out of every request before the subscribers
     *                           run, so a subscriber may still add its own
     */
    public function __construct(
        private readonly PostprocessingSubscribers $subscribers,
        private readonly Logger $logger,
        private readonly bool $taxSubtotals = true,
    ) {
    }

    /**
     * The payload to send, as the hook left it.
     *
     * @param array<string, mixed> $payload           the plugin's own payload
     * @param array<string, mixed> $context           platform objects and request facts; the v1 keys are added here
     * @param list<Message>|null   $shopMatchFailures what the default handler refuses, worked out on $payload; null for a request with no shop-match checks
     * @return array<string, mixed>
     *
     * @throws OrderRefused when a subscriber faults, or the default handler refuses
     */
    public function apply(string $requestType, string $trigger, string $endpoint, array $payload, array $context = [], ?array $shopMatchFailures = null): array
    {
        if (!in_array($requestType, self::REQUEST_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown order request type "' . $requestType . '".');
        }
        $context = [
            'request_type' => $requestType,
            'trigger' => $trigger,
            'endpoint' => $endpoint,
            'contract_version' => self::CONTRACT_VERSION,
        ] + $context + [
            'shipping_tax_rate' => null,
            'fallback_shipping_tax_rate' => null,
        ];
        $handlers = $this->subscribers->names();
        $facts = ['request_type' => $requestType, 'trigger' => $trigger];
        if (!$this->taxSubtotals) {
            // The shop-match checks were worked out on the full payload; Two is sent none of the split.
            unset($payload['tax_subtotals']);
        }

        try {
            $result = $this->subscribers->dispatch($payload, $context);
        } catch (\Throwable $e) {
            $this->logger->error(self::HOOK_FAILED, $facts + ['exception' => get_class($e), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            throw OrderRefused::because('postprocessing_hook_failed', Message::of('refusal_hook_threw', get_class($e)));
        }
        if (!is_array($result)) {
            $this->logger->error(self::HOOK_FAILED, $facts + ['returned' => get_debug_type($result)]);
            throw OrderRefused::because('postprocessing_hook_failed', Message::of('refusal_hook_returned', get_debug_type($result)));
        }
        try {
            json_encode($result, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error(self::HOOK_FAILED, $facts + ['returned' => 'unencodable array']);
            throw OrderRefused::because('postprocessing_hook_failed', Message::of('refusal_hook_unencodable'));
        }
        /** @var array<string, mixed> $result */

        if ($shopMatchFailures !== null) {
            if ($handlers === []) {
                if ($shopMatchFailures !== []) {
                    $this->logger->error(self::SHOP_MATCH_FAILED, $facts + ['failures' => array_map(static fn (Message $failure): string => $failure->english(), $shopMatchFailures)]);
                    // At most two checks: the gross, then the tax.
                    throw OrderRefused::because('shop_match_failed', count($shopMatchFailures) === 1 ? $shopMatchFailures[0] : Message::of('refusal_shop_match_both', ...$shopMatchFailures));
                }
            } else {
                $this->logger->info('Two order postprocessing: shop-match checks delegated to ' . implode(', ', $handlers), $facts);
            }
        }

        $changes = self::changes($payload, $result);
        if ($changes !== []) {
            $this->logger->debug('Two order postprocessing changed the payload', $facts + ['changes' => $changes]);
        }

        return $result;
    }

    /**
     * The opt-in totals helper (section 21.1): order net, tax and gross,
     * discount and tax subtotals rebuilt from the lines. Arithmetic, not a
     * tax judgement. The plugin's payloads carry nothing beyond their lines,
     * so the totals are the lines' sums. Deterministic.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function recomputeTotals(array $payload): array
    {
        $net = $tax = $gross = $discount = 0;
        $subtotals = [];
        $lines = is_array($payload['line_items'] ?? null) ? $payload['line_items'] : [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineNet = self::cents($line['net_amount'] ?? 0);
            $lineTax = self::cents($line['tax_amount'] ?? 0);
            $lineGross = self::cents($line['gross_amount'] ?? 0);
            $net += $lineNet;
            $tax += $lineTax;
            $gross += $lineGross;
            if ($lineGross < 0) {
                $discount -= $lineGross;
            }
            $rate = is_scalar($line['tax_rate'] ?? null) ? (string) $line['tax_rate'] : '0';
            $subtotals[$rate] ??= ['taxable' => 0, 'tax' => 0];
            $subtotals[$rate]['taxable'] += $lineNet;
            $subtotals[$rate]['tax'] += $lineTax;
        }
        uksort($subtotals, static fn (string $a, string $b): int => [(float) $a, $a] <=> [(float) $b, $b]);

        $payload['net_amount'] = self::money($net);
        $payload['tax_amount'] = self::money($tax);
        $payload['gross_amount'] = self::money($gross);
        $payload['discount_amount'] = self::money($discount);
        $payload['tax_subtotals'] = [];
        foreach ($subtotals as $rate => $sums) {
            $payload['tax_subtotals'][] = ['tax_rate' => (string) $rate, 'taxable_amount' => self::money($sums['taxable']), 'tax_amount' => self::money($sums['tax'])];
        }

        return $payload;
    }

    /** An amount as Two takes it: two decimals, a dot, never "-0.00". */
    public static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function cents(mixed $amount): int
    {
        return is_numeric($amount) ? (int) round((float) $amount * 100) : 0;
    }

    /**
     * The fields a subscriber changed, as {path, before, after} keyed by JSON
     * pointer, first DIFF_LIMIT only. Values outside the amounts and lines are
     * shown as "redacted", since they can carry buyer data.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<array{path: string, before: mixed, after: mixed}>
     */
    private static function changes(array $before, array $after): array
    {
        $old = self::flatten($before);
        $new = self::flatten($after);
        $changes = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $path) {
            $path = (string) $path;
            if (array_key_exists($path, $old) && array_key_exists($path, $new) && $old[$path] === $new[$path]) {
                continue;
            }
            $shown = preg_match('~^/(line_items|tax_subtotals)/|_amount$|^/tax_rate$~', $path) === 1;
            $changes[] = [
                'path' => $path,
                'before' => $shown ? ($old[$path] ?? null) : self::REDACTED,
                'after' => $shown ? ($new[$path] ?? null) : self::REDACTED,
            ];
            if (count($changes) === self::DIFF_LIMIT) {
                break;
            }
        }

        return $changes;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = $prefix . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            if (is_array($value) && $value !== []) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
