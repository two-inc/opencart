<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The admin save (Build Guide section 3.3): selective, so a field that fails
 * is kept as stored and named, and every other field is written.
 *
 * The API key and environment are one pair (the credentials). They are
 * written together or not at all, because a stored key is only good against
 * the environment it was issued for. A submitted pair replaces the stored one
 * only when it is well formed and:
 * - Two accepts it, or
 * - Two could not judge it (no answer, a 5xx) and the stored pair is not
 *   known good: its last definitive answer was not ok. The heartbeat is not
 *   asked, since an outage overwrites it. A rejected key (401 or 403) is never stored, and a key Two
 *   could not judge never replaces one that last verified.
 *
 * An empty key field means "keep the stored key", so the key never has to be
 * sent back to the browser. A field the form did not post is left alone.
 */
final class SettingsSave
{
    /** Credential outcomes. */
    public const SAVED = 'saved';
    public const SAVED_UNVERIFIED = 'saved_unverified';
    public const UNCHANGED = 'unchanged';
    public const MISSING = 'missing';
    public const KEPT_MALFORMED = 'kept_malformed';
    public const KEPT_REJECTED = 'kept_rejected';
    public const KEPT_UNVERIFIED = 'kept_unverified';
    public const KEPT_ENVIRONMENT = 'kept_environment';

    /** Field error codes. The adapter maps them, and the outcomes, to copy. */
    public const TITLE_MISSING = 'title_missing';
    public const STATUS_MISSING = 'status_missing';
    public const STATUS_UNKNOWN = 'status_unknown';
    /** The shop's void status: OpenCart sets it on orders being edited, so the order lifecycle never acts on it. */
    public const STATUS_VOID = 'status_void';
    /** The confirmed status is also a fulfil, cancel or refund status, so confirming an order would act on it at once. */
    public const STATUS_CONFLICT = 'status_conflict';
    public const VENDOR_INVALID = 'vendor_invalid';
    public const SWITCH_INVALID = 'switch_invalid';
    public const MINIMUM_INVALID = 'minimum_invalid';
    public const BASIS_INVALID = 'basis_invalid';
    public const PROXY_INVALID = 'proxy_invalid';
    public const TERM_TYPE_UNKNOWN = 'term_type_unknown';
    public const TERMS_NONE = 'terms_none';
    public const TERMS_UNKNOWN = 'terms_unknown';
    public const DEFAULT_TERM_UNKNOWN = 'default_term_unknown';

    /** The on/off settings: the placement switch, the tile's switches and the optional field toggles (Checkout fields), Validate tax subtotals (Order management) and the rate-limit switch (Diagnostics). */
    public const SWITCHES = ['company_search_in_address', 'address_lookup', 'enable_order_intent', 'show_about_link', 'display_tooltips', 'enable_invoice_email', 'enable_po_number', 'enable_project', 'enable_department', 'enable_tax_subtotals', 'disable_rate_limit'];

    /** Printable ASCII with no spaces: a key is sent as a header value, so nothing else may reach the wire. */
    private const KEY = '/^[\x21-\x7E]+\z/';

