<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderRefused;

/**
 * The payload builder over the shop's stored rows. Each case is the order's
 * stored lines and totals and either the refusal reason or the lines sent
 * ("name|type|net|tax|gross|rate"), the order sums ("net|tax|gross|discount"),
 * the tax subtotals ("rate:taxable:tax", comma separated) and how many
 * shop-match checks fail. Amounts are base currency unless the case sets fx.
 */
final class OrderBuilderSpec
{
    public static function runAll(): void
    {
        $w = self::line('Widget', 2, 100, 25, 0.25);
        $a = self::line('Alpha', 1, 100, 25, 0.25);
        $b = self::line('Beta', 2, 50, 7.5, 0.15);
        $z = self::line('Zero', 1, 80, 0, null);
        $ship = ['code' => 'shipping', 'title' => 'Flat Shipping Rate', 'value' => 50, 'tax' => 12.5, 'rate' => 0.25];
        $coupon = ['code' => 'coupon', 'title' => 'Coupon (SAVE10)', 'value' => -20];
        $wl = 'Widget|PHYSICAL|200.00|50.00|250.00|0.25';
        $tiny = self::line('Tiny', 1, 0.125, 0.03125, 0.25);

        $cases = [
            ['lines' => [$w], 'totals' => [], 'order' => [250, 50], 'items' => [$wl], 'sums' => '200.00|50.00|250.00|0.00', 'subtotals' => '0.25:200.00:50.00', 'failures' => 0, 'description' => 'one line at 25%: stored net and unit tax times quantity'],
            ['lines' => [$a, $b], 'totals' => [], 'order' => [240, 40], 'items' => ['Alpha|PHYSICAL|100.00|25.00|125.00|0.25', 'Beta|PHYSICAL|100.00|15.00|115.00|0.15'], 'sums' => '200.00|40.00|240.00|0.00', 'subtotals' => '0.15:100.00:15.00,0.25:100.00:25.00', 'failures' => 0, 'description' => 'mixed rates: each line keeps its own rate, subtotals per rate in rate order'],
            ['lines' => [$z], 'totals' => [], 'order' => [80, 0], 'items' => ['Zero|PHYSICAL|80.00|0.00|80.00|0'], 'sums' => '80.00|0.00|80.00|0.00', 'subtotals' => '0:80.00:0.00', 'failures' => 0, 'description' => 'an untaxed line with no rate goes at 0%'],
            ['lines' => [self::line('Ebook', 1, 80, 0, 0.0, ['type' => 'DIGITAL'])], 'totals' => [], 'order' => [80, 0], 'items' => ['Ebook|DIGITAL|80.00|0.00|80.00|0'], 'sums' => '80.00|0.00|80.00|0.00', 'subtotals' => '0:80.00:0.00', 'failures' => 0, 'description' => 'a product with no shipping goes as DIGITAL'],
            ['lines' => [$w], 'totals' => [$ship], 'order' => [312.5, 62.5], 'items' => [$wl, 'Flat Shipping Rate|SHIPPING_FEE|50.00|12.50|62.50|0.25'], 'sums' => '250.00|62.50|312.50|0.00', 'subtotals' => '0.25:250.00:62.50', 'failures' => 0, 'description' => 'taxed shipping: the tax its class gives, as a shipping line'],
            ['lines' => [$w], 'totals' => [['rate' => null, 'tax' => 0] + $ship], 'order' => [300, 50], 'items' => [$wl, 'Flat Shipping Rate|SHIPPING_FEE|50.00|0.00|50.00|0'], 'sums' => '250.00|50.00|300.00|0.00', 'subtotals' => '0:50.00:0.00,0.25:200.00:50.00', 'failures' => 0, 'description' => 'untaxed shipping goes as recorded, at 0%'],
            ['lines' => [$w], 'totals' => [$ship], 'order' => [300, 50], 'items' => [$wl, 'Flat Shipping Rate|SHIPPING_FEE|50.00|12.50|62.50|0.25'], 'sums' => '250.00|62.50|312.50|0.00', 'subtotals' => '0.25:250.00:62.50', 'failures' => 2, 'description' => 'shipping tax that disagrees with what the shop charged fails both shop-match checks'],
            ['lines' => [$w], 'totals' => [['code' => 'handling', 'title' => 'Handling Fee', 'value' => 10, 'tax' => 2.5, 'rate' => 0.25]], 'order' => [262.5, 52.5], 'items' => [$wl, 'Handling Fee|SERVICE|10.00|2.50|12.50|0.25'], 'sums' => '210.00|52.50|262.50|0.00', 'subtotals' => '0.25:210.00:52.50', 'failures' => 0, 'description' => 'a handling fee is a taxed service line'],
            ['lines' => [$w], 'totals' => [['code' => 'two_fee', 'title' => 'Payment terms fee - 30 days', 'value' => 10, 'tax' => 2.5, 'rate' => 0.25]], 'order' => [262.5, 52.5], 'items' => [$wl, 'Payment terms fee - 30 days|SERVICE|10.00|2.50|12.50|0.25'], 'sums' => '210.00|52.50|262.50|0.00', 'subtotals' => '0.25:210.00:52.50', 'failures' => 0, 'description' => 'the payment terms fee is a service line at the net, tax and rate the shop gave it'],
            ['lines' => [$w], 'totals' => [['code' => 'two_fee', 'title' => 'Payment terms fee - 30 days', 'value' => 10, 'tax' => 0, 'rate' => 0.0]], 'order' => [260, 50], 'items' => [$wl, 'Payment terms fee - 30 days|SERVICE|10.00|0.00|10.00|0'], 'sums' => '210.00|50.00|260.00|0.00', 'subtotals' => '0:10.00:0.00,0.25:200.00:50.00', 'failures' => 0, 'description' => 'a fee at a 0% tax class goes untaxed, as the merchant set it'],
            ['lines' => [$w], 'totals' => [$coupon], 'order' => [225, 45], 'items' => [$wl, 'Coupon (SAVE10)|DIGITAL|-20.00|-5.00|-25.00|0.25'], 'sums' => '180.00|45.00|225.00|25.00', 'subtotals' => '0.25:180.00:45.00', 'failures' => 0, 'description' => 'a coupon at one rate: its tax is what the stored tax total leaves'],
            ['lines' => [$w], 'totals' => [$coupon, ['code' => 'reward', 'title' => 'Reward Points (100)', 'value' => -10]], 'order' => [212.5, 42.5], 'items' => [$wl, 'Coupon (SAVE10), Reward Points (100)|DIGITAL|-30.00|-7.50|-37.50|0.25'], 'sums' => '170.00|42.50|212.50|37.50', 'subtotals' => '0.25:170.00:42.50', 'failures' => 0, 'description' => 'coupon and reward points combine into one discount line'],
            ['lines' => [$a, $b], 'totals' => [$coupon], 'order' => [216, 36], 'refused' => 'discount_tax_ambiguous', 'description' => 'a discount that reduced tax at mixed rates is refused, never apportioned'],
            ['lines' => [$a, $b, self::line('Zero', 1, 20, 0, 0.0)], 'totals' => [$coupon], 'order' => [240, 40], 'items' => ['Alpha|PHYSICAL|100.00|25.00|125.00|0.25', 'Beta|PHYSICAL|100.00|15.00|115.00|0.15', 'Zero|PHYSICAL|20.00|0.00|20.00|0', 'Coupon (SAVE10)|DIGITAL|-20.00|0.00|-20.00|0'], 'sums' => '200.00|40.00|240.00|20.00', 'subtotals' => '0:0.00:0.00,0.15:100.00:15.00,0.25:100.00:25.00', 'failures' => 0, 'description' => 'a discount that left tax untouched goes at 0%, whatever the other rates'],
            ['lines' => [$w], 'totals' => [['tax' => 0, 'rate' => null] + $ship, $coupon], 'order' => [287.5, 57.5], 'refused' => 'discount_tax_positive', 'description' => 'a discount cannot soak up a charge line whose tax the adapter under-reported'],
            ['lines' => [$z], 'totals' => [['value' => -8] + $coupon], 'order' => [70, -2], 'refused' => 'discount_tax_unexplained', 'description' => 'a discount that reduced tax when no line carries any is refused'],
            ['lines' => [$z], 'totals' => [['value' => -8] + $coupon], 'order' => [72, 0], 'items' => ['Zero|PHYSICAL|80.00|0.00|80.00|0', 'Coupon (SAVE10)|DIGITAL|-8.00|0.00|-8.00|0'], 'sums' => '72.00|0.00|72.00|8.00', 'subtotals' => '0:72.00:0.00', 'failures' => 0, 'description' => 'a coupon on an untaxed order carries no tax'],
            ['lines' => [$w], 'totals' => [['code' => 'credit', 'title' => 'Store Credit', 'value' => -50]], 'order' => [200, 50], 'items' => [$wl, 'Store Credit|DIGITAL|-50.00|0.00|-50.00|0'], 'sums' => '150.00|50.00|200.00|50.00', 'subtotals' => '0:-50.00:0.00,0.25:200.00:50.00', 'failures' => 0, 'description' => 'store credit is a post-tax reduction with no tax'],
            ['lines' => [$w], 'totals' => [['code' => 'voucher', 'title' => 'Gift voucher', 'value' => -50]], 'order' => [200, 50], 'items' => [$wl, 'Gift voucher|DIGITAL|-50.00|0.00|-50.00|0'], 'sums' => '150.00|50.00|200.00|50.00', 'subtotals' => '0:-50.00:0.00,0.25:200.00:50.00', 'failures' => 0, 'description' => 'a voucher is a post-tax reduction with no tax'],
            ['lines' => [self::line('Widget', 2, 10, 2.5, 0.25)], 'totals' => [], 'order' => [25, 5, ['currency_value' => 10]], 'items' => [$wl], 'sums' => '200.00|50.00|250.00|0.00', 'subtotals' => '0.25:200.00:50.00', 'failures' => 0, 'description' => 'base-currency amounts convert at the order\'s stored currency value'],
            ['lines' => [self::line('Round', 3, 8.3333, 2.08333, 0.25, ['total' => 24.9999])], 'totals' => [], 'order' => [31.2499, 6.2499], 'items' => ['Round|PHYSICAL|25.00|6.25|31.25|0.25'], 'sums' => '25.00|6.25|31.25|0.00', 'subtotals' => '0.25:25.00:6.25', 'failures' => 0, 'description' => 'four-decimal stored amounts round to cents per line'],
            ['lines' => [$tiny, $tiny, $tiny, $tiny], 'totals' => [], 'order' => [0.625, 0.125], 'items' => array_fill(0, 4, 'Tiny|PHYSICAL|0.13|0.03|0.16|0.25'), 'sums' => '0.52|0.12|0.64|0.00', 'subtotals' => '0.25:0.52:0.12', 'failures' => 0, 'description' => 'rounding drift across lines stays within a cent per line'],
            ['lines' => [$w], 'totals' => [['code' => 'sub_total', 'title' => 'Sub-Total', 'value' => 200], ['code' => 'tax', 'title' => 'VAT (25%)', 'value' => 50], ['code' => 'total', 'title' => 'Total', 'value' => 250]], 'order' => [250, 50], 'items' => [$wl], 'sums' => '200.00|50.00|250.00|0.00', 'subtotals' => '0.25:200.00:50.00', 'failures' => 0, 'description' => 'sub-total, tax and total rows are stated by the lines, not sent'],
            ['lines' => [$w], 'totals' => [['code' => 'gift_wrap', 'title' => 'Gift wrap', 'value' => 0]], 'order' => [250, 50], 'items' => [$wl], 'sums' => '200.00|50.00|250.00|0.00', 'subtotals' => '0.25:200.00:50.00', 'failures' => 0, 'description' => 'an unknown total worth nothing is skipped'],
            ['lines' => [$w], 'totals' => [['code' => 'gift_wrap', 'title' => 'Gift wrap', 'value' => 5]], 'order' => [255, 50], 'refused' => 'unsupported_total', 'description' => 'an unknown total with a value is refused'],
            ['lines' => [self::line('Widget', 2, 100, 27, 0.25, ['fixed_tax' => true])], 'totals' => [], 'order' => [254, 54], 'refused' => 'fixed_amount_tax', 'description' => 'a product taxed with a fixed-amount rate is refused'],
            ['lines' => [$w], 'totals' => [['fixed_tax' => true] + $ship], 'order' => [312.5, 62.5], 'refused' => 'fixed_amount_tax', 'description' => 'shipping taxed with a fixed-amount rate is refused'],
            ['lines' => [self::line('Widget', 1, 100, 25, null)], 'totals' => [], 'order' => [125, 25], 'refused' => 'line_rate_unknown', 'description' => 'tax on a line whose class gives no rate is refused'],
            ['lines' => [$w], 'totals' => [['rate' => null] + $ship], 'order' => [312.5, 62.5], 'refused' => 'line_rate_unknown', 'description' => 'tax on shipping whose class gives no rate is refused'],
            ['lines' => [self::line('Widget', 0, 100, 25, 0.25)], 'totals' => [], 'order' => [0, 0], 'refused' => 'negative_line', 'description' => 'a line with no quantity is refused'],
            ['lines' => [self::line('Widget', 1, -100, 0, 0.0)], 'totals' => [], 'order' => [0, 0], 'refused' => 'negative_line', 'description' => 'a product line with a negative amount is refused'],
            ['lines' => [], 'totals' => [], 'order' => [0, 0], 'refused' => 'empty_order', 'description' => 'an order with no products is refused'],
            ['lines' => [$w], 'totals' => [], 'order' => [250, 50, ['currency_value' => 0]], 'refused' => 'currency_value', 'description' => 'an order with no currency value is refused'],
            ['lines' => [$w], 'totals' => [], 'order' => [250, 50, ['date_added' => 'yesterday']], 'refused' => 'order_date', 'description' => 'an order with no readable date is refused'],
        ];

        foreach ($cases as $case) {
            self::runCase($case);
        }
        self::testHeader();
        self::testDeterministic();
        self::testShopMatchSentences();
        self::testRateFormat();
        self::testTerms();
    }

