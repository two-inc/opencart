<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\ErrorLog;
use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\OrderRefunds;
use Opencart\System\Library\Extension\Two\Core\PendingCheck;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\RefundRecords;
use Opencart\System\Library\Extension\Two\Core\RateLimiter;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;
use Opencart\System\Library\Extension\Two\Core\Surcharge;
use Opencart\System\Library\Extension\Two\Core\TaxCodes;
use Opencart\System\Library\Extension\Two\Opencart\DbOrderRecords;
use Opencart\System\Library\Extension\Two\Opencart\DbRateLimits;
use Opencart\System\Library\Extension\Two\Opencart\DbRefundRecords;
use Opencart\System\Library\Extension\Two\Opencart\DbSettingsStore;
use Opencart\System\Library\Extension\Two\Opencart\NoteText;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Admin adapter for the Two payment extension (Extensions > Payments > Two).
 *
 * Thin by design: it reads the shop (languages, order statuses, the posted
 * form), hands plain values to the core and turns the core's codes into
 * copy. The save rules live in SettingsSave, the key check in ApiKeyVerdict.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\Document $document
 * @property-read \Opencart\System\Library\Url $url
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Response $response
 * @property-read \Opencart\System\Library\Cart\User $user
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\Admin\Model\Setting\Setting $model_setting_setting
 * @property-read \Opencart\Admin\Model\Setting\Cron $model_setting_cron
 * @property-read \Opencart\Admin\Model\Setting\Event $model_setting_event
 * @property-read \Opencart\Admin\Model\Sale\Order $model_sale_order
 * @property-read \Opencart\Admin\Model\Setting\Store $model_setting_store
 * @property-read \Opencart\System\Engine\Event $event
 * @property-read \Opencart\Admin\Model\Localisation\Language $model_localisation_language
 * @property-read \Opencart\Admin\Model\Localisation\OrderStatus $model_localisation_order_status
 * @property-read \Opencart\Admin\Model\Localisation\Currency $model_localisation_currency
 * @property-read \Opencart\Admin\Model\Localisation\Country $model_localisation_country
 * @property-read \Opencart\Admin\Model\Localisation\TaxClass $model_localisation_tax_class
 * @property-read \Opencart\Admin\Model\Setting\Extension $model_setting_extension
 * @property-read \Opencart\Admin\Model\User\UserGroup $model_user_user_group
 */
