<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Typed, validated view of the merchant's settings (Build Guide section 16,
 * the Magento layering). Reads go through the SettingsStore port, so this
 * class holds the rules and the adapter holds the storage.
 *
 * Holds the settings the core flow uses; the rest of the merchant's set
 * lands with the phases that read it. Writes from the admin go through
 * SettingsSave, which holds the save rules.
 */
final class SettingsProvider
{
    /**
     * Fresh-install defaults, unprefixed. The adapter seeds these on install
     * alongside its own platform keys. TLS verification is on and debug
     * logging off unless the merchant changes them on the Diagnostics tab.
     * The status maps hold order status ids, which only the shop knows, so
     * the adapter seeds them by status name (DEFAULT_STATUS_NAMES); these are
     * the values when no such status exists.
     */
    public const DEFAULTS = [
        'environment' => Environment::DEFAULT,
        'api_key' => '',
        'title' => '{}',
        'fulfil_status_ids' => '[]',
        'cancel_status_id' => '0',
        'refund_status_id' => '0',
        'confirmed_status_id' => '0',
        'vendor_name' => '',
        'subtitle' => '{}',
        'enable_order_intent' => '1',
        'show_about_link' => '1',
        'display_tooltips' => '1',
        'enable_tax_subtotals' => '1',
        'disable_ssl_verify' => '0',
        'debug_mode' => '0',
        'custom_headers' => '[]',
        'company_search_in_address' => '1',
        'address_lookup' => '1',
        'enable_invoice_email' => '1',
        'enable_po_number' => '1',
        'enable_project' => '1',
        'enable_department' => '1',
        'min_order_amount' => '',
        'min_order_basis' => '',
        'trusted_proxies' => '',
        'disable_rate_limit' => '0',
        'payment_terms' => '[30,60,90]',
        'payment_term_type' => PaymentTerms::STANDARD,
        'default_payment_term' => '',
        'surcharge_type' => Surcharge::NONE,
        'surcharge_differential' => '0',
        'surcharge_grid' => '{}',
        'surcharge_rounding_basis' => 'none',
        'surcharge_rounding_step' => '',
        'surcharge_line_description' => '',
        'surcharge_tax_class_id' => '',
    ];

    /**
     * The optional buyer fields at checkout (Build Guide section 6.1), in the
     * order the tile renders them, each with its toggle.
     */
    public const OPTIONAL_FIELDS = [
        'invoice_email' => 'enable_invoice_email',
        'purchase_order_number' => 'enable_po_number',
        'project' => 'enable_project',
        'department' => 'enable_department',
    ];

    public const BASIS_GROSS = 'gross';
    public const BASIS_NET = 'net';

    /** The checkout title in every language the merchant has not set one for. */
    public const DEFAULT_TITLE = 'Business invoice';

    /** The longest vendor name sent as a header value. */
    public const VENDOR_MAX = 255;

    /** The shop's own status names the maps start on: fulfil on Shipped, and so on. */
    public const DEFAULT_STATUS_NAMES = [
        'confirmed_status_id' => 'Processing',
        'fulfil_status_ids' => 'Shipped',
        'cancel_status_id' => 'Canceled',
        'refund_status_id' => 'Refunded',
    ];

    public function __construct(private readonly SettingsStore $store)
    {
    }

    public function apiKey(): string
    {
        return trim((string) $this->store->get('api_key'));
    }

    /** The stored environment value, unresolved; null when nothing is stored. */
    public function environment(): ?string
    {
        return $this->store->get('environment');
    }

    /**
     * The payment method title at checkout in one language, trimmed, or the
     * default when it has none: the language's own (from its language file),
     * else DEFAULT_TITLE.
     */
    public function title(int $languageId, string $default = ''): string
    {
        $titles = json_decode((string) $this->store->get('title'), true);
        $title = is_array($titles) && is_scalar($titles[(string) $languageId] ?? null) ? trim((string) $titles[(string) $languageId]) : '';

        if ($title !== '') {
            return $title;
        }

        return trim($default) === '' ? self::DEFAULT_TITLE : trim($default);
    }