    /** Section 4: the order carries the term it is booked on, and none when it has none. */
    private static function testTerms(): void
    {
        $eom = ['type' => 'NET_TERMS', 'duration_days' => 45, 'duration_days_calculated_from' => 'END_OF_MONTH'];
        $cases = [
            [['terms' => ['type' => 'NET_TERMS', 'duration_days' => 30]], ['type' => 'NET_TERMS', 'duration_days' => 30], 'a standard term'],
            [['terms' => $eom], $eom, 'an end-of-month term'],
            [['terms' => []], null, 'an order placed before terms were kept sends none'],
            [[], null, 'no terms given sends none'],
        ];
        $fee = ['code' => 'two_fee', 'title' => 'Payment terms fee - 30 days', 'value' => 10, 'tax' => 2.5, 'rate' => 0.25];
        $rebuilds = [
            [['fee_refusal' => Message::of('refusal_fee_missing')], [], 'fee_missing', 'an order placed with a fee that lost it is refused, with the adapter\'s reason'],
            [['fee_refusal' => null, 'total' => 262.5, 'tax_total' => 52.5], [$fee], null, 'an order carrying its fee line is built'],
            [['fee_refusal' => null], [], null, 'an order placed without a fee needs none'],
            [['currency_refusal' => Message::of('refusal_currency_changed', 'NOK', 'EUR')], [], 'currency_changed', 'an order whose currency an edit changed is refused, with the adapter\'s reason'],
            [['currency_refusal' => null, 'total' => 262.5, 'tax_total' => 52.5], [$fee], null, 'an order in the currency it was placed in is built'],
        ];
        foreach ($rebuilds as [$over, $totals, $refused, $description]) {
            try {
                (new OrderBuilder())->build(self::order(250, 50, $over), [self::line('Widget', 2, 100, 25, 0.25)], $totals);
                $code = null;
            } catch (OrderRefused $e) {
                $code = $e->reason;
            }
            TinyAssert::same($refused, $code, 'fee expected: ' . $description);
        }
        foreach ($cases as [$over, $expected, $description]) {
            $payload = (new OrderBuilder())->build(self::order(250, 50, $over), [self::line('Widget', 2, 100, 25, 0.25)], []);
            TinyAssert::same($expected, $payload['terms'] ?? null, 'terms: ' . $description);
        }
    }

