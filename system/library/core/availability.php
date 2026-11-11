<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Whether Two may be offered at checkout (Build Guide section 18): an ordered
 * chain of silent checks. The first that fails names the withhold reason;
 * the buyer sees nothing, the log and the admin's health row say why.
 *
 * Only a definitive rejection of the API key withholds on Two's account: an
 * unreachable or erroring Two falls through to the cached merchant record,
 * which has no expiry, so an incident at Two never empties the checkout.
 * The fee-quote gate closes the chain: with a surcharge on, a cart whose fee
 * cannot be quoted is not offered Two rather than charged nothing, and the
 * health row names the last such failure.
 */
final class Availability
{
    public const DISABLED = 'disabled';
    public const NO_API_KEY = 'no_api_key';
    public const MERCHANT_UNIDENTIFIED = 'merchant_unidentified';
    public const KEY_REJECTED = 'key_rejected';
    public const BUYER_COUNTRIES_EMPTY = 'buyer_countries_empty';
    public const BUYER_COUNTRIES_MALFORMED = 'buyer_countries_malformed';
    public const CURRENCY = 'currency';
    public const BUYER_COUNTRY = 'buyer_country';
    public const NO_COUNTRY = 'no_country';
    public const BELOW_MINIMUM = 'below_minimum';
    public const FEE_QUOTE = 'fee_quote';
    public const NO_TERMS = 'no_terms';
    public const FEE_TOTAL_OFF = 'fee_total_off';
    public const TAX_CODE_MAP = 'tax_code_map_unreadable';

    /** The last fee-quote withhold, for the health row. */
    public const FEE_FAILURE_KEY = 'fee_quote_failure';

