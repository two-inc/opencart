<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\OrderTax;

/**
 * The tax engine's scope for a stored order: customer group and addresses
 * from the order and its store's settings, never from the request. The
 * smoke runs OpenCart's real engine at this scope (dev/ci/order-tax-probe.php).
 */
final class OrderTaxSpec
{
    public static function runAll(): void
    {
        $store = ['config_country_id' => '222', 'config_zone_id' => '3563', 'config_tax_default' => 'shipping', 'config_customer_group_id' => '1'];
        $order = ['customer_group_id' => '2', 'shipping_country_id' => '160', 'shipping_zone_id' => '0', 'payment_country_id' => '160', 'payment_zone_id' => '7'];
        $cases = [
            ['order' => $order, 'store' => $store, 'expected' => '2|160,0|160,7|222,3563', 'description' => 'the order\'s own customer group and addresses'],
            ['order' => ['customer_group_id' => '0'] + $order, 'store' => $store, 'expected' => '1|160,0|160,7|222,3563', 'description' => 'no group on the order: the store\'s default group'],
            ['order' => ['shipping_country_id' => '0'] + $order, 'store' => $store, 'expected' => '2|222,3563|160,7|222,3563', 'description' => 'no shipping address, tax default shipping: the store address'],
            ['order' => ['payment_country_id' => '0'] + $order, 'store' => $store, 'expected' => '2|160,0|-|222,3563', 'description' => 'no payment address, tax default shipping: no payment rates'],
            ['order' => ['payment_country_id' => '0'] + $order, 'store' => ['config_tax_default' => 'payment'] + $store, 'expected' => '2|160,0|222,3563|222,3563', 'description' => 'no payment address, tax default payment: the store address'],
            ['order' => $order, 'store' => [], 'expected' => '2|160,0|160,7|0,0', 'description' => 'a store with no address settings'],
        ];
        foreach ($cases as $case) {
            $scope = OrderTax::scope($case['order'], $case['store']);
            $address = static fn (?array $a): string => $a === null ? '-' : implode(',', $a);
            $actual = $scope['customer_group_id'] . '|' . $address($scope['shipping']) . '|' . $address($scope['payment']) . '|' . $address($scope['store']);
            TinyAssert::same($case['expected'], $actual, 'order tax scope: ' . $case['description']);
        }

        $row = static fn (int $store, string $key, string $value): array => ['store_id' => (string) $store, 'key' => $key, 'value' => $value];
        $rows = [$row(0, 'config_country_id', '222'), $row(0, 'config_zone_id', '3563'), $row(0, 'config_customer_group_id', '1'), $row(2, 'config_country_id', '160'), $row(2, 'config_zone_id', '0'), $row(3, 'config_country_id', '203'), $row(0, 'config_name', 'ignored')];
        $overlays = [
            ['store' => 0, 'expected' => ['config_country_id' => '222', 'config_zone_id' => '3563', 'config_customer_group_id' => '1'], 'description' => 'the default store as stored'],
            ['store' => 2, 'expected' => ['config_country_id' => '160', 'config_zone_id' => '0', 'config_customer_group_id' => '1'], 'description' => 'store 2 over store 0: a key store 2 lacks takes store 0\'s value'],
            ['store' => 5, 'expected' => ['config_country_id' => '222', 'config_zone_id' => '3563', 'config_customer_group_id' => '1'], 'description' => 'a store with no rows of its own: store 0'],
        ];
        foreach ($overlays as $case) {
            TinyAssert::same($case['expected'], OrderTax::overlay($rows, $case['store']), 'store settings: ' . $case['description']);
        }
    }
}