    /** @param array<string, mixed> $case */
    private static function runCase(array $case): void
    {
        $d = $case['description'];
        $order = self::order(...$case['order']);
        try {
            $payload = (new OrderBuilder())->build($order, $case['lines'], $case['totals']);
        } catch (OrderRefused $refused) {
            TinyAssert::same($case['refused'] ?? 'no refusal', $refused->reason, $d . ': refusal');

            return;
        }
        TinyAssert::same($case['refused'] ?? null, null, $d . ': expected a refusal');
        $items = array_map(static fn (array $l): string => implode('|', [$l['name'], $l['type'], $l['net_amount'], $l['tax_amount'], $l['gross_amount'], $l['tax_rate']]), $payload['line_items']);
        TinyAssert::same($case['items'], $items, $d . ': lines');
        TinyAssert::same($case['sums'], implode('|', [$payload['net_amount'], $payload['tax_amount'], $payload['gross_amount'], $payload['discount_amount']]), $d . ': order sums');
        $subtotals = implode(',', array_map(static fn (array $s): string => $s['tax_rate'] . ':' . $s['taxable_amount'] . ':' . $s['tax_amount'], $payload['tax_subtotals']));
        TinyAssert::same($case['subtotals'], $subtotals, $d . ': tax subtotals');
        TinyAssert::same($case['failures'], count(OrderBuilder::shopMatchFailures($payload, $order)), $d . ': shop-match failures');
    }

