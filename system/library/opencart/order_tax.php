<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

/**
 * OpenCart's own tax engine (Cart\Tax), set up for a stored order rather
 * than for the request: the order's customer group and addresses and its
 * store's settings. The storefront engine reads the customer group from the
 * session's config, and the confirm callback may arrive with no session (and
 * P5 rebuilds orders from the admin), so a rate looked up the storefront way
 * could differ from the one the buyer was charged.
 *
 * Tax reads nothing else from config, so a fresh registry holding only the
 * database and that one setting reproduces the computation OpenCart made at
 * checkout.
 */
final class OrderTax
{
    /** Store settings the scope reads, per store (OpenCart keeps them per store_id). */
    public const STORE_KEYS = ['config_country_id', 'config_zone_id', 'config_tax_default', 'config_customer_group_id'];

    /**
     * The customer group and addresses the order's tax is worked out at,
     * from the stored order and its store's settings only, as the
     * storefront's tax startup chooses them.
     *
     * @param array<string, mixed>  $order a stored order row
     * @param array<string, string> $store the order store's STORE_KEYS settings
     * @return array{customer_group_id: int, shipping: array{0: int, 1: int}|null, payment: array{0: int, 1: int}|null, store: array{0: int, 1: int}}
     */
    public static function scope(array $order, array $store): array
    {
        $storeAddress = [(int) ($store['config_country_id'] ?? 0), (int) ($store['config_zone_id'] ?? 0)];
        $default = (string) ($store['config_tax_default'] ?? '');
        $address = static function (string $role) use ($order, $storeAddress, $default): ?array {
            if ((int) ($order[$role . '_country_id'] ?? 0) > 0) {
                return [(int) $order[$role . '_country_id'], (int) ($order[$role . '_zone_id'] ?? 0)];
            }

            return $default === $role ? $storeAddress : null;
        };
        $group = (int) ($order['customer_group_id'] ?? 0);

        return [
            'customer_group_id' => $group > 0 ? $group : (int) ($store['config_customer_group_id'] ?? 0),
            'shipping' => $address('shipping'),
            'payment' => $address('payment'),
            'store' => $storeAddress,
        ];
    }

    /**
     * The order store's STORE_KEYS settings.
     *
     * @return array<string, string>
     */
    public static function storeSettings(\Opencart\System\Library\DB $db, int $storeId): array
    {
        $keys = implode("', '", self::STORE_KEYS);
        $query = $db->query('SELECT `store_id`, `key`, `value` FROM `' . DB_PREFIX . "setting` WHERE `store_id` IN ('0', '" . $storeId . "') AND `code` = 'config' AND `key` IN ('" . $keys . "')");

        return self::overlay(is_object($query) ? $query->rows : [], $storeId);
    }

    /**
     * Store N's settings laid over the default store's, as OpenCart's
     * setting startup loads them: a key store N lacks takes store 0's value.
     *
     * @param list<array<string, mixed>> $rows setting rows: store_id, key, value
     * @return array<string, string>
     */
    public static function overlay(array $rows, int $storeId): array
    {
        $settings = [];
        foreach ([0, $storeId] as $store) {
            foreach ($rows as $row) {
                if ((int) $row['store_id'] === $store && in_array($row['key'], self::STORE_KEYS, true)) {
                    $settings[(string) $row['key']] = (string) $row['value'];
                }
            }
        }

        return $settings;
    }

    /**
     * OpenCart's tax engine at the scope.
     *
     * @param array{customer_group_id: int, shipping: array{0: int, 1: int}|null, payment: array{0: int, 1: int}|null, store: array{0: int, 1: int}} $scope
     */
    public static function engine(\Opencart\System\Library\DB $db, array $scope): \Opencart\System\Library\Cart\Tax
    {
        $config = new \Opencart\System\Engine\Config();
        $config->set('config_customer_group_id', $scope['customer_group_id']);
        $registry = new \Opencart\System\Engine\Registry();
        $registry->set('db', $db);
        $registry->set('config', $config);
        $tax = new \Opencart\System\Library\Cart\Tax($registry);
        if ($scope['shipping'] !== null) {
            $tax->setShippingAddress(...$scope['shipping']);
        }
        if ($scope['payment'] !== null) {
            $tax->setPaymentAddress(...$scope['payment']);
        }
        $tax->setStoreAddress(...$scope['store']);

        return $tax;
    }

    /**
     * The percentage a tax class applies, as a fraction, and whether it also
     * holds a fixed-amount rate. Null rate when the class has no rate here.
     *
     * @return array{rate: float|null, fixed_tax: bool}
     */
    public static function rateOf(\Opencart\System\Library\Cart\Tax $tax, float $value, int $class): array
    {
        $percent = null;
        $fixed = false;
        foreach ($class > 0 ? $tax->getRates($value, $class) : [] as $rate) {
            if ($rate['type'] === 'F') {
                $fixed = true;
            } else {
                $percent = ($percent ?? 0.0) + (float) $rate['rate'] / 100;
            }
        }

        return ['rate' => $percent, 'fixed_tax' => $fixed];
    }

    /**
     * A line's tax-code key (section 22): its tax class and each rate the
     * class applies at the scope, as the shop's tax engine picks them. Null
     * for a line with no tax class, which takes the order's shared code.
     *
     * @return array{class: int, rates: list<array{id: int, rate: float, type: string}>}|null
     */
    public static function codeKey(\Opencart\System\Library\Cart\Tax $tax, float $value, int $class): ?array
    {
        if ($class <= 0) {
            return null;
        }
        $rates = [];
        foreach ($tax->getRates($value, $class) as $id => $rate) {
            $rates[] = ['id' => (int) ($rate['tax_rate_id'] ?? $id), 'rate' => (float) $rate['rate'], 'type' => (string) $rate['type']];
        }

        return ['class' => $class, 'rates' => $rates];
    }
}