    /** The vendor name is sent as a header value: printable ASCII, at most VENDOR_MAX characters. */
    private const VENDOR = '/^[\x20-\x7E]+\z/';
    public const VENDOR_MAX = 255;

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly Environment $environment,
        private readonly ApiKeyVerdict $verdict,
        private readonly RecordProvider $records,
    ) {
    }

    /**
     * Validates and writes the submitted settings in one write, then brings
     * the verdict (and, for new credentials, the merchant record) up to date.
     *
     * @param array{api_key?: string, environment?: string, vendor_name?: string, title?: array<int, string>, subtitle?: array<int, string>, fulfil_status_ids?: list<string>, confirmed_status_id?: string, cancel_status_id?: string, refund_status_id?: string, enable_order_intent?: string, show_about_link?: string, display_tooltips?: string, company_search_in_address?: string, address_lookup?: string, enable_invoice_email?: string, enable_po_number?: string, enable_project?: string, enable_department?: string, min_order_amount?: string, min_order_basis?: string, trusted_proxies?: string, disable_rate_limit?: string, payment_term_type?: string, payment_terms?: list<string>, default_payment_term?: string, surcharge_type?: string, surcharge_differential?: string, surcharge_rounding_basis?: string, surcharge_rounding_step?: string, surcharge_line_description?: string, surcharge_tax_class_id?: string, surcharge_grid?: array<int|string, array<string, string>>} $submitted
     * @param list<int> $orderStatusIds the shop's order statuses, which the maps may name
     * @param int       $voidStatusId   the shop's void status (0 for none), which no map may name
     * @param list<int> $taxClassIds    the shop's tax classes, which the surcharge's tax class must be one of
     * @return array{errors: array<string, string>, credentials: string|null, status: string|null, code: int|null}
     */
    public function save(array $submitted, array $orderStatusIds, int $voidStatusId, array $taxClassIds = []): array
    {
        $write = [];
        $errors = [];

        // The Payment terms tab reads the merchant record's terms; other saves never ask for it.
        if (array_intersect_key($submitted, array_flip(['payment_term_type', 'payment_terms', 'default_payment_term', 'surcharge_type'])) !== []) {
            [$termWrite, $termErrors] = $this->paymentTerms($submitted);
            $surcharge = Surcharge::validate($submitted, PaymentTerms::recordTerms($this->records->record()), $taxClassIds);
            $write += $termWrite + $surcharge['write'];
            $errors += $termErrors + $surcharge['errors'];
        }

        if (isset($submitted['title'])) {
            $titles = array_map('trim', $submitted['title']);
            if (in_array('', $titles, true)) {
                $errors['title'] = self::TITLE_MISSING;
            } else {
                $write['title'] = (string) json_encode((object) $titles, JSON_UNESCAPED_UNICODE);
            }
        }

        if (isset($submitted['fulfil_status_ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('trim', $submitted['fulfil_status_ids']), static fn (string $id): bool => $id !== '')));
            if (array_diff($ids, array_map('strval', $orderStatusIds)) !== []) {
                $errors['fulfil_status_ids'] = self::STATUS_UNKNOWN;
            } elseif ($voidStatusId > 0 && in_array((string) $voidStatusId, $ids, true)) {
                $errors['fulfil_status_ids'] = self::STATUS_VOID;
            } else {
                $write['fulfil_status_ids'] = (string) json_encode(array_map('intval', $ids));
            }
        }

        foreach (['confirmed_status_id', 'cancel_status_id', 'refund_status_id'] as $key) {
            if (!isset($submitted[$key])) {
                continue;
            }
            $id = trim($submitted[$key]);
            $error = match (true) {
                $id === '' || $id === '0' => self::STATUS_MISSING,
                !in_array($id, array_map('strval', $orderStatusIds), true) => self::STATUS_UNKNOWN,
                $voidStatusId > 0 && $id === (string) $voidStatusId => self::STATUS_VOID,
                default => null,
            };
            if ($error !== null) {
                $errors[$key] = $error;
            } else {
                $write[$key] = $id;
            }
        }
        if (isset($write['confirmed_status_id'])) {
            // Placing an order at a status the lifecycle acts on would fulfil, cancel or refund it the moment
            // Two confirms it. Judged against the maps as they will stand after this save.
            $fulfil = isset($write['fulfil_status_ids']) ? (array) json_decode($write['fulfil_status_ids'], true) : $this->settings->fulfilStatusIds();
            $acting = array_merge(array_map('intval', $fulfil), [
                (int) ($write['cancel_status_id'] ?? $this->settings->cancelStatusId()),
                (int) ($write['refund_status_id'] ?? $this->settings->refundStatusId()),
            ]);
            if (in_array((int) $write['confirmed_status_id'], $acting, true)) {
                $errors['confirmed_status_id'] = self::STATUS_CONFLICT;
                unset($write['confirmed_status_id']);
            }
        }

        if (isset($submitted['vendor_name'])) {
            $vendor = trim($submitted['vendor_name']);
            if ($vendor === '' || (preg_match(self::VENDOR, $vendor) === 1 && strlen($vendor) <= self::VENDOR_MAX)) {
                $write['vendor_name'] = $vendor;
            } else {
                $errors['vendor_name'] = self::VENDOR_INVALID;
            }
        }

        if (isset($submitted['subtitle'])) {
            // Optional in every language: blank shows no subtitle.
            $write['subtitle'] = (string) json_encode((object) array_map('trim', $submitted['subtitle']), JSON_UNESCAPED_UNICODE);
        }

        foreach (self::SWITCHES as $key) {
            if (isset($submitted[$key])) {
                $value = trim($submitted[$key]);
                if (in_array($value, ['0', '1'], true)) {
                    $write[$key] = $value;
                } else {
                    $errors[$key] = self::SWITCH_INVALID;
                }
            }
        }
        if (isset($submitted['min_order_amount'])) {
            // Empty is no minimum; otherwise a positive amount with at most two decimals.
            $amount = trim($submitted['min_order_amount']);
            if ($amount === '' || (preg_match('~^\d{1,10}(\.\d{1,2})?\z~', $amount) === 1 && (float) $amount > 0)) {
                $write['min_order_amount'] = $amount;
            } else {
                $errors['min_order_amount'] = self::MINIMUM_INVALID;
            }
        }
        if (isset($submitted['min_order_basis'])) {
            $basis = trim($submitted['min_order_basis']);
            if (in_array($basis, ['', SettingsProvider::BASIS_GROSS, SettingsProvider::BASIS_NET], true)) {
                $write['min_order_basis'] = $basis;
            } else {
                $errors['min_order_basis'] = self::BASIS_INVALID;
            }
        }

        if (isset($submitted['trusted_proxies'])) {
            $entries = RateLimiter::parseProxies($submitted['trusted_proxies']);
            if (array_filter($entries, static fn (string $entry): bool => !RateLimiter::isValidProxyEntry($entry)) === []) {
                $write['trusted_proxies'] = trim($submitted['trusted_proxies']);
            } else {
                $errors['trusted_proxies'] = self::PROXY_INVALID;
            }
        }

        $credentials = null;
        $checked = ['status' => null, 'code' => null];
        if (isset($submitted['environment'])) {
            [$credentials, $pair, $checked] = $this->credentials(trim($submitted['api_key'] ?? ''), trim($submitted['environment']));
            $write += $pair;
        }

        if ($write !== []) {
            $this->settings->store()->setMany($write);
        }

        if ($credentials === self::SAVED) {
            // New credentials: the record the shop holds describes the old ones.
            $this->records->refresh();
        } elseif ($credentials === self::UNCHANGED) {
            $checked = $this->verdict->verify();
        }

        return ['errors' => $errors, 'credentials' => $credentials, 'status' => $checked['status'], 'code' => $checked['code']];
    }

    /**
     * The Payment terms tab: the term type, the ticked terms (at least one of
     * those the merchant record offers for that type) and the default term
     * (blank, or one of the ticked terms).
     *
     * @param array<string, mixed> $submitted
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function paymentTerms(array $submitted): array
    {
        $write = [];
        $errors = [];
        $type = $this->settings->paymentTermType();
        if (isset($submitted['payment_term_type'])) {
            $type = trim((string) $submitted['payment_term_type']);
            if (in_array($type, PaymentTerms::TYPES, true)) {
                $write['payment_term_type'] = $type;
            } else {
                $errors['payment_term_type'] = self::TERM_TYPE_UNKNOWN;
                $type = $this->settings->paymentTermType();
            }
        }
        $record = PaymentTerms::recordTerms($this->records->record());
        $configurable = (new PaymentTerms($record, [], $type, null, null))->configurable();
        $ticked = $this->settings->tickedPaymentTerms();
        if (isset($submitted['payment_terms']) && is_array($submitted['payment_terms'])) {
            $days = array_values(array_unique(array_map(static fn (mixed $day): string => trim((string) $day), $submitted['payment_terms'])));
            $days = array_values(array_filter($days, static fn (string $day): bool => $day !== ''));
            if (array_filter($days, static fn (string $day): bool => !ctype_digit($day) || !in_array((int) $day, $record, true)) !== []) {
                $errors['payment_terms'] = self::TERMS_UNKNOWN;
            } elseif ($configurable !== [] && array_intersect(array_map('intval', $days), $configurable) === []) {
                $errors['payment_terms'] = self::TERMS_NONE;
            } else {
                $ticked = array_map('intval', $days);
                sort($ticked);
                $write['payment_terms'] = (string) json_encode($ticked);
            }
        }
        if (isset($submitted['default_payment_term'])) {
            $default = trim((string) $submitted['default_payment_term']);
            $offered = (new PaymentTerms($record, $ticked, $type, null, null))->offered();
            if ($default === '' || (ctype_digit($default) && in_array((int) $default, $offered, true))) {
                $write['default_payment_term'] = $default;
            } else {
                $errors['default_payment_term'] = self::DEFAULT_TERM_UNKNOWN;
            }
        }

        return [$write, $errors];
    }

    /** Whether a key may be sent to Two at all. The live check applies the same rule. */
    public static function isWellFormedKey(string $key): bool
    {
        return preg_match(self::KEY, $key) === 1;
    }

    /**
     * The outcome for a submitted pair, the settings to write for it, and
     * Two's answer when it was asked.
     *
     * @return array{0: string, 1: array<string, string>, 2: array{status: string|null, code: int|null}}
     */
    private function credentials(string $apiKey, string $environment): array
    {
        $none = ['status' => null, 'code' => null];
        $storedKey = $this->settings->apiKey();
        $storedEnvironment = $this->settings->environment();
        $key = $apiKey === '' ? $storedKey : $apiKey;

        if (!$this->environment->acceptsSave($environment, $storedEnvironment)) {
            return [self::KEPT_ENVIRONMENT, [], $none];
        }
        if ($key === '') {
            // Nothing stored and nothing typed: there is no key to protect, so the environment is kept.
            return [self::MISSING, ['environment' => $environment], $none];
        }
        if (!self::isWellFormedKey($key)) {
            return [self::KEPT_MALFORMED, [], $none];
        }
        if ($key === $storedKey && Environment::recognise($environment) === Environment::recognise($storedEnvironment)) {
            return [self::UNCHANGED, ['environment' => $environment], $none];
        }

        $checked = $this->verdict->check($key, $environment);
        $answer = ['status' => $checked['status'], 'code' => $checked['code']];
        $pair = ['api_key' => $key, 'environment' => $environment];
        if ($checked['status'] === ApiKeyVerdict::OK) {
            return [self::SAVED, $pair, $answer];
        }
        if ($checked['status'] === ApiKeyVerdict::INVALID) {
            return [self::KEPT_REJECTED, [], $answer];
        }
        if ($storedKey !== '' && $this->verdict->lastDefinitiveStatus() === ApiKeyVerdict::OK) {
            return [self::KEPT_UNVERIFIED, [], $answer];
        }

        return [self::SAVED_UNVERIFIED, $pair, $answer];
    }
}