    private static function testHeader(): void
    {
        $payload = (new OrderBuilder())->build(self::order(250, 50, ['comment' => 'Leave at door']), [self::line('Widget', 2, 100, 25, 0.25)], []);
        $cases = [
            ['path' => 'merchant_order_id', 'expected' => '42', 'description' => 'the shop order id'],
            ['path' => 'merchant_reference', 'expected' => '42', 'description' => 'a deterministic reference'],
            ['path' => 'currency', 'expected' => 'NOK', 'description' => 'the order currency'],
            ['path' => 'order_note', 'expected' => 'Leave at door', 'description' => 'the buyer comment'],
            ['path' => 'buyer.company.organization_number', 'expected' => '123456789', 'description' => 'the organisation number entered'],
            ['path' => 'buyer.company.country_prefix', 'expected' => 'NO', 'description' => 'the company country'],
            ['path' => 'buyer.representative.email', 'expected' => 'buyer@example.com', 'description' => 'the buyer email'],
            ['path' => 'billing_address.organization_name', 'expected' => 'Acme AS', 'description' => 'the company on the billing address'],
            ['path' => 'shipping_address.city', 'expected' => 'Oslo', 'description' => 'no shipping address: the billing address is used'],
            ['path' => 'shipping_details.expected_delivery_date', 'expected' => '2026-10-31', 'description' => 'delivery seven days after the stored order date'],
            ['path' => 'merchant_urls.merchant_confirmation_url', 'expected' => 'https://shop.example/confirm', 'description' => 'the callback URLs as given'],
            ['path' => 'line_items.0.unit_price', 'expected' => '100.00', 'description' => 'the stored unit price'],
            ['path' => 'line_items.0.tax_class_name', 'expected' => 'VAT 25%', 'description' => 'the rate as a class name'],
        ];
        foreach ($cases as $case) {
            $value = $payload;
            foreach (explode('.', $case['path']) as $key) {
                $value = $value[$key] ?? null;
            }
            TinyAssert::same($case['expected'], $value, 'header: ' . $case['description']);
        }
        // Two decides the invoice type from the merchant account and ignores a value sent, so none is sent.
        TinyAssert::true(!array_key_exists('invoice_type', $payload), 'header: no invoice type is sent');
    }

