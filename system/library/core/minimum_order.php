<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The minimum order value below which Two is not offered (Build Guide
 * section 6.2), on a gross or net basis. Two floors can apply:
 *
 * - the platform's, from the merchant record (`min_order_amount`,
 *   `min_order_currency`, `min_order_basis`, all valid or none). When the
 *   cart's value cannot be put in its currency the cart is not offered Two
 *   (fails closed): Two would refuse the order anyway;
 * - the merchant's own, from the settings, in the shop's default currency,
 *   on the basis the merchant chose, else the platform's basis, else gross.
 *   When it cannot be judged it does not withhold: it is the merchant's
 *   preference, not a rule Two enforces.
 *
 * Two floors in the same currency on the same basis are one floor: the
 * higher binds.
 */
final class MinimumOrder
{
    /**
     * @param array{amount: float, currency: string, basis: string}|null $platform
     * @param array{amount: float, currency: string, basis: string}|null $merchant
     */
    public function __construct(public readonly ?array $platform, public readonly ?array $merchant)
    {
    }

    /** @param array<string, mixed>|null $record */
    public static function from(?array $record, SettingsProvider $settings, string $shopCurrency): self
    {
        $platform = self::platformFloor($record);
        $amount = $settings->merchantMinimumOrder();
        $shopCurrency = strtoupper(trim($shopCurrency));
        $merchant = $amount === null || $shopCurrency === '' ? null : [
            'amount' => $amount,
            'currency' => $shopCurrency,
            'basis' => $settings->merchantMinimumOrderBasis() ?? $platform['basis'] ?? SettingsProvider::BASIS_GROSS,
        ];

        return new self($platform, $merchant);
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array{amount: float, currency: string, basis: string}|null
     */
    public static function platformFloor(?array $record): ?array
    {
        $amount = $record['min_order_amount'] ?? null;
        $currency = $record['min_order_currency'] ?? null;
        $basis = $record['min_order_basis'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0 || !is_string($currency) || preg_match('~^[A-Za-z]{3}\z~', trim($currency)) !== 1
            || !in_array($basis, [SettingsProvider::BASIS_GROSS, SettingsProvider::BASIS_NET], true)) {
            return null;
        }

        return ['amount' => (float) $amount, 'currency' => strtoupper(trim($currency)), 'basis' => $basis];
    }

    /**
     * Why the cart is below a floor, or null when it is not. The cart's value
     * on each basis is in its own currency; $convert puts an amount from one
     * currency into another, or answers null when it cannot.
     *
     * @param \Closure(float, string, string): ?float $convert
     */
    public function shortfall(float $gross, float $net, string $currency, \Closure $convert): ?string
    {
        $currency = strtoupper(trim($currency));
        foreach (['platform' => $this->platform, 'merchant' => $this->merchant] as $which => $floor) {
            if ($floor === null) {
                continue;
            }
            $value = $floor['basis'] === SettingsProvider::BASIS_NET ? $net : $gross;
            $converted = $currency === $floor['currency'] ? round($value, 2) : ($currency === '' ? null : $convert($value, $currency, $floor['currency']));
            if ($converted === null) {
                if ($which === 'platform') {
                    return 'the platform minimum cannot be judged (no rate from ' . ($currency === '' ? '?' : $currency) . ' to ' . $floor['currency'] . ')';
                }
                continue;
            }
            if ($converted < $floor['amount']) {
                return 'below the ' . $which . ' minimum (' . number_format($converted, 2, '.', '') . ' ' . $floor['currency'] . ' ' . $floor['basis'] . ', minimum ' . number_format($floor['amount'], 2, '.', '') . ')';
            }
        }

        return null;
    }

    /**
     * The floors that bind, for the admin's health row: one per currency and basis.
     *
     * @return list<array{amount: float, currency: string, basis: string}>
     */
    public function bindingFloors(): array
    {
        $binding = [];
        foreach (array_filter([$this->platform, $this->merchant]) as $floor) {
            $key = $floor['currency'] . '|' . $floor['basis'];
            if (!isset($binding[$key]) || $floor['amount'] > $binding[$key]['amount']) {
                $binding[$key] = $floor;
            }
        }

        return array_values($binding);
    }
}
