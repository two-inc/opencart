<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Builds the Two order payload from the shop's stored order (Build Guide
 * sections 5.4, 6.3 and 21.4). The plugin makes no determination about how
 * tax is levied: it reports the nets, taxes and rates the shop recorded and
 * buys the receivable the merchant defined. It never computes or adjusts a
 * tax.
 *
 * Inputs are neutral shapes the adapter maps from the shop's rows, with
 * every amount as the shop stored it (in the shop's base currency) and
 * converted here at the order's own stored currency value:
 *
 * - product lines carry the stored line net, the stored unit tax and the
 *   rate the shop's tax class applies at the order's addresses;
 * - charge totals (shipping, handling, the low-order fee) carry the tax and
 *   rate the shop's own tax engine gives their stored tax class at the
 *   order's addresses. OpenCart keeps no per-line tax for them, only the
 *   order's tax rows, so the adapter reproduces the merchant's own
 *   computation, and the default handler's shop-match check refuses the
 *   order unless the lines add up to the tax and total the shop actually
 *   charged. Tax is never invented. The payment terms fee (`two_fee`) is
 *   one of them, at the tax class the merchant selected for it;
 * - discount totals (coupon, reward points) are pre-tax: the shop takes
 *   their tax off its tax rows, so their tax is what the stored tax total
 *   leaves once every other line is counted. A discount cannot add tax,
 *   so a remainder above zero means another line's tax is wrong and the
 *   order is not built. With taxed lines at more than
 *   one rate that remainder cannot be given one rate, so the order is not
 *   built: the plugin never apportions it (see docs/divergences.md);
 * - a line whose tax class holds a fixed-amount rate is not built either:
 *   a Two line carries one percentage rate, and Two refuses the rest;
 * - post-tax totals (store credit, vouchers) reduce what the buyer owes and
 *   carry no tax.
 *
 * The same input always gives the same payload: no clock, no randomness.
 */
final class OrderBuilder
{
    /** Totals that add a taxed charge, and the Two line type each becomes. */
    public const CHARGE_CODES = ['shipping' => 'SHIPPING_FEE', 'handling' => 'SERVICE', 'low_order_fee' => 'SERVICE', 'two_fee' => 'SERVICE'];
    public const DISCOUNT_CODES = ['coupon', 'reward'];
    public const POST_TAX_CODES = ['credit', 'voucher'];
    /** Totals the payload already states through its lines. */
    public const SUMMARY_CODES = ['sub_total', 'tax', 'total'];

    /** Days from placement the expected delivery date is set to, as the reference plugin's default. */
    public const DELIVERY_DAYS = 7;

    /**
     * @param array{
     *     order_id: int, currency: string, currency_value: float|string, total: float|string, tax_total: float|string,
     *     date_added: string, comment?: string, carrier_name?: string,
     *     company: array{company_name: string, organization_number: string, country_code: string},
     *     buyer: array{email: string, first_name: string, last_name: string, phone_number: string},
     *     billing_address: array<string, string>, shipping_address?: array<string, string>|null,
     *     merchant_urls: array<string, string>,
     *     buyer_fields?: array<string, string>,
     *     terms?: array<string, mixed>|null,
     *     fee_refusal?: Message|null,
     *     currency_refusal?: Message|null,
     *     tax_codes?: array{map?: string|null, exempt?: bool, recorded?: array<string, string>|null}|null,
     *     buyer_vat_number?: string|null
     * } $order
     * @param list<array{name: string, quantity: int|string, price: float|string, total: float|string, tax: float|string, rate: float|null, fixed_tax?: bool, type: string, product_id?: int, tax_key?: array{class: int, rates: list<array{id: int, rate: float, type: string}>}|null}> $lines
     * @param list<array{code: string, title: string, value: float|string, tax?: float|string|null, rate?: float|null, fixed_tax?: bool, tax_key?: array{class: int, rates: list<array{id: int, rate: float, type: string}>}|null}> $totals
     * @param array<string, string>|null $taxCodes set to the placement record of the codes this build gave (TaxCodes::record)
     * @param-out array<string, string> $taxCodes
     * @return array<string, mixed>
     *
     * @throws OrderRefused when the order cannot be built from what the shop stored
     */
    public function build(array $order, array $lines, array $totals, ?array &$taxCodes = null): array
    {
        $fx = (float) $order['currency_value'];
        if ($fx <= 0) {
            throw OrderRefused::because('currency_value', Message::of('refusal_currency_value'));
        }
        // A rebuilt order whose currency an edit changed from the one it was placed in (the adapter says why).
        if (($order['currency_refusal'] ?? null) !== null) {
            throw OrderRefused::because('currency_changed', $order['currency_refusal']);
        }
        if ($lines === []) {
            throw OrderRefused::because('empty_order', Message::of('refusal_empty_order'));
        }

        $items = [];
        // Section 22: each line's tax class and the rates applied, null for a line with none (step 4).
        $keys = [];
        foreach ($lines as $line) {
            $items[] = self::productLine($line, $fx);
            $keys[] = self::recordKey($line['tax_key'] ?? null, isset($line['product_id']) ? 'product:' . $line['product_id'] : null);
        }
        $discounts = [];
        $postTax = [];
        foreach ($totals as $total) {
            $code = $total['code'];
            $value = (float) $total['value'];
            if (in_array($code, self::SUMMARY_CODES, true) || abs($value) < 0.00005) {
                continue;
            }
            if (isset(self::CHARGE_CODES[$code])) {
                $items[] = self::chargeLine($total, self::CHARGE_CODES[$code], $fx);
                $keys[] = self::recordKey($total['tax_key'] ?? null, $code);
            } elseif (in_array($code, self::DISCOUNT_CODES, true)) {
                $discounts[] = $total;
            } elseif (in_array($code, self::POST_TAX_CODES, true)) {
                $postTax[] = $total;
            } else {
                throw OrderRefused::because('unsupported_total', Message::of('refusal_unsupported_total', $code));
            }
        }
        // A rebuilt order placed with a payment terms fee whose fee line is gone (the adapter says why).
        if (($order['fee_refusal'] ?? null) !== null) {
            throw OrderRefused::because('fee_missing', $order['fee_refusal']);
        }
        if ($discounts !== []) {
            $items[] = self::discountLine($discounts, $items, self::cents($order['tax_total'], $fx), $fx);
        }
        foreach ($postTax as $total) {
            $gross = self::cents($total['value'], $fx);
            $items[] = self::line($total['title'], 'DIGITAL', 1, $gross, $gross, 0, 0.0, 'item');
        }
        // Codes come only from the merchant's rows, set before the hook so a subscriber sees them.
        $items = TaxCodes::apply($items, $keys, $order['tax_codes'] ?? null);
        $taxCodes = ($order['tax_codes'] ?? null) === null ? [] : TaxCodes::record($items, $keys);

        $company = $order['company'];
        $billing = $order['billing_address'];
        $payload = [
            'currency' => $order['currency'],
            'gross_amount' => '0.00',
            'net_amount' => '0.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'discount_rate' => '0',
            'merchant_order_id' => (string) $order['order_id'],
            'merchant_reference' => (string) $order['order_id'],
            'merchant_additional_info' => '',
            'order_note' => (string) ($order['comment'] ?? ''),
            'buyer' => [
                'company' => [
                    'company_name' => $company['company_name'],
                    'country_prefix' => $company['country_code'],
                    'organization_number' => $company['organization_number'],
                    'website' => '',
                ],
                'representative' => $order['buyer'],
            ],
            'billing_address' => ['organization_name' => $company['company_name']] + $billing,
            'shipping_address' => ['organization_name' => $company['company_name']] + ($order['shipping_address'] ?? $billing),
            'shipping_details' => [
                'carrier_name' => (string) ($order['carrier_name'] ?? ''),
                'tracking_number' => '',
                'expected_delivery_date' => self::deliveryDate($order['date_added']),
            ],
            'merchant_urls' => $order['merchant_urls'],
            'recurring' => false,
            'line_items' => $items,
            'tax_subtotals' => [],
        ];
        // The optional buyer fields (section 6.1), sent only when the buyer gave one.
        $fields = is_array($order['buyer_fields'] ?? null) ? $order['buyer_fields'] : [];
        foreach (['department' => 'buyer_department', 'project' => 'buyer_project', 'purchase_order_number' => 'buyer_purchase_order_number'] as $field => $key) {
            if (trim((string) ($fields[$field] ?? '')) !== '') {
                $payload[$key] = trim((string) $fields[$field]);
            }
        }
        // Section 4: the term the buyer chose, as the order is booked on it.
        if (is_array($order['terms'] ?? null) && $order['terms'] !== []) {
            $payload['terms'] = $order['terms'];
        }
        if (trim((string) ($fields['invoice_email'] ?? '')) !== '') {
            $payload['invoice_details'] = ['invoice_emails' => [trim((string) $fields['invoice_email'])]];
        }
        // Section 22.4: a Spanish merchant's order tells Two the buyer's VAT number. The adapter
        // passes it only where it is sent; absent rather than empty, so other payloads are unchanged.
        // The order edit drops it, since Two keeps the number given at create.
        if (($order['buyer_vat_number'] ?? null) !== null) {
            $payload['buyer_vat_number'] = $order['buyer_vat_number'];
        }

        return Postprocessing::recomputeTotals($payload);
    }

    /**
     * A line's tax-code key with the key its code is recorded under at
     * placement (`product:<id>`, or the charge total's code), null for a
     * line with no tax class.
     *
     * @param array{class: int, rates: list<array{id: int, rate: float, type: string}>}|null $key
     * @return array{class: int, rates: list<array{id: int, rate: float, type: string}>, line: string|null}|null
     */
    private static function recordKey(?array $key, ?string $line): ?array
    {
        return $key === null ? null : $key + ['line' => $line];
    }

    /**
     * The default handler's shop-match checks (section 21.4): whether the
     * payload still adds up to what the shop charged, which Two's API cannot
     * see. Each line's rounding to cents may move a sum by up to a cent, so
     * the tolerance is a cent per line. Public, so a merchant handler can
     * opt back in on the payload it returns.
     *
     * @param array<string, mixed>                                                            $payload
     * @param array{currency_value: float|string, total: float|string, tax_total: float|string} $order
     * @return list<Message> one sentence per failed check, empty when the payload matches
     */
    public static function shopMatchFailures(array $payload, array $order): array
    {
        $fx = (float) $order['currency_value'];
        $lines = is_array($payload['line_items'] ?? null) ? count($payload['line_items']) : 0;
        $tolerance = max(1, $lines);
        $checks = [
            'gross' => [Postprocessing::cents($payload['gross_amount'] ?? null), self::cents($order['total'], $fx), 'refusal_shop_match_gross'],
            'tax' => [Postprocessing::cents($payload['tax_amount'] ?? null), self::cents($order['tax_total'], $fx), 'refusal_shop_match_tax'],
        ];
        $failures = [];
        foreach ($checks as [$sent, $shop, $key]) {
            if (abs($sent - $shop) > $tolerance) {
                $failures[] = Message::of($key, Postprocessing::money($sent), Postprocessing::money($shop));
            }
        }

        return $failures;
    }

    /**
     * A rate as Two takes it: a decimal fraction, no trailing zeros.
     */
    public static function rate(float $rate): string
    {
        $formatted = rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @param array{name: string, quantity: int|string, price: float|string, total: float|string, tax: float|string, rate: float|null, fixed_tax?: bool, type: string} $line
     * @return array<string, mixed>
     */
    private static function productLine(array $line, float $fx): array
    {
        $quantity = (int) $line['quantity'];
        $net = self::cents($line['total'], $fx);
        $tax = self::cents((float) $line['tax'] * $quantity, $fx);
        if ($quantity <= 0 || $net < 0 || $tax < 0) {
            throw OrderRefused::because('negative_line', Message::of('refusal_negative_line', $line['name']));
        }
        self::refuseFixedTax($line['fixed_tax'] ?? false, $line['name']);
        $rate = $line['rate'];
        if ($rate === null) {
            if ($tax !== 0) {
                throw OrderRefused::because('line_rate_unknown', Message::of('refusal_line_rate_unknown', $line['name']));
            }
            $rate = 0.0;
        }

        return self::line($line['name'], $line['type'], $quantity, self::cents($line['price'], $fx), $net, $tax, $rate, 'pcs');
    }

    /**
     * @param array{code: string, title: string, value: float|string, tax?: float|string|null, rate?: float|null, fixed_tax?: bool} $total
     * @return array<string, mixed>
     */
    private static function chargeLine(array $total, string $type, float $fx): array
    {
        $net = self::cents($total['value'], $fx);
        $tax = self::cents($total['tax'] ?? 0, $fx);
        self::refuseFixedTax($total['fixed_tax'] ?? false, $total['title']);
        $rate = $total['rate'] ?? null;
        if ($rate === null) {
            if ($tax !== 0) {
                throw OrderRefused::because('line_rate_unknown', Message::of('refusal_line_rate_unknown', $total['title']));
            }
            $rate = 0.0;
        }

        return self::line($total['title'], $type, 1, $net, $net, $tax, $rate, 'item');
    }

    /**
     * One line for every pre-tax discount, as the reference plugin combines
     * them. Its tax is the stored tax total less every other line's tax.
     *
     * @param non-empty-list<array{code: string, title: string, value: float|string}> $discounts
     * @param list<array<string, mixed>>                                             $items
     * @return array<string, mixed>
     */
    private static function discountLine(array $discounts, array $items, int $storedTax, float $fx): array
    {
        $net = 0;
        $titles = [];
        foreach ($discounts as $discount) {
            $net += self::cents($discount['value'], $fx);
            $titles[] = $discount['title'];
        }
        $tax = $storedTax;
        $rates = [];
        foreach ($items as $item) {
            $tax -= Postprocessing::cents($item['tax_amount']);
            if ((string) $item['tax_amount'] !== '0.00') {
                $rates[(string) $item['tax_rate']] = (float) $item['tax_rate'];
            }
        }
        if ($tax > 0) {
            throw OrderRefused::because('discount_tax_positive', Message::of('refusal_discount_tax_positive', Postprocessing::money($tax)));
        }
        if ($tax === 0) {
            $rate = 0.0;
        } elseif (count($rates) === 1) {
            $rate = (float) reset($rates);
        } elseif ($rates === []) {
            throw OrderRefused::because('discount_tax_unexplained', Message::of('refusal_discount_tax_unexplained', Postprocessing::money(-$tax)));
        } else {
            throw OrderRefused::because('discount_tax_ambiguous', Message::of('refusal_discount_tax_ambiguous', implode(', ', array_keys($rates))));
        }

        return self::line(implode(', ', $titles), 'DIGITAL', 1, $net, $net, $tax, $rate, 'item');
    }

    /**
     * One Two line from amounts in cents, as the order, an edit and a refund send it.
     *
     * @return array<string, mixed>
     */
    public static function line(string $name, string $type, int $quantity, int $unitPrice, int $net, int $tax, float $rate, string $unit): array
    {
        $percent = rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.');

        return [
            'name' => $name,
            'description' => $name,
            'type' => $type,
            'quantity' => $quantity,
            'quantity_unit' => $unit,
            'unit_price' => Postprocessing::money($unitPrice),
            'net_amount' => Postprocessing::money($net),
            'tax_amount' => Postprocessing::money($tax),
            'gross_amount' => Postprocessing::money($net + $tax),
            'discount_amount' => '0.00',
            'tax_rate' => self::rate($rate),
            'tax_class_name' => 'VAT ' . $percent . '%',
            'image_url' => '',
            'product_page_url' => '',
        ];
    }

    private static function refuseFixedTax(bool $fixed, string $name): void
    {
        if ($fixed) {
            throw OrderRefused::because('fixed_amount_tax', Message::of('refusal_fixed_amount_tax', $name));
        }
    }

    /** A stored base-currency amount in the order currency, in cents. */
    private static function cents(float|int|string $amount, float $fx): int
    {
        return (int) round(round((float) $amount * $fx, 6) * 100);
    }

    private static function deliveryDate(string $dateAdded): string
    {
        $placed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateAdded, new \DateTimeZone('UTC'));
        if ($placed === false) {
            throw OrderRefused::because('order_date', Message::of('refusal_order_date'));
        }

        return $placed->modify('+' . self::DELIVERY_DAYS . ' days')->format('Y-m-d');
    }
}