    private static function testDeterministic(): void
    {
        $build = static fn (): string => (string) json_encode((new OrderBuilder())->build(self::order(225, 45), [self::line('Widget', 2, 100, 25, 0.25)], [['code' => 'coupon', 'title' => 'Coupon', 'value' => -20]]));
        TinyAssert::same($build(), $build(), 'the same stored order builds the same payload');
    }

    private static function testShopMatchSentences(): void
    {
        $payload = ['gross_amount' => '312.50', 'tax_amount' => '62.50', 'line_items' => [[], []]];
        TinyAssert::same(
            ['The lines add up to 312.50 but the shop charged 300.00.', 'The lines carry 62.50 tax but the shop charged 50.00.'],
            array_map(static fn (Message $failure): string => $failure->english(), OrderBuilder::shopMatchFailures($payload, ['currency_value' => 1, 'total' => 300, 'tax_total' => 50])),
            'shop-match failures name both sums'
        );
    }

    private static function testRateFormat(): void
    {
        $cases = [[0.25, '0.25', 'a quarter'], [0.0, '0', 'zero'], [0.125, '0.125', 'three decimals'], [0.1234567, '0.123457', 'six decimals at most'], [1.0, '1', 'one']];
        foreach ($cases as [$rate, $expected, $description]) {
            TinyAssert::same($expected, OrderBuilder::rate($rate), 'rate format: ' . $description);
        }
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function order(float $total, float $tax, array $over = []): array
    {
        return $over + [
            'order_id' => 42, 'currency' => 'NOK', 'currency_value' => 1.0, 'total' => $total, 'tax_total' => $tax,
            'date_added' => '2026-10-24 10:00:00', 'comment' => '', 'carrier_name' => 'Flat rate',
            'company' => ['company_name' => 'Acme AS', 'organization_number' => '123456789', 'country_code' => 'NO'],
            'buyer' => ['email' => 'buyer@example.com', 'first_name' => 'Kari', 'last_name' => 'Nordmann', 'phone_number' => '+4799999999'],
            'billing_address' => ['street_address' => 'Gate 1', 'postal_code' => '0150', 'city' => 'Oslo', 'region' => '', 'country' => 'NO'],
            'shipping_address' => null,
            'merchant_urls' => ['merchant_confirmation_url' => 'https://shop.example/confirm'],
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function line(string $name, int $quantity, float $price, float $unitTax, ?float $rate, array $over = []): array
    {
        return $over + ['name' => $name, 'quantity' => $quantity, 'price' => $price, 'total' => $price * $quantity, 'tax' => $unitTax, 'rate' => $rate, 'type' => 'PHYSICAL'];
    }
}