    /**
     * The order statuses that fulfil the order with Two. Empty is a real
     * setting: the shop never fulfils automatically.
     *
     * @return list<int>
     */
    public function fulfilStatusIds(): array
    {
        $decoded = json_decode((string) $this->store->get('fulfil_status_ids'), true);
        $ids = [];
        foreach (is_array($decoded) ? $decoded : [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * The order status an order takes when Two confirms it (verified, ready
     * for fulfilment), or null when none is mapped.
     */
    public function confirmedStatusId(): ?int
    {
        return self::statusId($this->store->get('confirmed_status_id'));
    }

    /**
     * The status a Two-confirmed order is placed at: the merchant's
     * confirmed status, else the shop's default order status, as every shop
     * placed orders before the setting existed.
     */
    public function placedStatusId(int $shopDefault): int
    {
        return $this->confirmedStatusId() ?? $shopDefault;
    }

    /** The order status that cancels the order with Two, or null when none is mapped. */
    public function cancelStatusId(): ?int
    {
        return self::statusId($this->store->get('cancel_status_id'));
    }

    /** The order status that refunds the order in full with Two (used from P5), or null when none is mapped. */
    public function refundStatusId(): ?int
    {
        return self::statusId($this->store->get('refund_status_id'));
    }

    /**
     * The merchant's vendor name (General), sent as X-Vendor-Name with every
     * keyed request so Two can tell apart sites that share one merchant
     * account. Empty, the single-site case, sends nothing.
     */
    public function vendorName(): string
    {
        $vendor = trim((string) $this->store->get('vendor_name'));

        // Checked again on read, as custom headers are: a value written past the admin never reaches the wire.
        return self::isSendableVendorName($vendor) ? $vendor : '';
    }

    /** A vendor name goes as a header value: printable ASCII, at most VENDOR_MAX characters. */
    public static function isSendableVendorName(string $vendor): bool
    {
        return preg_match('/^[\x20-\x7E]+\z/', $vendor) === 1 && strlen($vendor) <= self::VENDOR_MAX;
    }

    /**
     * The status maps to start on the shop's own statuses, by name. An
     * existing install keeps placing at its default order status until the
     * merchant saves a confirmed status, so the confirmed status is named only
     * on a fresh install.
     *
     * @return array<string, string>
     */
    public static function statusNamesToSeed(bool $existingInstall): array
    {
        return $existingInstall ? array_diff_key(self::DEFAULT_STATUS_NAMES, ['confirmed_status_id' => true]) : self::DEFAULT_STATUS_NAMES;
    }

    /**
     * The optional subtitle under the payment method title at checkout, in
     * one language, trimmed. Empty renders no subtitle: the brand declares no
     * default tagline.
     */
    public function subtitle(int $languageId): string
    {
        $subtitles = json_decode((string) $this->store->get('subtitle'), true);

        return is_array($subtitles) && is_scalar($subtitles[(string) $languageId] ?? null) ? trim((string) $subtitles[(string) $languageId]) : '';
    }

    /**
     * Whether the tile asks the order intent as a preview once the company
     * is known (Checkout fields). Confirm asks its own intent whatever this
     * says. On unless switched off, as on a fresh install.
     */
    public function orderIntentPreview(): bool
    {
        return self::isOnByDefault($this->store->get('enable_order_intent'));
    }

    /** Whether the tile shows the "What is Two?" explainer link. On unless switched off. */
    public function showAboutLink(): bool
    {
        return self::isOnByDefault($this->store->get('show_about_link'));
    }

    /**
     * Validate tax subtotals (Order management): whether the per-rate
     * tax_subtotals split goes with order intents, orders, order edits and
     * refunds. On unless switched off, as on a fresh install.
     */
    public function taxSubtotals(): bool
    {
        return self::isOnByDefault($this->store->get('enable_tax_subtotals'));
    }

    /** Whether the optional fields show their help as a tooltip on the label. On unless switched off. */
    public function displayTooltips(): bool
    {
        return self::isOnByDefault($this->store->get('display_tooltips'));
    }

    /**
     * Where the company search sits (the placement switch): in the address
     * forms (the default) or in the payment tile.
     */
    public function companySearchInAddressArea(): bool
    {
        return trim((string) ($this->store->get('company_search_in_address') ?? '1')) !== '0';
    }

    /**
     * Whether the payment tile holds the company control: the placement
     * switch alone decides, never what was captured (TWO-26721).
     */
    public function companyControlInTile(): bool
    {
        return !$this->companySearchInAddressArea();
    }

    /**
     * "Autofill company address": whether a picked company's registered
     * address is written into the buyer's address form. On by default, and
     * off whenever the search is in the tile, which has no address to fill.
     */
    public function addressLookup(): bool
    {
        return $this->companySearchInAddressArea() && $this->addressLookupSwitch();
    }

    /** The merchant's own switch, as the admin page shows it. */
    public function addressLookupSwitch(): bool
    {
        return trim((string) ($this->store->get('address_lookup') ?? '1')) !== '0';
    }

    /**
     * The optional fields switched on, in render order. A toggle with no
     * stored value is on, as on a fresh install.
     *
     * @return list<string> keys of OPTIONAL_FIELDS
     */
    public function optionalFields(): array
    {
        $fields = [];
        foreach (self::OPTIONAL_FIELDS as $field => $key) {
            if (trim((string) ($this->store->get($key) ?? '1')) !== '0') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The merchant's own minimum order value, in the shop's default currency,
     * or null when none is set.
     */
    public function merchantMinimumOrder(): ?float
    {
        $value = trim((string) $this->store->get('min_order_amount'));

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** The basis the merchant chose for that minimum (gross or net), or null when unset. */
    public function merchantMinimumOrderBasis(): ?string
    {
        $basis = trim((string) $this->store->get('min_order_basis'));

        return in_array($basis, [self::BASIS_GROSS, self::BASIS_NET], true) ? $basis : null;
    }

    /**
     * The merchant's own proxies (Diagnostics > Trusted proxies), as IPs or
     * CIDR ranges: the rate limits read the caller through them.
     *
     * @return list<string>
     */
    public function trustedProxies(): array
    {
        return RateLimiter::parseProxies((string) $this->store->get('trusted_proxies'));
    }

    /** Whether the merchant switched off the per-caller limits on the checkout relays (Diagnostics); off by default. */
    public function rateLimitDisabled(): bool
    {
        return self::isOn($this->store->get('disable_rate_limit'));
    }

    /**
     * The terms the merchant ticked on the Payment terms tab. A shop with no
     * stored value reads the fresh-install ticks (30, 60 and 90 days, as the
     * reference plugin installs); an unreadable value ticks none.
     *
     * @return list<int>
     */
    public function tickedPaymentTerms(): array
    {
        $decoded = json_decode((string) ($this->store->get('payment_terms') ?? self::DEFAULTS['payment_terms']), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($decoded, static fn (mixed $days): bool => is_int($days) && $days > 0))));
    }

    /** Standard terms, or end of month; anything else reads as standard. */
    public function paymentTermType(): string
    {
        return trim((string) $this->store->get('payment_term_type')) === PaymentTerms::END_OF_MONTH ? PaymentTerms::END_OF_MONTH : PaymentTerms::STANDARD;
    }

    /** The merchant's own default term, or null when none is chosen. */
    public function defaultPaymentTerm(): ?int
    {
        $value = trim((string) $this->store->get('default_payment_term'));

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** TLS verification is on unless the merchant switched it off (section 13.2). */
    public function verifyTls(): bool
    {
        return !self::isOn($this->store->get('disable_ssl_verify'));
    }

    public function debugLogging(): bool
    {
        return self::isOn($this->store->get('debug_mode'));
    }

    /**
     * The stored custom request-header rows, shape-checked but otherwise
     * unvalidated (section 3.5): CustomHeaders decides what may be sent.
     *
     * @return list<array{name: string, value: string}>
     */
    public function customHeaderRows(): array
    {
        $decoded = json_decode((string) $this->store->get('custom_headers'), true);
        $rows = [];
        foreach (is_array($decoded) ? $decoded : [] as $row) {
            if (is_array($row) && is_scalar($row['name'] ?? null) && is_scalar($row['value'] ?? null)) {
                $rows[] = ['name' => (string) $row['name'], 'value' => (string) $row['value']];
            }
        }

        return $rows;
    }

    /**
     * Identity of the credentials in force: the API key and the environment it
     * is used against. Cached verdicts and the merchant record are bound to it,
     * so a new key or environment, by any route, reads as a cold cache. Never
     * logged or shown; a one-way hash so the stored cache never holds the key.
     */
    public function credentialFingerprint(): string
    {
        return hash('sha256', $this->apiKey() . '|' . (Environment::recognise($this->environment()) ?? Environment::SANDBOX));
    }

    public function store(): SettingsStore
    {
        return $this->store;
    }

    private static function statusId(?string $value): ?int
    {
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function isOn(?string $value): bool
    {
        return trim((string) $value) === '1';
    }

    /** A switch that is on until the merchant stores 0: a shop that predates it reads as a fresh install. */
    private static function isOnByDefault(?string $value): bool
    {
        return trim((string) ($value ?? '1')) !== '0';
    }
}