class Two extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/two/payment/two';
    private const CODE = 'payment_two';

    /** OpenCart's own keys, saved as posted, and seeded next to the core defaults on install. */
    private const PLATFORM_DEFAULTS = ['status' => '0', 'sort_order' => '0'];

    public function index(): void
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));
        $this->document->addScript('../extension/two/admin/view/javascript/two_admin.js');
        $services = new Services($this->db, $this->log);
        $settings = $services->settings;
        $brand = Brand::default()->productName;
        $token = 'user_token=' . $this->session->data['user_token'];

        $data = [];
        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link(self::ROUTE, $token)],
        ];
        $data['save'] = $this->url->link(self::ROUTE . '.save', $token);
        $data['verify_key'] = $this->url->link(self::ROUTE . '.verifyKey', $token);
        $data['fee_rates'] = $this->url->link(self::ROUTE . '.feeRates', $token);
        $data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

        foreach (array_keys(self::PLATFORM_DEFAULTS) as $key) {
            $data[self::CODE . '_' . $key] = $this->config->get(self::CODE . '_' . $key);
        }

        // General: environment offered per Environment::options, the key never sent back to the browser.
        $stored = $settings->environment();
        $data['environments'] = array_map(fn (string $environment): array => [
            'value' => $environment,
            'text' => $this->language->get('text_environment_' . $environment),
        ], $services->environment->options($stored));
        $data['environment'] = $services->environment->resolve($stored);
        $data['environment_unhealthy'] = $services->environment->isHealthy($stored) ? '' : $this->language->get('error_environment_unhealthy');
        $data['api_key_saved'] = $settings->apiKey() !== '';
        $status = $services->verdict->status();
        $data['key_status'] = $status;
        $data['key_message'] = $this->keyMessage($status, null);

        // The merchant record, read here so a shop with no crontab still refreshes it.
        $record = $services->records->record();
        $fetchedAt = $services->records->fetchedAt();
        $data['merchant_id'] = is_scalar($record['id'] ?? null) ? (string) $record['id'] : '';
        $data['merchant_short_name'] = is_scalar($record['short_name'] ?? null) ? (string) $record['short_name'] : '';
        $data['record_fetched_at'] = $fetchedAt === null ? '' : gmdate('Y-m-d H:i', $fetchedAt) . 'Z';
        $data['vendor_name'] = $settings->vendorName();

        // Checkout fields.
        $this->load->model('localisation/language');
        $data['languages'] = $this->model_localisation_language->getLanguages();
        $data['titles'] = [];
        $data['subtitles'] = [];
        $data['product_messages'] = [];
        foreach ($data['languages'] as $language) {
            $data['subtitles'][$language['language_id']] = $settings->subtitle((int) $language['language_id']);
            $data['product_messages'][$language['language_id']] = $settings->productMessage((int) $language['language_id']);
            // Each language's default title from its own language file, over en-gb's as OpenCart does for a
            // language this extension does not ship, prefixed so this page's keys stay.
            $this->load->language(self::ROUTE, 'two_title', 'en-gb');
            $this->load->language(self::ROUTE, 'two_title', (string) $language['code']);
            $data['titles'][$language['language_id']] = $settings->title((int) $language['language_id'], $this->language->get('two_title_text_title_default'));
        }

        // Checkout fields: the placement switch, the optional fields and the minimum order.
        $data['company_search_in_address'] = $settings->companySearchInAddressArea();
        $data['address_lookup'] = $settings->addressLookupSwitch();
        // The tile's switches, in the reference plugin's order, then the optional fields; tooltips last, as its help names the fields above it.
        $data['tile_switches'] = [];
        foreach (['enable_order_intent' => $settings->orderIntentPreview(), 'show_about_link' => $settings->showAboutLink()] as $key => $on) {
            $data['tile_switches'][] = ['key' => $key, 'on' => $on, 'label' => sprintf($this->language->get('entry_' . $key), $brand), 'help' => sprintf($this->language->get('help_' . $key), $brand)];
        }
        $data['optional_fields'] = [];
        foreach (SettingsProvider::OPTIONAL_FIELDS as $field => $key) {
            $data['optional_fields'][] = ['key' => $key, 'on' => in_array($field, $settings->optionalFields(), true), 'label' => $this->language->get('entry_' . $key), 'help' => sprintf($this->language->get('help_' . $key), $brand)];
        }
        $data['optional_fields'][] = ['key' => 'display_tooltips', 'on' => $settings->displayTooltips(), 'label' => $this->language->get('entry_display_tooltips'), 'help' => $this->language->get('help_display_tooltips')];
        // The product page message and buy button (section 20): two switches and the wording, as in the reference plugin.
        $data['product_switches'] = [];
        foreach (['product_message_enabled' => $settings->productMessageEnabled(), 'product_button_enabled' => $settings->productButtonEnabled()] as $key => $on) {
            $data['product_switches'][] = ['key' => $key, 'on' => $on, 'label' => $this->language->get('entry_' . $key), 'help' => $this->language->get('help_' . $key)];
        }
        $data['min_order_amount'] = trim((string) $settings->store()->get('min_order_amount'));
        $data['min_order_basis'] = $settings->merchantMinimumOrderBasis() ?? '';
        $data['trusted_proxies'] = trim((string) $settings->store()->get('trusted_proxies'));
        $data['disable_rate_limit'] = $settings->rateLimitDisabled();

        // Diagnostics, in the reference plugin's order.
        $data['debug_mode'] = $settings->debugLogging();
        $data['disable_ssl_verify'] = !$settings->verifyTls();
        $data['custom_headers'] = $settings->customHeaderRows();
        $data['refresh_profile'] = $this->url->link(self::ROUTE . '.refreshProfile', $token);
        $data['error_log'] = $this->url->link(self::ROUTE . '.errorLog', $token);
        // The refresh's own URL, guarded by a random token (never the API key). OpenCart's cron runner reaches the same refresh without it.
        $data['cron_url'] = HTTP_CATALOG . 'index.php?route=' . Services::CRON_ACTION . '&token=' . rawurlencode($services->records->cronToken());
        $ranAt = (int) $settings->store()->get(RecordProvider::CRON_LAST_RUN_KEY);
        $data['cron_last_run'] = $ranAt > 0 ? sprintf($this->language->get('text_cron_last_run'), gmdate('Y-m-d H:i', $ranAt) . 'Z') : $this->language->get('text_cron_never');
        // Orders the buyer verified but never returned from are placed only by the pending check (TWO-26300): warn when they wait and the URL has stopped running.
        $waiting = DbOrderRecords::waitingCount($this->db, (int) $this->config->get('config_void_status_id'));
        $data['pending_stale'] = PendingCheck::scheduleStale($waiting, (int) $settings->store()->get(PendingCheck::LAST_RUN_KEY), time()) ? sprintf($this->language->get('text_pending_stale'), $waiting) : '';
        $shopCurrency = (string) $this->config->get('config_currency');
        $data['entry_min_order_amount'] = sprintf($this->language->get('entry_min_order_amount'), $shopCurrency);

        // The health row (section 18): why Two is not offered, or the constraints that may still withhold it.
        $data['health'] = $this->healthRow($services, $shopCurrency);

        // Payment terms: the record's terms, the merchant's narrowing, and the surcharge.
        $terms = $services->paymentTerms();
        $surcharge = $services->surcharge();
        $data['record_terms'] = $terms->recordTerms;
        $data['ticked_terms'] = $settings->tickedPaymentTerms();
        $data['payment_term_type'] = $terms->type;
        $data['default_payment_term'] = $settings->defaultPaymentTerm();
        $data['surcharge_types'] = [];
        foreach (Surcharge::TYPES as $type) {
            $data['surcharge_types'][$type] = $this->language->get('text_surcharge_' . $type);
        }
        $data['rounding_bases'] = ['none' => $this->language->get('text_rounding_none')];
        foreach (array_keys(Surcharge::ROUNDING) as $basis) {
            $data['rounding_bases'][$basis] = $this->language->get('text_rounding_' . $basis);
        }
        $data['rounding_steps'] = Surcharge::ROUNDING_STEPS;
        $store = $settings->store();
        $data['surcharge'] = [
            'type' => $surcharge->type,
            'differential' => $surcharge->differential,
            'line_description' => $surcharge->lineDescription,
            'rounding_basis' => trim((string) $store->get('surcharge_rounding_basis')),
            'rounding_step' => trim((string) $store->get('surcharge_rounding_step')),
            'tax_class_id' => trim((string) $store->get('surcharge_tax_class_id')),
        ];
        $grid = json_decode((string) $store->get('surcharge_grid'), true);
        $data['surcharge_grid'] = [];
        foreach ($terms->recordTerms as $days) {
            $row = is_array($grid[$days] ?? null) ? $grid[$days] : [];
            $data['surcharge_grid'][] = ['days' => $days] + array_map(static fn (string $column): string => is_scalar($row[$column] ?? null) ? (string) $row[$column] : '', array_combine(Surcharge::COLUMNS, Surcharge::COLUMNS));
        }
        $data['column_labels'] = ['percentage' => $this->language->get('column_percentage'), 'fixed' => $this->language->get('column_fixed'), 'cap' => $this->language->get('column_cap')];
        $this->load->model('localisation/tax_class');
        $data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
        $data['help_payment_terms'] = sprintf($this->language->get('help_payment_terms'), $brand);
        $data['help_surcharge_type'] = sprintf($this->language->get('help_surcharge_type'), $brand);
        $data['help_surcharge_grid'] = sprintf($this->language->get('help_surcharge_grid'), $shopCurrency, $brand);
        // The merchant fee beside each term: two_admin.js asks feeRates once the page is up, and words the outcome.
        $feeText = [];
        foreach (['stale', 'stale_dated', 'unavailable', 'rejected', 'rejected_bare', 'stale_rejected', 'stale_rejected_bare', 'no_api_key', 'no_figure'] as $key) {
            $feeText[$key] = $this->language->get('text_fees_' . $key);
        }
        $data['fee_text'] = (string) json_encode($feeText, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Order management.
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['fulfil_status_ids'] = $settings->fulfilStatusIds();
        // Status names come from OpenCart's own table, stored escaped as OpenCart stores every admin value.
        $active = array_column(array_filter($data['order_statuses'], static fn (array $status): bool => in_array((int) $status['order_status_id'], $data['fulfil_status_ids'], true)), 'name');
        $data['fulfil_active'] = implode(', ', $active);
        $data['cancel_status_id'] = $settings->cancelStatusId();
        $data['refund_status_id'] = $settings->refundStatusId();
        // A shop that predates the setting shows the status its orders are placed at today.
        $data['confirmed_status_id'] = $settings->placedStatusId((int) $this->config->get('config_order_status_id'));
        $data['enable_tax_subtotals'] = $settings->taxSubtotals();
        $data['tax_codes'] = $this->taxCodeView($services, $brand);
        $data['buyer_vat_fields'] = $this->buyerVatFields();
        $data['buyer_vat_field'] = (string) $settings->store()->get(TaxCodes::VAT_FIELD_KEY);
        $data['text_tax_codes_intro'] = sprintf($this->language->get('text_tax_codes_intro'), $brand);
        $data['help_buyer_vat_field'] = sprintf($this->language->get('help_buyer_vat_field'), $brand);

        foreach (['help_debug_mode', 'help_refresh_profile', 'error_profile_refresh', 'help_custom_headers', 'help_company_search_in_address', 'help_address_lookup', 'help_api_key', 'help_vendor_name', 'help_fulfil', 'help_cancel', 'help_refund', 'help_confirmed', 'entry_fulfil', 'entry_cancel', 'entry_refund', 'entry_confirmed', 'text_status_mapping_intro', 'text_default_confirmed', 'text_default_fulfil', 'text_default_cancel', 'text_default_refund'] as $key) {
            $data[$key] = sprintf($this->language->get($key), $brand);
        }
        $data['help_environment'] = $this->language->get('help_environment');
        $data['brand_name'] = $brand;
        $data['version'] = Services::pluginVersion();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    /**
     * Saves the form selectively: OpenCart's own keys as posted, the core's
     * through SettingsSave, which keeps a refused field as stored and names
     * it. Only posted fields are written, so a key the form does not carry
     * is never wiped.
     */
    public function save(): void
    {
        $this->load->language(self::ROUTE);
        $json = [];

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $json['error']['warning'] = $this->language->get('error_permission');
            $this->json($json);

            return;
        }

        $post = $this->request->post;
        if (SettingsSave::cutShort($this->submitted($post))) {
            // Before anything is written, the platform settings included.
            $json['error']['warning'] = $this->language->get('error_form_truncated');
            $this->json($json);

            return;
        }
        $platform = [];
        foreach (array_keys(self::PLATFORM_DEFAULTS) as $key) {
            if (isset($post[self::CODE . '_' . $key]) && is_scalar($post[self::CODE . '_' . $key])) {
                $platform[$key] = (string) $post[self::CODE . '_' . $key];
            }
        }
        if ($platform !== []) {
            // Upserts: a key with no row yet (added after this shop installed) is inserted, not dropped.
            (new DbSettingsStore($this->db))->setMany($platform);
        }

        $services = new Services($this->db, $this->log);
        $this->load->model('localisation/tax_class');
        $taxClassIds = array_map(static fn (array $row): int => (int) $row['tax_class_id'], $this->model_localisation_tax_class->getTaxClasses());
        $submitted = $this->submitted($post);
        $taxCodes = ['vat_fields' => array_column($this->buyerVatFields(), 'id')];
        if (isset($submitted['tax_code_map'])) {
            // The rows are saved against the section as it would render now: its rows and the codes listed at 0%.
            $section = $this->taxCodeSection($services);
            $taxCodes += [
                'rows' => $section['state']['view'] === TaxCodes::VIEW_ROWS ? $section['rows'] : null,
                'zero' => array_column(TaxCodes::zeroRated($section['state']['codes']), 'code'),
            ];
        }
        $result = $services->save->save($submitted, $this->orderStatusIds(), (int) $this->config->get('config_void_status_id'), $taxClassIds, $taxCodes);

        foreach ($result['errors'] as $field => $code) {
            $json['error'][$field] = $field === 'surcharge_grid' ? $this->gridMessage($code) : $this->language->get('error_' . $code);
        }
        if (isset($result['errors']['custom_headers'])) {
            $json['error']['custom_headers'] = $this->headersMessage($result['errors']['custom_headers'], $this->submitted($post)['custom_headers'] ?? []);
        }
        if (isset($result['errors']['trusted_proxies'])) {
            // Name the first entry that is not an IP or a range, as the reference plugin does.
            $bad = array_values(array_filter(RateLimiter::parseProxies(self::raw($post[self::CODE . '_trusted_proxies'] ?? '')), static fn (string $entry): bool => !RateLimiter::isValidProxyEntry($entry)));
            $json['error']['trusted_proxies'] = htmlspecialchars(sprintf($this->language->get('error_proxy_invalid'), $bad[0] ?? ''), ENT_QUOTES, 'UTF-8');
        }
        $key = $this->credentialsMessage($result);
        if ($key !== null && $key['refused']) {
            $json['error'][$key['field']] = $key['text'];
        }
        if (isset($json['error'])) {
            $json['error']['warning'] = $this->language->get('error_partial');
        }
        $json['success'] = $this->language->get(isset($json['error']) ? 'text_success_partial' : 'text_success')
            . ($key !== null && !$key['refused'] && $key['text'] !== '' ? ' ' . $key['text'] : '');

        $this->json($json);
    }

    /**
     * The live check behind the API key field: the typed key (or, when the
     * field is empty, the stored one) against the chosen environment. Asks
     * Two and caches nothing.
     */
    public function verifyKey(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->json(['status' => '', 'ok' => false, 'definitive' => false, 'message' => $this->language->get('error_permission')]);

            return;
        }
        $services = new Services($this->db, $this->log);
        $typed = trim(self::raw($this->request->post['api_key'] ?? null));
        $environment = self::raw($this->request->post['environment'] ?? null);
        $key = $typed === '' ? $services->settings->apiKey() : $typed;

        if (!$services->environment->acceptsSave($environment, $services->settings->environment())) {
            $this->json(['status' => '', 'ok' => false, 'definitive' => false, 'message' => $this->language->get('error_kept_environment')]);

            return;
        }
        if ($key !== '' && !SettingsSave::isWellFormedKey($key)) {
            $this->json(['status' => '', 'ok' => false, 'definitive' => true, 'message' => $this->language->get('error_kept_malformed')]);

            return;
        }
        $checked = $services->verdict->check($key, $environment);
        $this->json([
            'status' => $checked['status'],
            'ok' => $checked['status'] === ApiKeyVerdict::OK,
            'definitive' => ApiKeyVerdict::isDefinitive($checked['status']),
            'message' => $this->keyMessage($checked['status'], $checked['code']),
            'merchant_id' => is_scalar($checked['merchant']['id'] ?? null) ? (string) $checked['merchant']['id'] : '',
            'merchant_short_name' => is_scalar($checked['merchant']['short_name'] ?? null) ? (string) $checked['merchant']['short_name'] : '',
        ]);
    }

    /**
     * The fee Two charges the merchant for each term on the Payment terms tab,
     * asked by two_admin.js once the page is up so a slow Two never holds the
     * page. Posts `terms`, a JSON list of day counts. The buyer country is the
     * shop's own, since a settings page has no buyer.
     */
    public function feeRates(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('access', self::ROUTE)) {
            $this->json(['success' => false, 'error' => 'permission', 'message' => $this->language->get('error_permission')]);

            return;
        }
        $decoded = json_decode(self::raw($this->request->post['terms'] ?? ''), true);
        $this->load->model('localisation/country');
        $country = $this->model_localisation_country->getCountry((int) $this->config->get('config_country_id'));
        // The shop's own country; NL only when it has none, the reference plugin's own fallback.
        $iso = is_string($country['iso_code_2'] ?? null) && trim($country['iso_code_2']) !== '' ? $country['iso_code_2'] : 'NL';
        $result = (new Services($this->db, $this->log))->feeRates->fetch(is_array($decoded) ? $decoded : [], $iso);
        if (!empty($result['stale']) && ($result['fetched_at'] ?? 0) > 0) {
            $result['fetched_at_display'] = gmdate('Y-m-d H:i', (int) $result['fetched_at']) . 'Z';
        }
        $this->json($result);
    }

    /**
     * The Diagnostics tab's "Refresh merchant profile" button: refetches the
     * merchant record now. A failed refetch keeps the cached record. POST
     * only, with the admin's user_token (OpenCart's login check) and the
     * modify permission.
     */
    public function refreshProfile(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', self::ROUTE) || ($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->json(['success' => false, 'message' => $this->language->get('error_permission')]);

            return;
        }
        $refreshed = (new Services($this->db, $this->log))->records->refresh();
        $this->json([
            'success' => $refreshed,
            'message' => $refreshed ? $this->language->get('text_profile_refreshed') : sprintf($this->language->get('error_profile_refresh'), Brand::default()->productName),
        ]);
    }

    /**
     * The Diagnostics tab's error log view: the extension's last 100 lines of
     * the shop's error log, the API key and every custom header value
     * redacted, each line HTML-escaped (ErrorLog::view). Needs the modify
     * permission, as the log may hold buyer data.
     */
    public function errorLog(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(htmlspecialchars($this->language->get('error_permission'), ENT_QUOTES, 'UTF-8'));

            return;
        }
        $settings = (new Services($this->db, $this->log))->settings;
        $secrets = array_merge([$settings->apiKey()], array_map(static fn (array $row): string => $row['value'], $settings->customHeaderRows()));
        $brand = Brand::default()->productName;
        $token = 'user_token=' . $this->session->data['user_token'];
        $this->document->setTitle(sprintf($this->language->get('heading_error_log'), $brand));

        $data = [
            'lines' => ErrorLog::view($this->errorLogTail(), $secrets),
            'heading_error_log' => sprintf($this->language->get('heading_error_log'), $brand),
            'text_error_log_intro' => sprintf($this->language->get('text_error_log_intro'), ErrorLog::LIMIT, $brand),
            'back' => $this->url->link(self::ROUTE, $token),
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
        ];
        $this->response->setOutput($this->load->view(self::ROUTE . '_error_log', $data));
    }

    /** The end of the shop's error log: at most ErrorLog::TAIL_BYTES, empty when there is none. */
    private function errorLogTail(): string
    {
        $path = DIR_LOGS . basename((string) $this->config->get('config_error_filename'));
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        $size = (int) filesize($path);
        if ($size > ErrorLog::TAIL_BYTES) {
            fseek($handle, $size - ErrorLog::TAIL_BYTES);
        }
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /**
     * Called by Extensions > Payments > Install. Seeds the fresh-install
     * defaults (the status maps by the shop's status names) and registers
     * the daily merchant-record refresh and the hourly pending check with
     * OpenCart's cron.
     */
    public function install(): void
    {
        $this->load->model('localisation/order_status');
        $byName = [];
        foreach ($this->model_localisation_order_status->getOrderStatuses() as $status) {
            $byName[$status['name']] ??= (int) $status['order_status_id'];
        }
        $statuses = [];
        foreach (SettingsProvider::DEFAULT_STATUS_NAMES as $key => $name) {
            if (isset($byName[$name])) {
                $statuses[$key] = $key === 'fulfil_status_ids' ? (string) json_encode([$byName[$name]]) : (string) $byName[$name];
            }
        }

        $defaults = [];
        foreach ($statuses + self::PLATFORM_DEFAULTS + SettingsProvider::DEFAULTS as $key => $value) {
            $defaults[self::CODE . '_' . $key] = $value;
        }
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting(self::CODE, $defaults);

        $this->load->model('setting/cron');
        $this->model_setting_cron->deleteCronByCode(Services::CRON_CODE);
        $this->model_setting_cron->addCron(Services::CRON_CODE, Services::CRON_DESCRIPTION, Services::CRON_CYCLE, Services::CRON_ACTION, true);
        $this->model_setting_cron->deleteCronByCode(Services::PENDING_CRON_CODE);
        $this->model_setting_cron->addCron(Services::PENDING_CRON_CODE, Services::PENDING_CRON_DESCRIPTION, Services::PENDING_CRON_CYCLE, Services::CRON_ACTION, true);

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(Services::EVENT_CODE);
        $this->model_setting_event->addEvent(['code' => Services::EVENT_CODE, 'description' => Services::EVENT_DESCRIPTION, 'trigger' => Services::EVENT_TRIGGER, 'action' => Services::EVENT_ACTION, 'status' => true, 'sort_order' => 0]);
        $this->model_setting_event->deleteEventByCode(Services::CHECKOUT_EVENT_CODE);
        $this->model_setting_event->addEvent(['code' => Services::CHECKOUT_EVENT_CODE, 'description' => Services::CHECKOUT_EVENT_DESCRIPTION, 'trigger' => Services::CHECKOUT_EVENT_TRIGGER, 'action' => Services::CHECKOUT_EVENT_ACTION, 'status' => true, 'sort_order' => 0]);
        foreach (Services::ADDRESS_EVENTS + Services::PRODUCT_EVENTS as $code => [$trigger, $action, $description]) {
            $this->model_setting_event->deleteEventByCode($code);
            $this->model_setting_event->addEvent(['code' => $code, 'description' => $description, 'trigger' => $trigger, 'action' => $action, 'status' => true, 'sort_order' => 0]);
        }

        // The order-scoped table. Kept on uninstall: it holds the Two order ids of placed orders.
        DbOrderRecords::install(function (string $sql): array {
            $result = $this->db->query($sql);

            return is_object($result) ? $result->rows : [];
        }, DB_PREFIX);
        // The checkout relays' per-caller rate-limit counts.
        $this->db->query(DbRateLimits::createTableSql(DB_PREFIX));
        DbRefundRecords::install(function (string $sql): array {
            $result = $this->db->query($sql);

            return is_object($result) ? $result->rows : [];
        }, DB_PREFIX);

        // The payment terms fee total, which charges the surcharge: installed and enabled with the method.
        $this->load->model('setting/extension');
        if (!$this->model_setting_extension->getExtensionByCode('total', 'two_fee')) {
            $this->model_setting_extension->install('total', 'two', 'two_fee');
            $this->load->model('user/user_group');
            foreach (['access', 'modify'] as $permission) {
                $this->model_user_user_group->addPermission($this->user->getGroupId(), $permission, 'extension/two/total/two_fee');
            }
            // Before the Taxes total, which lists the taxes added so far: the fee's tax is charged only then.
            $taxSort = (int) $this->config->get('total_tax_sort_order');
            $sort = $taxSort > (int) Services::FEE_TOTAL_SORT_ORDER || $taxSort <= 0 ? (int) Services::FEE_TOTAL_SORT_ORDER : $taxSort - 1;
            $this->model_setting_setting->editSetting('total_two_fee', ['total_two_fee_status' => '1', 'total_two_fee_sort_order' => (string) $sort]);
            if ($taxSort <= 0) {
                $this->log->write('Two: the Taxes order total has sort order ' . $taxSort . ', so the payment terms fee total (sort order ' . $sort . ') cannot sort before it and its tax would not be charged. Give Taxes a higher sort order in Extensions > Order Totals.');
            }
        }
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(self::CODE);

        $this->load->model('setting/cron');
        $this->model_setting_cron->deleteCronByCode(Services::CRON_CODE);
        $this->model_setting_cron->deleteCronByCode(Services::PENDING_CRON_CODE);

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(Services::EVENT_CODE);
        $this->model_setting_event->deleteEventByCode(Services::CHECKOUT_EVENT_CODE);
        foreach (array_keys(Services::ADDRESS_EVENTS + Services::PRODUCT_EVENTS) as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }

        $this->load->model('setting/extension');
        $this->model_setting_extension->uninstall('total', 'two_fee');
        $this->model_setting_setting->deleteSetting('total_two_fee');
    }

    /**
     * The Two tab on the admin order page (Sales > Orders > the order),
     * which OpenCart shows for orders paid with this extension. OpenCart
     * renders it inline with the page, so it is only a shell: its content
     * (the Two order, the downloads, the refund form and the refunds sent)
     * reads Two, and is loaded from `two.panel` when the tab is opened, so
     * a slow or unreachable Two never holds up the order page.
     */
    public function order(): string
    {
        $this->load->language(self::ROUTE);

        return $this->load->view(self::ROUTE . '_order_tab', [
            'panel_action' => $this->orderLink('panel', (int) ($this->request->get['order_id'] ?? 0)),
            'text_panel_loading' => $this->language->get('text_panel_loading'),
            'error_panel' => $this->language->get('error_panel'),
        ]);
    }

    /**
     * The Two tab's content, loaded when the tab is opened and again after
     * a refund or retry. Same guard as the downloads.
     */
    public function panel(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('access', 'sale/order') || !$this->user->hasPermission('access', self::ROUTE)) {
            $this->response->setOutput('<p>' . htmlspecialchars($this->language->get('error_invoice_permission'), ENT_QUOTES, 'UTF-8') . '</p>');

            return;
        }
        $this->response->setOutput($this->panelHtml());
    }

    private function panelHtml(): string
    {
        $this->load->language(self::ROUTE);
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        // The order's own store: settings, the API key among them, are kept per store.
        $order = $this->twoOrder($orderId);
        $services = new Services($this->db, $this->log, (int) ($order['store_id'] ?? 0));
        $record = $services->orders->get($orderId) ?? [];
        ['two' => $two, 'state' => $state, 'refundable' => $refundable] = $services->orderRefunds($this->event)->panel($orderId);
        $link = fn (string $action): string => $this->orderLink($action, $orderId);

        $remaining = $two === null ? null : OrderRefunds::remainingCents($two);
        $refunds = $services->refunds->forOrder($orderId);
        $earlier = OrderRefunds::refundedPerLine($refunds);
        $lines = [];
        foreach ($two === null ? [] : OrderRefunds::refundableLines($two) as $line) {
            $lines[] = $line + ['left' => max(0, $line['quantity'] - ($earlier[$line['id']]['quantity'] ?? 0))];
        }
        $text = [];
        foreach (['text_two_order', 'text_two_state', 'text_no_two_order', 'button_invoice', 'help_invoice', 'button_credit_note', 'help_credit_note', 'text_refund', 'text_refunded', 'text_remaining',
            'text_refund_help', 'text_refund_unavailable', 'text_refund_unread', 'text_refunds', 'column_line', 'column_quantity', 'column_net', 'column_tax', 'column_rate', 'column_refund_quantity',
            'column_date', 'column_amount', 'column_state', 'entry_refund_net', 'entry_refund_tax', 'entry_refund_rate', 'entry_refund_reason', 'button_refund', 'button_retry', 'text_refund_blocked', 'column_left', 'error_ajax',
            'button_check_status', 'help_check_status'] as $key) {
            $text[$key] = $this->language->get($key);
        }
        $unresolved = false;
        foreach ($refunds as &$refund) {
            $refund['state_text'] = $this->language->get('text_refund_state_' . $refund['state']);
            $refund['reason'] = NoteText::stored((string) $refund['reason'], $this->load, $this->language, NoteText::adminCode($this->config));
            $refund['retry'] = in_array($refund['state'], [RefundRecords::UNKNOWN, RefundRecords::PENDING], true);
            $unresolved = $unresolved || $refund['retry'];
        }
        unset($refund);

        return $this->load->view(self::ROUTE . '_order', $text + [
            'two_order_id' => $record['two_order_id'] ?? '',
            'two_order_state' => $state,
            'invoice' => in_array($state, OrderLifecycle::FULFILLED_STATES, true) ? $link('invoice') : '',
            'credit_note' => $state === 'REFUNDED' || in_array(RefundRecords::DONE, array_column($refunds, 'state'), true) ? $link('creditNote') : '',
            'refundable' => $refundable,
            'read' => $two !== null,
            'refund_action' => $link('refund'),
            'retry_action' => $link('refundRetry'),
            'unresolved' => $unresolved,
            'currency' => (string) ($two['currency'] ?? ''),
            'refunded' => $two === null ? '' : Postprocessing::money(OrderRefunds::refundedCents($two)),
            'remaining' => $remaining === null ? '' : Postprocessing::money($remaining),
            'lines' => $lines,
            'panel_action' => $link('panel'),
            'rates' => $two === null ? [] : OrderRefunds::rates($two),
            'refunds' => $refunds,
            // Waiting for the buyer's return: the merchant may ask Two now (TWO-26300).
            'check_action' => $order !== null && $this->unplaced($order) ? $link('checkStatus') : '',
        ]);
    }

    /**
     * "Check status with Two" on an unplaced order: asks Two at once, at any
     * age, and places the order if Two holds it verified or confirmed. The
     * check runs in the order's store front (a store instance), so placing
     * fires the storefront's order events and the order mail, as the buyer's
     * return would; the history note is written there. Same rights as a
     * refund.
     */
    public function checkStatus(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', 'sale/order') || !$this->user->hasPermission('modify', self::ROUTE)) {
            $this->json(['error' => $this->language->get('error_refund_permission')]);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->json(['error' => $this->language->get('error_invoice_no_reference')]);

            return;
        }
        $this->load->model('setting/store');
        $store = $this->model_setting_store->createStoreInstance((int) $order['store_id'], (string) $this->config->get('config_language'));
        $loader = $store->get('load');
        $result = $loader instanceof \Opencart\System\Engine\Loader ? $loader->controller(Services::CRON_ACTION . '.check', $orderId, $this->user->getUserName()) : null;
        if (!is_array($result) || !($result['message'] ?? null) instanceof Message) {
            $this->json(['error' => $this->language->get('error_ajax')]);

            return;
        }
        // The answer in the admin user's language; the history note was written in the shop's.
        $text = NoteText::render($result['message'], $this->load, $this->language, NoteText::adminCode($this->config));
        $this->json($result['outcome'] === OrderFlow::CONFIRMED ? ['success' => $text] : ['error' => $text]);
    }

    /**
     * Not yet placed: status 0, or the shop's void status.
     *
     * @param array<string, mixed> $order
     */
    private function unplaced(array $order): bool
    {
        $status = (int) $order['order_status_id'];

        return $status === 0 || $status === (int) $this->config->get('config_void_status_id');
    }

    private function orderLink(string $action, int $orderId): string
    {
        return $this->url->link(self::ROUTE . '.' . $action, 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $orderId, true);
    }

    /**
     * The partial refund form's action: refunds the picked lines and the
     * amount line at Two and notes the outcome in the order history.
     * Needs the right to modify orders and this extension.
     */
    public function refund(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', 'sale/order') || !$this->user->hasPermission('modify', self::ROUTE)) {
            $this->json(['error' => $this->language->get('error_refund_permission')]);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->json(['error' => $this->language->get('error_invoice_no_reference')]);

            return;
        }
        $post = $this->request->post;
        $lines = [];
        foreach (is_array($post['two_refund_line'] ?? null) ? $post['two_refund_line'] : [] as $id => $quantity) {
            if (is_scalar($quantity) && trim((string) $quantity) !== '') {
                $lines[(string) $id] = ctype_digit(trim((string) $quantity)) ? (int) $quantity : -1;
            }
        }
        if (in_array(-1, $lines, true)) {
            $this->json(['error' => $this->language->get('error_refund_quantity')]);

            return;
        }
        $request = ['lines' => $lines];
        foreach (['net', 'tax', 'rate', 'reason'] as $field) {
            $request[$field] = self::raw($post['two_refund_' . $field] ?? '');
        }

        $result = (new Services($this->db, $this->log, (int) $order['store_id']))->orderRefunds($this->catalogEvent((int) $order['store_id']))->partial($orderId, $request);
        $this->historyNote($order, $result['message']);
        // The answer in the admin user's language; the history note in the shop's.
        $text = NoteText::render($result['message'], $this->load, $this->language, NoteText::adminCode($this->config));
        $this->json($result['outcome'] === OrderLifecycle::DONE ? ['success' => $text] : ['error' => $text]);
    }

    /**
     * Sends a refund whose outcome is unknown again, under its own key, and
     * notes the outcome in the order history. Same rights as a refund.
     */
    public function refundRetry(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', 'sale/order') || !$this->user->hasPermission('modify', self::ROUTE)) {
            $this->json(['error' => $this->language->get('error_refund_permission')]);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->json(['error' => $this->language->get('error_invoice_no_reference')]);

            return;
        }
        $refundId = (int) ($this->request->post['two_refund_id'] ?? 0);
        $result = (new Services($this->db, $this->log, (int) $order['store_id']))->orderRefunds($this->catalogEvent((int) $order['store_id']))->retry($orderId, $refundId);
        $this->historyNote($order, $result['message']);
        // The answer in the admin user's language; the history note in the shop's.
        $text = NoteText::render($result['message'], $this->load, $this->language, NoteText::adminCode($this->config));
        $this->json($result['outcome'] === OrderLifecycle::DONE ? ['success' => $text] : ['error' => $text]);
    }

    /**
     * Streams the order's latest Two credit note, behind the same guard as
     * the invoice.
     */
    public function creditNote(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('access', 'sale/order') || !$this->user->hasPermission('access', self::ROUTE)) {
            $this->invoiceNotice($this->language->get('error_invoice_permission'), 403);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->invoiceNotice($this->language->get('error_invoice_no_reference'), 404);

            return;
        }
        $result = (new Services($this->db, $this->log, (int) $order['store_id']))->orderRefunds($this->event)->creditNote($orderId);
        if (isset($result['pdf'])) {
            $this->pdf($result['pdf'], 'credit-note-' . $orderId . '.pdf');

            return;
        }
        $this->invoiceNotice($this->language->get($result['notice'] === OrderRefunds::CREDIT_NOTE_NO_REFERENCE ? 'error_invoice_no_reference' : 'error_credit_note'), 200);
    }

    /**
     * The event registry of the order's store front, as OpenCart's own admin
     * order edits run it (a store instance). The admin registers only admin/
     * and system/ triggers, so a write sent from here fires the order
     * postprocessing event there, where the merchant's subscribers on
     * `catalog/extension/two/order_postprocessing` are registered.
     */
    private function catalogEvent(int $storeId): \Opencart\System\Engine\Event
    {
        $this->load->model('setting/store');
        $store = $this->model_setting_store->createStoreInstance($storeId, (string) $this->config->get('config_language'));
        $config = $store->get('config');
        $event = $store->get('event');
        if (!$config instanceof \Opencart\System\Engine\Config || !$event instanceof \Opencart\System\Engine\Event) {
            throw new \RuntimeException('The store instance has no config or event registry.');
        }
        // The instance takes its store from the admin's host name (so store 0);
        // set the order's store, as OpenCart's own order edit does.
        $config->set('config_store_id', $storeId);

        return $event;
    }

    /**
     * The shop order when it was paid with this extension, else null.
     *
     * @return array<string, mixed>|null
     */
    private function twoOrder(int $orderId): ?array
    {
        $this->load->model('sale/order');
        $order = $orderId > 0 ? $this->model_sale_order->getOrder($orderId) : [];

        return $order && (string) ($order['payment_method']['code'] ?? '') === 'two.two' ? $order : null;
    }

    /**
     * A line in the order history at the order's current status, not
     * notified, in the language of the order's store. Written directly: the
     * admin has no order history model, and the status does not change, so
     * no order event is due.
     *
     * @param array<string, mixed> $order
     */
    private function historyNote(array $order, Message $message): void
    {
        $this->load->model('setting/setting');
        $code = $this->model_setting_setting->getValue('config_language_catalog', (int) $order['store_id']) ?: NoteText::shopCode($this->config);
        $text = NoteText::render($message, $this->load, $this->language, $code);
        $this->db->query('INSERT INTO `' . DB_PREFIX . "order_history` SET `order_id` = '" . (int) $order['order_id'] . "', `order_status_id` = '" . (int) $order['order_status_id'] . "', `notify` = '0', `comment` = '"
            . $this->db->escape('Two: ' . $text) . "', `date_added` = NOW()");
    }

    private function pdf(string $body, string $filename): void
    {
        $this->response->addHeader('Content-Type: application/pdf');
        $this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
        $this->response->addHeader('Cache-Control: private, no-store');
        $this->response->setOutput($body);
    }

    /**
     * Streams the order's Two invoice. Admin only: the route sits behind the
     * admin login and its user_token, and the user must also be allowed to
     * see orders and this extension.
     */
    public function invoice(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('access', 'sale/order') || !$this->user->hasPermission('access', self::ROUTE)) {
            $this->invoiceNotice($this->language->get('error_invoice_permission'), 403);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->invoiceNotice($this->language->get('error_invoice_no_reference'), 404);

            return;
        }

        $result = (new Services($this->db, $this->log, (int) $order['store_id']))->orderLifecycle($this->event)->invoice($orderId);
        if (isset($result['pdf'])) {
            $this->pdf($result['pdf'], 'invoice-' . $orderId . '.pdf');

            return;
        }
        $message = match ($result['notice']) {
            OrderLifecycle::INVOICE_NOT_READY => $this->language->get('error_invoice_not_ready'),
            OrderLifecycle::INVOICE_UNAVAILABLE_STATE => sprintf($this->language->get('error_invoice_state'), $result['state']),
            OrderLifecycle::INVOICE_NO_REFERENCE => $this->language->get('error_invoice_no_reference'),
            default => $this->language->get('error_invoice'),
        };
        $this->invoiceNotice($message, 200);
    }

    /** Why there is no invoice, as a short page the download link opened. */
    private function invoiceNotice(string $message, int $status): void
    {
        if ($status !== 200) {
            $this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $status . ($status === 403 ? ' Forbidden' : ' Not Found'));
        }
        $this->response->addHeader('Content-Type: text/html; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($this->language->get('heading_title'), ENT_QUOTES, 'UTF-8')
            . '</title></head><body><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>');
    }

    /**
     * The posted core fields, as plain values. A field the form did not post
     * is left out, so SettingsSave leaves it alone. OpenCart HTML-escapes
     * every posted value; the core holds values as typed, so they are
     * decoded here and escaped again when rendered.
     *
     * @param array<string, mixed> $post
     * @return array{api_key?: string, environment?: string, vendor_name?: string, title?: array<int, string>, subtitle?: array<int, string>, product_message?: array<int, string>, fulfil_status_ids?: list<string>, confirmed_status_id?: string, enable_order_intent?: string, show_about_link?: string, display_tooltips?: string, enable_tax_subtotals?: string, cancel_status_id?: string, refund_status_id?: string, company_search_in_address?: string, address_lookup?: string, enable_invoice_email?: string, enable_po_number?: string, enable_project?: string, enable_department?: string, min_order_amount?: string, min_order_basis?: string, trusted_proxies?: string, disable_rate_limit?: string, product_message_enabled?: string, product_button_enabled?: string, debug_mode?: string, disable_ssl_verify?: string, custom_headers?: list<array{name: string, value: string, send_from_browser: bool}>, tax_code_map?: array<string, string>, form_end?: string, tax_codes_clear?: string, buyer_vat_field?: string}
     */
    private function submitted(array $post): array
    {
        $field = static fn (string $key): mixed => $post[self::CODE . '_' . $key] ?? null;
        $submitted = [];
        foreach (array_merge(['api_key', 'environment', 'vendor_name', 'confirmed_status_id', 'cancel_status_id', 'refund_status_id', 'min_order_amount', 'min_order_basis', 'trusted_proxies', 'payment_term_type', 'default_payment_term', 'surcharge_type', 'surcharge_differential', 'surcharge_line_description', 'surcharge_rounding_basis', 'surcharge_rounding_step', 'surcharge_tax_class_id'], SettingsSave::SWITCHES) as $key) {
            if (is_scalar($field($key))) {
                $submitted[$key] = self::raw($field($key));
            }
        }
        foreach (['buyer_vat_field', 'tax_codes_clear'] as $key) {
            if (is_scalar($field($key))) {
                $submitted[$key] = self::raw($field($key));
            }
        }
        // Always given: a post without the form's last field was cut short (SettingsSave::cutShort).
        $submitted['form_end'] = is_scalar($field('form_end')) ? self::raw($field('form_end')) : '';
        if (is_array($field('tax_code_map'))) {
            $map = [];
            foreach ($field('tax_code_map') as $row => $code) {
                $map[(string) $row] = is_scalar($code) ? self::raw($code) : '';
            }
            $submitted['tax_code_map'] = $map;
        }
        if (is_array($field('title'))) {
            // One title per shop language: a language the form left out reads as blank, which is refused.
            $this->load->model('localisation/language');
            $titles = [];
            foreach ($this->model_localisation_language->getLanguages() as $language) {
                $title = $field('title')[$language['language_id']] ?? '';
                $titles[(int) $language['language_id']] = self::raw($title);
            }
            $submitted['title'] = $titles;
        }
        if (is_array($field('subtitle'))) {
            $this->load->model('localisation/language');
            $subtitles = [];
            foreach ($this->model_localisation_language->getLanguages() as $language) {
                $subtitles[(int) $language['language_id']] = self::raw($field('subtitle')[$language['language_id']] ?? '');
            }
            $submitted['subtitle'] = $subtitles;
        }
        if (is_array($field('product_message'))) {
            $this->load->model('localisation/language');
            $messages = [];
            foreach ($this->model_localisation_language->getLanguages() as $language) {
                $messages[(int) $language['language_id']] = self::raw($field('product_message')[$language['language_id']] ?? '');
            }
            $submitted['product_message'] = $messages;
        }
        if (is_array($field('payment_terms'))) {
            $submitted['payment_terms'] = array_values(array_map(static fn (mixed $days): string => self::raw($days), $field('payment_terms')));
        }
        if (is_array($field('surcharge_grid'))) {
            $grid = [];
            foreach ($field('surcharge_grid') as $days => $row) {
                foreach (is_array($row) ? $row : [] as $column => $value) {
                    $grid[(string) $days][(string) $column] = self::raw($value);
                }
            }
            $submitted['surcharge_grid'] = $grid;
        }
        if (isset($post[self::CODE . '_custom_headers_posted'])) {
            // The table posts a marker, so removing every row saves an empty table.
            $rows = [];
            foreach (is_array($field('custom_headers')) ? $field('custom_headers') : [] as $row) {
                if (is_array($row)) {
                    $rows[] = ['name' => self::raw($row['name'] ?? ''), 'value' => self::raw($row['value'] ?? ''), 'send_from_browser' => self::raw($row['send_from_browser'] ?? '') === '1'];
                }
            }
            $submitted['custom_headers'] = $rows;
        }
        if (is_array($field('fulfil_status_ids'))) {
            $submitted['fulfil_status_ids'] = array_values(array_map(static fn (mixed $id): string => self::raw($id), $field('fulfil_status_ids')));
        }

        return $submitted;
    }

    /**
     * The "Tax codes for 0% lines" section's state (section 22.5) and, when
     * it shows the rows, one block per tax class: the exempt row, a row per
     * 0% percentage rate the class's rules use, and the no-rate row. The
     * merchant's country is the merchant record's, read for the credentials
     * in force.
     *
     * @return array{state: array{view: string, notices: list<string>, clear: bool, country: string, codes: list<array{code: string, display_name: string, rate: float}>}, blocks: list<array{title: string, rows: list<array{0: string, 1: string}>}>, rows: list<string>, list: array{fetched_at: int, failure: array{at: int, status: int}|null}|null}
     */
    private function taxCodeSection(Services $services): array
    {
        $hasKey = $services->settings->apiKey() !== '';
        $country = $hasKey ? TaxCodes::merchantCountry($services->records->record()) : '';
        $list = $country === '' ? null : $services->taxCodes->forCountry($country);
        $state = TaxCodes::sectionState([
            'has_key' => $hasKey,
            'raw' => $services->settings->store()->get(TaxCodes::MAP_KEY),
            'country' => $country,
            'list' => $list,
        ]);
        $blocks = [];
        $rows = [];
        if ($state['view'] === TaxCodes::VIEW_ROWS) {
            $zeroRates = [];
            $query = $this->db->query('SELECT tr.`tax_class_id`, r.`tax_rate_id`, r.`name`, gz.`name` AS `geo_zone` FROM `' . DB_PREFIX . 'tax_rule` tr JOIN `' . DB_PREFIX . 'tax_rate` r ON (r.`tax_rate_id` = tr.`tax_rate_id`) LEFT JOIN `' . DB_PREFIX . "geo_zone` gz ON (gz.`geo_zone_id` = r.`geo_zone_id`) WHERE r.`type` = 'P' AND r.`rate` = 0 ORDER BY tr.`priority`, r.`name`");
            foreach ($query->rows as $row) {
                // OpenCart stores names escaped; the template escapes them again.
                $name = html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8');
                $zone = html_entity_decode((string) ($row['geo_zone'] ?? ''), ENT_QUOTES, 'UTF-8');
                $zeroRates[(int) $row['tax_class_id']][(int) $row['tax_rate_id']] = $zone === '' ? $name : $name . ' (' . $zone . ')';
            }
            $this->load->model('localisation/tax_class');
            foreach ($this->model_localisation_tax_class->getTaxClasses() as $class) {
                $id = (int) $class['tax_class_id'];
                $block = [[TaxCodes::exemptKey($id), $this->language->get('text_tax_codes_exempt_row')]];
                foreach ($zeroRates[$id] ?? [] as $rateId => $label) {
                    $block[] = [TaxCodes::rateKey($id, $rateId), $label];
                }
                $block[] = [TaxCodes::noRuleKey($id), $this->language->get('text_tax_codes_none_row')];
                $blocks[] = ['title' => html_entity_decode((string) $class['title'], ENT_QUOTES, 'UTF-8'), 'rows' => $block];
                array_push($rows, ...array_column($block, 0));
            }
        }

        return ['state' => $state, 'blocks' => $blocks, 'rows' => $rows, 'list' => $list];
    }

    /**
     * The section as the template renders it: notices worded, and each row's
     * choices (none, the codes listed at 0%, and a saved code no longer
     * listed, marked, so nothing saved disappears unseen).
     *
     * @return array{view: string, notices: list<array{level: string, text: string}>, clear: bool, blocks: list<array{title: string, rows: list<array{key: string, label: string, selected: string, options: list<array{value: string, label: string}>}>}>}
     */
    private function taxCodeView(Services $services, string $brand): array
    {
        $section = $this->taxCodeSection($services);
        $state = $section['state'];
        $notices = [];
        if ($state['view'] === TaxCodes::VIEW_RECORD_ERROR) {
            $notices[] = ['level' => 'warning', 'text' => sprintf($this->language->get('text_tax_codes_record_error'), $brand)];
        } elseif ($state['view'] === TaxCodes::VIEW_LIST_ERROR) {
            $notices[] = ['level' => 'warning', 'text' => sprintf($this->language->get('text_tax_codes_list_error'), $state['country'], $brand)];
        } elseif (($failure = $section['list']['failure'] ?? null) !== null) {
            // A failed refresh while an older list is held: name the failure and the list's age.
            $notices[] = ['level' => 'warning', 'text' => sprintf($this->language->get('text_tax_codes_stale'), $state['country'], $brand, gmdate('Y-m-d H:i', $failure['at']) . 'Z', $failure['status'], gmdate('Y-m-d H:i', $section['list']['fetched_at']) . 'Z')];
        }
        $wording = [
            TaxCodes::NOTICE_UNREADABLE => ['danger', sprintf($this->language->get('text_tax_codes_unreadable'), $brand)],
            TaxCodes::NOTICE_SAVED_VALID => ['info', sprintf($this->language->get('text_tax_codes_saved_valid'), $brand, $state['country'])],
            TaxCodes::NOTICE_SAVED_INVALID => ['warning', sprintf($this->language->get('text_tax_codes_saved_invalid'), $state['country'], $brand)],
        ];
        foreach ($state['notices'] as $notice) {
            $notices[] = ['level' => $wording[$notice][0], 'text' => $wording[$notice][1]];
        }
        $stored = TaxCodes::parse($services->settings->store()->get(TaxCodes::MAP_KEY)) ?? [];
        $zero = TaxCodes::zeroRated($state['codes']);
        $listed = array_column($zero, 'code');
        $blocks = [];
        foreach ($section['blocks'] as $block) {
            $rows = [];
            foreach ($block['rows'] as [$key, $label]) {
                $selected = $stored[$key] ?? '';
                $options = [['value' => '', 'label' => $this->language->get('text_tax_codes_none')]];
                foreach ($zero as $code) {
                    $options[] = ['value' => $code['code'], 'label' => $code['display_name'] . ' (' . $code['code'] . ')'];
                }
                if ($selected !== '' && !in_array($selected, $listed, true)) {
                    $options[] = ['value' => $selected, 'label' => sprintf($this->language->get('text_tax_codes_unlisted'), $selected)];
                }
                $rows[] = ['key' => $key, 'label' => $label, 'selected' => $selected, 'options' => $options];
            }
            $blocks[] = ['title' => $block['title'], 'rows' => $rows];
        }

        return ['view' => $state['view'], 'notices' => $notices, 'clear' => $state['clear'], 'blocks' => $blocks];
    }

    /**
     * The shop's text custom fields on the account or the address, any of
     * which may hold the buyer's VAT number (section 22.4).
     *
     * @return list<array{id: string, name: string}>
     */
    private function buyerVatFields(): array
    {
        $query = $this->db->query('SELECT cf.`custom_field_id`, cfd.`name` FROM `' . DB_PREFIX . 'custom_field` cf JOIN `' . DB_PREFIX . "custom_field_description` cfd ON (cfd.`custom_field_id` = cf.`custom_field_id` AND cfd.`language_id` = '" . (int) $this->config->get('config_language_id') . "') WHERE cf.`location` IN ('account', 'address') AND cf.`type` = 'text' ORDER BY cf.`sort_order`, cfd.`name`");

        return array_values(array_map(static fn (array $row): array => ['id' => (string) $row['custom_field_id'], 'name' => html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8')], $query->rows));
    }

    /**
     * The header table's refusals (code:row index, comma separated) as one
     * sentence per row, naming the header, then that nothing on the tab
     * was saved.
     *
     * @param list<array{name: string, value: string, send_from_browser: bool}> $rows
     */
    private function headersMessage(string $codes, array $rows): string
    {
        $sentences = [];
        foreach (explode(',', $codes) as $refusal) {
            [$code, $index] = array_pad(explode(':', $refusal), 2, '');
            $sentences[] = sprintf($this->language->get('error_header_' . $code), trim($rows[(int) $index]['name'] ?? ''));
        }
        $sentences[] = $this->language->get('error_headers_tab');

        return htmlspecialchars(implode(' ', $sentences), ENT_QUOTES, 'UTF-8');
    }

    /** The grid's refusals (code:column:days, comma separated) as one sentence per cell. */
    private function gridMessage(string $codes): string
    {
        $sentences = [];
        foreach (explode(',', $codes) as $refusal) {
            [$code, $column, $days] = array_pad(explode(':', $refusal), 3, '');
            $sentences[] = sprintf($this->language->get('error_' . $code), $this->language->get('column_' . $column), (int) $days);
        }

        return htmlspecialchars(implode(' ', $sentences), ENT_QUOTES, 'UTF-8');
    }

    /** A posted value as typed: Request::clean ran htmlspecialchars (ENT_COMPAT) on it, which this undoes exactly. */
    private static function raw(mixed $value): string
    {
        return is_scalar($value) ? htmlspecialchars_decode((string) $value, ENT_COMPAT) : '';
    }

    /** @return list<int> */
    private function orderStatusIds(): array
    {
        $this->load->model('localisation/order_status');

        return array_map(static fn (array $status): int => (int) $status['order_status_id'], $this->model_localisation_order_status->getOrderStatuses());
    }

    /**
     * The health row's sentence: "Not shown at checkout" and the first
     * reason that holds for every cart, or "Shown at checkout" and the
     * per-cart constraints.
     *
     * @return array{ok: bool, text: string}
     */
    private function healthRow(Services $services, string $shopCurrency): array
    {
        $this->load->model('localisation/currency');
        $currencies = array_values(array_map(static fn (array $row): string => (string) $row['code'], array_filter($this->model_localisation_currency->getCurrencies(), static fn (array $row): bool => (bool) $row['status'])));
        $this->load->model('setting/extension');
        $feeTotal = (bool) $this->config->get('total_two_fee_status') && $this->model_setting_extension->getExtensionByCode('total', 'two_fee') !== [];
        $health = $services->availability->health((bool) $this->config->get(self::CODE . '_status'), $shopCurrency, $currencies, $feeTotal);
        $brand = Brand::default();
        if (!$health['shown']) {
            return ['ok' => false, 'text' => $this->language->get('text_health_hidden') . ' - ' . sprintf($this->language->get('text_withheld_' . $health['reason']), $brand->providerFullName, $shopCurrency)];
        }
        $clauses = [];
        $floors = array_map(fn (array $floor): string => sprintf($this->language->get('text_health_floor'), number_format($floor['amount'], 2, '.', ''), $floor['currency'], $this->language->get('text_basis_' . $floor['basis'])), $health['floors']);
        if ($floors !== []) {
            $clauses[] = sprintf($this->language->get('text_health_below'), implode(' ' . $this->language->get('text_or') . ' ', $floors));
        }
        if ($health['buyer_countries'] !== []) {
            $clauses[] = sprintf($this->language->get('text_health_countries'), implode(', ', $health['buyer_countries']));
        }
        if ($health['refused_currencies'] !== []) {
            $clauses[] = sprintf($this->language->get('text_health_currencies'), implode(', ', $health['refused_currencies']));
        }
        $fx = $services->fx->table();
        if ($fx !== null && $fx['fetched_at'] > 0) {
            $clauses[] = sprintf($this->language->get('text_health_fx'), $brand->productName, gmdate('Y-m-d H:i', $fx['fetched_at']) . 'Z');
        }
        if ($health['fee_quote_failure'] !== null) {
            $clauses[] = sprintf($this->language->get('text_health_fee_quote'), gmdate('Y-m-d H:i', $health['fee_quote_failure']['at']) . 'Z', $health['fee_quote_failure']['detail'], $brand->productName);
        }

        return ['ok' => true, 'text' => $this->language->get('text_health_shown') . ($clauses === [] ? '' : ' - ' . implode('; ', $clauses))];
    }

    /** The merchant-facing sentence for a verdict, empty for ok. */
    private function keyMessage(string $status, ?int $code): string
    {
        $brand = Brand::default()->productName;

        return match ($status) {
            ApiKeyVerdict::OK => '',
            ApiKeyVerdict::INVALID => sprintf($this->language->get('error_key_invalid'), $brand),
            ApiKeyVerdict::SERVICE_ERROR => sprintf($this->language->get('error_key_service'), $brand, (int) $code),
            ApiKeyVerdict::UNREACHABLE => sprintf($this->language->get('error_key_unreachable'), $brand),
            ApiKeyVerdict::NOT_CONFIGURED => sprintf($this->language->get('error_key_not_configured'), $brand),
            ApiKeyVerdict::VERIFYING => $this->language->get('text_key_verifying'),
            default => $code !== null
                ? sprintf($this->language->get('error_key_unexpected'), $brand, $code)
                : $this->language->get('error_key_unverified'),
        };
    }

    /**
     * What the save says about the credentials: refused (shown on the field
     * at fault) or a note on the success line. Null when they were not posted.
     *
     * @param array{credentials: string|null, status: string|null, code: int|null} $result
     * @return array{refused: bool, field: string, text: string}|null
     */
    private function credentialsMessage(array $result): ?array
    {
        $verdict = $result['status'] === null ? '' : $this->keyMessage($result['status'], $result['code']);

        return match ($result['credentials']) {
            null => null,
            SettingsSave::SAVED, SettingsSave::SAVED_UNVERIFIED, SettingsSave::UNCHANGED => ['refused' => false, 'field' => 'api_key', 'text' => $verdict],
            SettingsSave::MISSING => ['refused' => true, 'field' => 'api_key', 'text' => $this->language->get('error_key_missing')],
            SettingsSave::KEPT_REJECTED, SettingsSave::KEPT_UNVERIFIED => ['refused' => true, 'field' => 'api_key', 'text' => trim($verdict . ' ' . $this->language->get('text_key_kept'))],
            SettingsSave::KEPT_ENVIRONMENT => ['refused' => true, 'field' => 'environment', 'text' => $this->language->get('error_kept_environment')],
            default => ['refused' => true, 'field' => 'api_key', 'text' => $this->language->get('error_' . $result['credentials'])],
        };
    }

    /** @param array<string, mixed> $json */
    private function json(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }
}