    /** The currencies Two invoices in. */
    public const SUPPORTED_CURRENCIES = ['NOK', 'GBP', 'SEK', 'USD', 'DKK', 'EUR'];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly RecordProvider $records,
        private readonly ApiKeyVerdict $verdict,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Null when Two may be offered for this cart, otherwise the withhold
     * reason (one of the constants) and its detail for the log.
     *
     * The cart's gross and net are in value_currency when given (a shop that
     * keeps its amounts in its default currency), else in the cart's currency.
     *
     * @param array{enabled: bool, currency: string, shop_currency: string, gross: float, net: float, buyer_country: string|null, value_currency?: string, fee_total?: bool} $checkout
     * @param \Closure(float, string, string): ?float $convert puts an amount from one currency into another, null when it cannot
     * @param (\Closure(): ?string)|null $feeQuote why the fee this cart would be charged cannot be quoted, null when it can (or no fee applies)
     * @return array{reason: string, detail: string}|null
     */
    public function withholdReason(array $checkout, \Closure $convert, ?\Closure $feeQuote = null): ?array
    {
        $shop = $this->shopReason($checkout['enabled'], $checkout['fee_total'] ?? true);
        if ($shop !== null) {
            return ['reason' => $shop, 'detail' => $shop];
        }
        $placement = $this->placementReason($checkout);
        if ($placement !== null) {
            return $placement;
        }
        $country = self::country($checkout['buyer_country']);
        if (!$this->settings->companySearchInAddressArea() && $country === null) {
            return ['reason' => self::NO_COUNTRY, 'detail' => 'no country resolves from the billing or shipping address, so the tile\'s search could not run'];
        }
        $currency = strtoupper(trim($checkout['currency']));
        $shortfall = MinimumOrder::from($this->records->record(), $this->settings, $checkout['shop_currency'])->shortfall($checkout['gross'], $checkout['net'], $checkout['value_currency'] ?? $currency, $convert);
        if ($shortfall !== null) {
            return ['reason' => self::BELOW_MINIMUM, 'detail' => $shortfall];
        }
        $unquotable = $feeQuote === null ? null : $feeQuote();
        $stored = $this->settings->store()->get(self::FEE_FAILURE_KEY);
        if ($unquotable !== null) {
            // Written when the reason changes, not on every withheld render: the time is when this failure began.
            if (($this->feeFailure()['detail'] ?? null) !== $unquotable) {
                $this->settings->store()->setMany([self::FEE_FAILURE_KEY => (string) json_encode(['at' => ($this->clock)(), 'detail' => $unquotable])]);
            }

            return ['reason' => self::FEE_QUOTE, 'detail' => $unquotable];
        }
        if ($feeQuote !== null && $stored !== null && $stored !== '') {
            $this->settings->store()->setMany([self::FEE_FAILURE_KEY => '']);
        }

        return null;
    }

    /**
     * The checks placement repeats, since the page may be stale (the buyer
     * switched currency or address after picking Two): the cart currency,
     * then the buyer country.
     *
     * @param array{currency: string, buyer_country: string|null} $checkout
     * @return array{reason: string, detail: string}|null
     */
    public function placementReason(array $checkout): ?array
    {
        $currency = strtoupper(trim($checkout['currency']));
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return ['reason' => self::CURRENCY, 'detail' => 'cart currency ' . ($currency === '' ? 'unresolved' : $currency) . ' is not one Two invoices in'];
        }
        $country = self::country($checkout['buyer_country']);
        $countries = BuyerCountries::fromRecord($this->records->record());
        if (!$countries->allows($country)) {
            return ['reason' => self::BUYER_COUNTRY, 'detail' => 'buyer country ' . ($country ?? 'unresolved') . ', supported buyer country list ' . $countries->state];
        }

        return null;
    }

    /**
     * The withhold reasons that hold for every cart, in chain order: the
     * health row names the first. $feeTotalEnabled is whether the shop runs
     * the payment terms fee order total: a surcharge it would not charge
     * withholds Two rather than refuse every placement.
     */
    public function shopReason(bool $enabled, bool $feeTotalEnabled = true): ?string
    {
        if (!$enabled) {
            return self::DISABLED;
        }
        if ($this->settings->apiKey() === '') {
            return self::NO_API_KEY;
        }
        $record = $this->records->record();
        if (!is_scalar($record['short_name'] ?? null) || trim((string) $record['short_name']) === '') {
            // No merchant record names the merchant yet: every restriction it carries is unknown.
            return self::MERCHANT_UNIDENTIFIED;
        }
        // The heartbeat: the cached verdict, checked live once it is due.
        if (ApiKeyVerdict::isDefinitive($this->verdict->status())) {
            return self::KEY_REJECTED;
        }
        $state = BuyerCountries::fromRecord($record)->state;
        if ($state === BuyerCountries::EMPTY) {
            return self::BUYER_COUNTRIES_EMPTY;
        }
        if ($state === BuyerCountries::MALFORMED) {
            return self::BUYER_COUNTRIES_MALFORMED;
        }
        if (PaymentTerms::from($record, $this->settings)->offered() === []) {
            return self::NO_TERMS;
        }
        $surcharge = Surcharge::from($this->settings);
        if (!$feeTotalEnabled && (!$surcharge->isKnown() || $surcharge->enabled())) {
            return self::FEE_TOTAL_OFF;
        }
        if (TaxCodes::parse($this->settings->store()->get(TaxCodes::MAP_KEY)) === null) {
            // A mapping the form could not have written: the order build would refuse a 0% line, so withhold Two before the buyer starts.
            return self::TAX_CODE_MAP;
        }

        return null;
    }

    /**
     * The admin's health row: why Two is not shown, or that it is, with the
     * per-cart constraints that may still withhold it. Not shown for the
     * currency only when none of the shop's enabled currencies is one Two
     * invoices in; otherwise the others are a per-cart constraint.
     *
     * @param list<string> $shopCurrencies the shop's enabled currencies
     * @return array{shown: bool, reason: string|null, floors: list<array{amount: float, currency: string, basis: string}>, buyer_countries: list<string>, refused_currencies: list<string>, fee_quote_failure: array{at: int, detail: string}|null}
     */
    public function health(bool $enabled, string $shopCurrency, array $shopCurrencies, bool $feeTotalEnabled = true): array
    {
        $refused = [];
        $usable = false;
        foreach ($shopCurrencies as $code) {
            $code = strtoupper(trim($code));
            if (in_array($code, self::SUPPORTED_CURRENCIES, true)) {
                $usable = true;
            } elseif ($code !== '' && !in_array($code, $refused, true)) {
                $refused[] = $code;
            }
        }
        $reason = $this->shopReason($enabled, $feeTotalEnabled) ?? ($usable ? null : self::CURRENCY);
        $record = $reason === null ? $this->records->record() : null;

        return [
            'shown' => $reason === null,
            'reason' => $reason,
            'floors' => $reason === null ? MinimumOrder::from($record, $this->settings, $shopCurrency)->bindingFloors() : [],
            'buyer_countries' => $reason === null ? BuyerCountries::fromRecord($record)->allowed : [],
            'refused_currencies' => $reason === null ? $refused : [],
            'fee_quote_failure' => $reason === null ? $this->feeFailure() : null,
        ];
    }

    /**
     * The last cart withheld because its fee could not be quoted, until a
     * quote succeeds again.
     *
     * @return array{at: int, detail: string}|null
     */
    private function feeFailure(): ?array
    {
        $failure = json_decode((string) $this->settings->store()->get(self::FEE_FAILURE_KEY), true);

        return is_array($failure) && is_int($failure['at'] ?? null) && is_string($failure['detail'] ?? null) ? ['at' => $failure['at'], 'detail' => $failure['detail']] : null;
    }

    private static function country(?string $country): ?string
    {
        return $country === null || trim($country) === '' ? null : strtoupper(trim($country));
    }
}
