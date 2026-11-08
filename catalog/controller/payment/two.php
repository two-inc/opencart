<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\BuyerFields;
use Opencart\System\Library\Extension\Two\Core\CompanyCapture;
use Opencart\System\Library\Extension\Two\Core\CompanyLookup;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\RateLimiter;
use Opencart\System\Library\Extension\Two\Core\RequestToken;
use Opencart\System\Library\Extension\Two\Opencart\DbOrderLock;
use Opencart\System\Library\Extension\Two\Opencart\DbRateLimits;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\PaymentTerms;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Storefront adapter for the Two payment method: the tile, the checkout
 * capture's endpoints (company search, sole-trader signup tokens, the saved
 * company per address role, the order intent), placing the order (create the Two order, send the buyer to
 * Two's hosted checkout), and the confirm and cancel callbacks Two sends the
 * buyer back to. The rules live in the core's OrderFlow.
 *
 * The order stays unplaced (status 0, OpenCart's missing order, or the void
 * status OpenCart 4.1.0.0 sets on every checkout edit) until Two confirms it, as OpenCart's own redirect payment methods leave it: moving
 * an order off status 0 is what sends the buyer the order confirmation.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Engine\Event $event
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\Url $url
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Response $response
 * @property-read \Opencart\System\Library\Cart\Customer $customer
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\System\Library\Cart\Tax $tax
 * @property-read \Opencart\System\Library\Cart\Currency $currency
 * @property-read \Opencart\Catalog\Model\Checkout\Order $model_checkout_order
 * @property-read \Opencart\Catalog\Model\Localisation\Country $model_localisation_country
 * @property-read \Opencart\Catalog\Model\Extension\Two\Payment\Two $model_extension_two_payment_two
 */
class Two extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/two/payment/two';
    private const TOKEN_PATTERN = '~^[0-9a-f]{48}\z~';
    /** How long a callback waits for another hit on the same order to finish. */
    private const LOCK_SECONDS = 30;

    /** The named lock this request holds, released before every redirect (Response::redirect exits, so a finally never runs). */
    private ?string $lock = null;

    /** The address roles a company is captured for, and the tile's own. */
    public const ROLES = ['payment', 'shipping', 'tile', 'account'];
    private const SESSION_KEY = 'two_checkout';
    /** How long the registry's per-country answers are kept in the session. */
    private const CACHE_SECONDS = 3600;

    /**
     * The tile under the payment step: the company (searched here, or the
     * one captured in the address form), the optional fields and the
     * confirm button. Rendered once Two is the chosen method, so the order
     * intent it asks never slows the method list.
     */
    public function index(): string
    {
        $this->load->language(self::ROUTE);
        $services = $this->services();
        // Only the placement setting decides whether the tile holds the company
        // control: never what the address forms captured (TWO-26721).
        $inTile = $services->settings->companyControlInTile();
        $company = $inTile ? $this->companyFor('tile') : $this->addressCompany($this->buyerRole(false));
        $this->load->model(self::ROUTE);
        $fields = [];
        $brand = Brand::default()->productName;
        $tooltips = $services->settings->displayTooltips();
        foreach ($services->settings->optionalFields() as $field) {
            $fields[] = ['name' => $field, 'label' => $this->language->get('entry_' . $field), 'type' => $field === 'invoice_email' ? 'email' : 'text', 'help' => $tooltips ? sprintf($this->language->get('help_' . $field), $brand) : ''];
        }
        $aboutUrl = Brand::default()->aboutUrl;
        $model = $this->model_extension_two_payment_two;
        $terms = $services->paymentTerms();
        $selected = $terms->selected($model->chosenTerm());
        $eom = $terms->isEndOfMonth();
        $chips = array_map(fn (int $days): array => [
            'days' => $days,
            'text' => sprintf($this->language->get($eom ? 'text_term_eom' : 'text_term_days'), $days),
            'name' => $eom ? sprintf($this->language->get('text_term_eom_name'), $days) : '',
        ], $terms->offered());

        return $this->load->view(self::ROUTE, [
            // Plain text under the method title; blank renders nothing (the brand declares no default tagline).
            'subtitle' => $services->settings->subtitle((int) $this->config->get('config_language_id')),
            // Only an http(s) target is ever linked.
            'about_url' => $services->settings->showAboutLink() && preg_match('~^https?://~i', $aboutUrl) === 1 ? $aboutUrl : '',
            'text_about' => sprintf($this->language->get('text_about'), $brand),
            'text_about_tooltip' => sprintf($this->language->get('text_about_body'), $brand, $brand) . ' ' . $this->language->get('text_about_strong') . ' ' . $this->language->get('text_about_more'),
            'terms' => $chips,
            'selected_term' => $selected,
            'text_terms_title' => $this->language->get('text_terms_title'),
            'text_terms_description' => $this->language->get($eom ? 'text_terms_description_eom' : 'text_terms_description'),
            'text_pay_in' => $selected === null ? '' : sprintf($this->language->get($eom ? 'text_pay_in_eom' : 'text_pay_in'), $selected),
            'language' => $this->config->get('config_language'),
            'in_tile' => $inTile,
            'country' => $this->model_extension_two_payment_two->buyerCountry() ?? '',
            'company' => $company,
            'company_label' => $company === null ? '' : CompanyLookup::displayLabel($company['company_name'], $company['organization_number']),
            'fields' => $fields,
            'edit_address' => $editAddress = $this->editAddressLink(),
            // The summary of a saved address is what that address keeps: the
            // script never replaces it with a capture from the new-address form.
            'saved_address' => !$inTile && (int) ($this->session->data[$this->buyerRole(false) . '_address']['address_id'] ?? 0) > 0,
            // A saved address has no company field on the page: say where it is.
            'text_company_missing' => sprintf($this->language->get($editAddress === '' ? 'text_company_missing' : 'text_company_missing_saved'), $brand),
            'text_company_edit_address' => $this->language->get('text_company_edit_address'),
            'text_company_from_address' => $this->language->get('text_company_from_address'),
            'entry_company' => $this->language->get('entry_company'),
            'text_optional' => $this->language->get('text_optional'),
            'button_confirm' => $this->language->get('button_confirm'),
        ]);
    }

    /** Places the order: asks the intent again, creates the Two order and answers with Two's payment URL. */
    public function confirm(): void
    {
        $this->load->language(self::ROUTE);
        $json = [];
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $this->load->model('checkout/order');
        $order = $orderId > 0 ? $this->model_checkout_order->getOrder($orderId) : [];
        if (!$order || !$this->unplaced($order)) {
            $json['error'] = $this->language->get('error_order');
        } elseif (($this->session->data['payment_method']['code'] ?? '') !== 'two.two') {
            $json['error'] = $this->language->get('error_payment_method');
        }
        $services = $this->services();
        $fields = BuyerFields::fromPost($this->postedFields(), $services->settings->optionalFields());
        if (!$json && $fields['refused'] !== []) {
            $json['error'] = $this->fieldMessage($fields['refused']);
        }
        if (!$json) {
            // The page may be stale: OpenCart keeps the chosen method through a currency or address change.
            $this->load->model(self::ROUTE);
            $facts = $this->model_extension_two_payment_two->checkoutFacts();
            $stale = $services->availability->placementReason($facts);
            if ($stale !== null) {
                $this->log->write('Two: order ' . $orderId . ' not placed: ' . $stale['detail']);
                $json['error'] = htmlspecialchars(sprintf($this->language->get('error_placement_' . $stale['reason']), Brand::default()->productName, strtoupper((string) $facts['currency'])), ENT_QUOTES, 'UTF-8');
            }
        }

        if (!$json) {
            // One placement of an order at a time: a second press or a retry while
            // the first is still talking to Two is refused, never run alongside it.
            $placed = OrderFlow::exclusively(new DbOrderLock($this->db), $orderId, fn (): array => $this->place($orderId, $services, $fields['fields']));
            $json = $placed['ok'] ? ['redirect' => $placed['redirect'] ?? ''] : ['error' => $placed['error'] ?? $this->buyerMessage($placed['buyer'] ?? [ErrorMapper::GENERIC])];
        }

        $this->json($json);
    }

    /**
     * One placement, under the order's lock: the term, the intent again,
     * then the create. The buyer's notice comes back escaped.
     *
     * @param array<string, string> $fields the optional buyer fields
     * @return array{ok: bool, redirect?: string, error?: string}
     */
    private function place(int $orderId, Services $services, array $fields): array
    {
        $this->load->model(self::ROUTE);
        $term = $this->model_extension_two_payment_two->placementTermRefusal($orderId);
        if ($term !== null) {
            // Section 4: only a term the merchant record offers, at the fee quoted for it.
            $this->log->write('Two: order ' . $orderId . ' not placed: ' . $term['detail']);

            return ['ok' => false, 'error' => htmlspecialchars(sprintf($this->language->get('error_placement_' . $term['reason']), Brand::default()->productName), ENT_QUOTES, 'UTF-8')];
        }
        $company = $this->buyerCompany($services->settings->companySearchInAddressArea());
        $token = OrderFlow::newToken();
        $urls = [
            'merchant_confirmation_url' => $this->callbackUrl(OrderFlow::CALLBACK_CONFIRM, $orderId, $token, false),
            'merchant_cancel_order_url' => $this->callbackUrl(OrderFlow::CALLBACK_CANCEL, $orderId, $token, false),
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ];
        $input = $this->model_extension_two_payment_two->orderInput($orderId, $company, $urls, $fields);
        $flow = $services->orderFlow($this->event);
        // Section 6.3: the intent is asked again at placement.
        $intent = $input === null ? null : $flow->intent($input['order'], $input['lines'], $input['totals'], $this->sessionHash(), $input['context'] + ['merchant_short_name' => $this->merchantShortName($services)]);
        $refusal = $intent === null ? null : OrderFlow::afterIntent($intent);
        if ($intent !== null && $refusal !== null) {
            // Section 6.3: only an approved intent lets the order be created.
            if ($intent['buyer'] !== [ErrorMapper::COMPANY_REQUIRED]) {
                $this->note($orderId, $intent['message']);
            }

            return ['ok' => false, 'error' => $refusal === [OrderFlow::INTENT_DECLINED] ? $this->declinedNotice() : $this->buyerMessage($refusal)];
        }
        $result = $input === null
            ? ['ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'reason' => Message::of('note_order_unreadable')->english(), 'message' => Message::of('note_order_unreadable')]
            : $flow->create($input['order'], $input['lines'], $input['totals'], $token, $input['context']);
        if ($result['ok']) {
            return ['ok' => true, 'redirect' => $result['redirect']];
        }
        if ($result['buyer'] !== [ErrorMapper::COMPANY_REQUIRED]) {
            $this->note($orderId, $result['message']);
        }

        return ['ok' => false, 'error' => $this->buyerMessage($result['buyer'])];
    }

    /**
     * The order intent for the session's order, asked from the tile once the
     * company is known (never from the method list). Answers the outcome and
     * the notice to show, if the brand shows one.
     */
    public function intent(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guarded(true, 'intent')) {
            return;
        }
        $limit = RateLimiter::sessionIntent(array_map('intval', (array) ($this->state()['intent_stamps'] ?? [])), time());
        $this->remember('intent_stamps', $limit['stamps']);
        if (!$limit['allowed']) {
            $this->json(['outcome' => OrderFlow::UNDECIDED, 'notice' => htmlspecialchars($this->language->get('error_too_many'), ENT_QUOTES, 'UTF-8')]);

            return;
        }
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $this->load->model('checkout/order');
        $order = $orderId > 0 ? $this->model_checkout_order->getOrder($orderId) : [];
        $services = $this->services();
        // The preview switched off (Checkout fields): nothing is asked here; confirm still asks its own intent.
        if (!$services->settings->orderIntentPreview() || !$order || !$this->unplaced($order) || ($this->session->data['payment_method']['code'] ?? '') !== 'two.two') {
            $this->json(['outcome' => OrderFlow::UNDECIDED, 'notice' => '']);

            return;
        }
        $this->load->model(self::ROUTE);
        $input = $this->model_extension_two_payment_two->orderInput($orderId, $this->buyerCompany($services->settings->companySearchInAddressArea()), []);
        if ($input === null) {
            $this->json(['outcome' => OrderFlow::UNDECIDED, 'notice' => '']);

            return;
        }
        // Two can take seconds to answer: reload the stored session before this
        // request writes it back, so a shipping or address change OpenCart
        // saved meanwhile stands, then put this request's intent stamps back.
        $stamps = $limit['stamps'];
        $result = CompanyCapture::relay(
            fn (): array => $services->orderFlow($this->event)->intent($input['order'], $input['lines'], $input['totals'], $this->sessionHash(), $input['context'] + ['merchant_short_name' => $this->merchantShortName($services)]),
            $this->reloadSession(...),
            function (array $result) use ($stamps): void {
                $this->remember('intent_stamps', RateLimiter::keepStamps(array_map('intval', (array) ($this->state()['intent_stamps'] ?? [])), $stamps, time()));
            },
        );
        $brand = Brand::default();
        $notice = match (true) {
            $result['outcome'] === OrderFlow::APPROVED && $brand->intentApprovedNoticeEnabled => htmlspecialchars($brand->intentApprovedNotice ?? sprintf($this->language->get('text_intent_approved'), $brand->productName), ENT_QUOTES, 'UTF-8'),
            $result['outcome'] === OrderFlow::DECLINED && $brand->intentDeclinedNoticeEnabled => $this->declinedNotice(),
            $result['buyer'] !== [] => $this->buyerMessage($result['buyer']),
            default => '',
        };
        $this->json(['outcome' => $result['outcome'], 'notice' => $notice]);
    }

    /**
     * The buyer's term (Build Guide section 4): kept in the session for the
     * fee total and the order, and refused unless the merchant record
     * offers it. The script then reloads the totals, so the fee follows.
     */
    public function term(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guarded(true, 'term')) {
            return;
        }
        $days = $this->services()->paymentTerms()->accept($this->request->post['days'] ?? null);
        if ($days === null) {
            $this->json(['ok' => false, 'error' => htmlspecialchars($this->language->get('error_term'), ENT_QUOTES, 'UTF-8')]);

            return;
        }
        $this->session->data['two_payment_term'] = $days;
        $this->json(['ok' => true]);
    }

    /**
     * The fee each offered term would add to this cart, for the chips: tax
     * included when the shop shows prices with tax (config_tax), as OpenCart
     * shows its own totals. A preview only: a term whose quote fails shows
     * no fee, and never decides what is charged.
     */
    public function fees(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guarded(false, 'fees')) {
            return;
        }
        $this->load->model(self::ROUTE);
        $model = $this->model_extension_two_payment_two;
        $currency = $model->sessionCurrency();
        $taxClass = $this->services()->surcharge()->taxClassId;
        $fees = [];
        foreach ($model->previewFees() as $days => $net) {
            $shown = $this->tax->calculate($net, $taxClass, (bool) $this->config->get('config_tax'));
            $fees[(string) $days] = htmlspecialchars(sprintf($this->language->get('text_fee_plus'), $this->currency->format($shown, $currency, 1.0)), ENT_QUOTES, 'UTF-8');
        }
        $this->json(['fees' => (object) $fees]);
    }

    /** The company search, relayed so the API key stays on the server. */
    public function search(): void
    {
        if (!$this->guarded(false, 'search')) {
            return;
        }
        $companies = CompanyCapture::relay(fn (): ?array => $this->services()->companies->search((string) ($this->request->get['q'] ?? ''), (string) ($this->request->get['country'] ?? '')), $this->reloadSession(...));
        $this->json($companies === null ? ['error' => 'unavailable'] : ['items' => $companies]);
    }

    /** One company's organisation number and addresses, by its search result's lookup id. */
    public function companyDetails(): void
    {
        if (!$this->guarded(false, 'companyDetails')) {
            return;
        }
        $details = CompanyCapture::relay(fn (): ?array => $this->services()->companies->details((string) ($this->request->get['lookup_id'] ?? '')), $this->reloadSession(...));
        $this->json($details ?? ['error' => 'unavailable']);
    }

    /** The countries the company search covers, kept in the session for an hour. */
    public function countries(): void
    {
        if (!$this->guarded(false, 'countries')) {
            return;
        }
        $cached = $this->state()['countries'] ?? null;
        if (!is_array($cached) || ($cached['at'] ?? 0) < time() - self::CACHE_SECONDS) {
            $countries = CompanyCapture::relay(fn (): ?array => $this->services()->companies->supportedCountries(), $this->reloadSession(...));
            $cached = $countries === null ? null : ['at' => time(), 'list' => $countries];
            if ($cached !== null) {
                $this->remember('countries', $cached);
            }
        }
        $this->json(CompanyLookup::countriesEnvelope($cached['list'] ?? null));
    }

    /** Whether a buyer in a country may enrol as a sole trader; one country kept in the session. */
    public function soleTrader(): void
    {
        if (!$this->guarded(false, 'soleTrader')) {
            return;
        }
        $country = strtoupper(trim((string) ($this->request->get['country'] ?? '')));
        $cached = $this->state()['sole_trader'] ?? null;
        if (is_array($cached) && ($cached['country'] ?? '') === $country && is_array($cached['types'] ?? null) && ($cached['at'] ?? 0) >= time() - self::CACHE_SECONDS) {
            $this->json($cached['types']);

            return;
        }
        $types = CompanyCapture::relay(fn (): ?array => $this->services()->companies->supportedCompanyTypes($country), $this->reloadSession(...));
        if ($types === null) {
            $this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 502 Bad Gateway');
            $this->json(['error' => 'unavailable']);

            return;
        }
        // One slot, so a caller cannot grow the session one country at a time.
        $this->remember('sole_trader', ['country' => $country, 'types' => $types, 'at' => time()]);
        $this->json($types);
    }

    /** Mints the hosted sole-trader signup's tokens for this buyer's enrolment attempt. */
    public function soleTraderTokens(): void
    {
        $this->load->language(self::ROUTE);
        // The shared sole-trader module posts a JSON body of its own, so the
        // token rides on the query string. No country: the tokens are the
        // buyer's, minted at checkout load before any country is chosen.
        if (!$this->guarded(true, 'soleTraderTokens', true)) {
            return;
        }
        $attempt = (int) ($this->state()['sole_trader_attempt'] ?? 0) + 1;
        $this->remember('sole_trader_attempt', $attempt);
        $companies = $this->services()->companies;
        $tokens = CompanyCapture::relay(
            fn (): ?array => $companies->soleTraderTokens(IdempotencyKey::soleTraderAttempt($this->sessionHash(), $attempt)),
            $this->reloadSession(...),
            function (?array $tokens) use ($attempt): void {
                $this->remember('sole_trader_attempt', max($attempt, (int) ($this->state()['sole_trader_attempt'] ?? 0)));
            },
        );
        $this->json($tokens === null
            ? ['error' => $this->language->get('error_sole_trader')]
            : $tokens + ['signup_url' => $companies->signupUrl(), 'api_base' => $companies->apiBaseUrl()]);
    }

    /**
     * Keeps the company captured for one address role (or the tile) in the
     * session, each role on its own. An empty name clears the role.
     */
    public function saveCompany(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guarded(true, 'saveCompany')) {
            return;
        }
        $post = fn (string $key): string => trim(html_entity_decode((string) ($this->request->post[$key] ?? ''), ENT_QUOTES, 'UTF-8'));
        $role = $post('role');
        $company = in_array($role, self::ROLES, true)
            ? CompanyCapture::fromPost(['company_name' => $post('company_name'), 'organization_number' => $post('organization_number'), 'country' => $post('country'), 'source' => $post('source')])
            : null;
        if ($company === null) {
            $this->json(['ok' => false]);

            return;
        }
        $companies = $this->state()['companies'] ?? [];
        if ($company['company_name'] === '') {
            unset($companies[$role]);
        } else {
            $companies[$role] = $company;
            $this->topUpSavedAddress($role, $company);
        }
        $this->remember('companies', $companies);
        $this->json(['ok' => true]);
    }

    /**
     * The settings the checkout script reads, for the checkout page event.
     *
     * @return array<string, mixed>
     */
    public function scriptConfig(): array
    {
        $this->load->language(self::ROUTE);
        $services = $this->services();
        $this->load->model('localisation/country');
        $isoById = [];
        foreach ($this->model_localisation_country->getCountries() as $country) {
            $isoById[(string) $country['country_id']] = (string) $country['iso_code_2'];
        }
        $link = fn (string $action): string => str_replace('&amp;', '&', $this->url->link(self::ROUTE . '.' . $action, 'language=' . $this->config->get('config_language')));
        $brand = Brand::default()->productName;
        $copy = [];
        foreach (['pay_in', 'pay_in_eom'] as $key) {
            $copy[$key] = $this->language->get('text_' . $key);
        }
        foreach (['chip_registered', 'chip_sole_trader', 'chip_manual', 'search_aria', 'search_too_short', 'no_matches', 'search_unavailable', 'select_country', 'back_to_search', 'company_number', 'select_different_sole_trader', 'sole_trader_signup', 'sole_trader_error', 'address_unavailable', 'checking', 'settle_timeout', 'confirm_failed', 'totals_failed'] as $key) {
            $copy[$key] = sprintf($this->language->get('js_' . $key), $brand, CompanyLookup::MIN_TERM_LENGTH);
        }
        // The tile's line for the company the address form holds, kept current by the script.
        $copy['company_from_address'] = $this->language->get('text_company_from_address');
        // The panel fills in the count itself, so these two are not formatted here.
        foreach (['results_one', 'results_many'] as $key) {
            $copy[$key] = $this->language->get('js_' . $key);
        }
        $companies = $services->companies;

        return [
            'token' => $this->csrfToken(),
            'in_address' => $services->settings->companySearchInAddressArea(),
            'address_lookup' => $services->settings->addressLookup(),
            'checkout_api_url' => $companies->apiBaseUrl(),
            'checkout_page_url' => $companies->checkoutPageUrl(),
            // Only the header rows the merchant flagged "Also send from browser": the sole-trader autofill call is the one call the browser makes to Two itself.
            'custom_headers' => (object) $services->settings->browserCustomHeaders(),
            'urls' => ['search' => $link('search'), 'countries' => $link('countries'), 'sole_trader' => $link('soleTrader'), 'sole_trader_tokens' => $link('soleTraderTokens'), 'save_company' => $link('saveCompany'), 'intent' => $link('intent'), 'company_details' => $link('companyDetails'), 'confirm' => $link('confirm'), 'term' => $link('term'), 'fees' => $link('fees'), 'totals' => str_replace('&amp;', '&', $this->url->link('checkout/confirm.confirm', 'language=' . $this->config->get('config_language')))],
            'countries' => $isoById,
            'companies' => $this->state()['companies'] ?? (object) [],
            'min_length' => CompanyLookup::MIN_TERM_LENGTH,
            'end_of_month' => $services->settings->paymentTermType() === PaymentTerms::END_OF_MONTH,
            'intent_preview' => $services->settings->orderIntentPreview(),
            'i18n' => $copy,
        ];
    }

    /** Two sent the buyer back after verifying: confirm, then the shop's success page. */
    public function confirmation(): void
    {
        $this->callback(OrderFlow::CALLBACK_CONFIRM);
    }

    /** Two sent the buyer back after cancelling: cancel, then back to the cart with a notice. */
    public function cancel(): void
    {
        $this->callback(OrderFlow::CALLBACK_CANCEL);
    }

    private function callback(string $kind): void
    {
        $this->load->language(self::ROUTE);
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $token = (string) ($this->request->get['token'] ?? '');
        if ($orderId <= 0 || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $this->leave('cart', 'generic');

            return;
        }
        if ($this->bounce($kind, $orderId, $token)) {
            return;
        }
        // The token first, so a request that does not own the order never holds
        // its lock. Not logged: anyone can send one, so it would only fill the log.
        if (!$this->services()->orderFlow($this->event)->owns($orderId, $token)) {
            $this->leave('cart', 'generic');

            return;
        }
        // One callback per order at a time, so the status read before a write
        // cannot go stale under a concurrent hit (the bounce page's link and its
        // refresh). A guarded UPDATE cannot do this: OpenCart's addHistory must
        // see the old status itself, since the order mail and stock keep off it.
        $lock = DbOrderLock::name($orderId);
        $held = (int) ($this->db->query("SELECT GET_LOCK('" . $lock . "', " . self::LOCK_SECONDS . ") AS `held`")->row['held'] ?? 0) === 1;
        if (!$held) {
            // Another hit on this order is still talking to Two and may yet place it.
            $this->load->model('checkout/order');
            $order = $this->model_checkout_order->getOrder($orderId);
            $after = OrderFlow::afterLockTimeout($order && !$this->unplaced($order));
            $this->leave($after['goto'], $after['notice']);

            return;
        }
        $this->lock = $lock;
        try {
            $this->settle($kind, $orderId, $token);
        } finally {
            // Only reached when settle() throws: every normal path releases in leave().
            $this->releaseLock();
        }
    }

    private function releaseLock(): void
    {
        if ($this->lock !== null) {
            $this->db->query("SELECT RELEASE_LOCK('" . $this->lock . "')");
            $this->lock = null;
        }
    }

    private function settle(string $kind, int $orderId, string $token): void
    {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($orderId);
        if (!$order) {
            $this->leave('cart', 'generic');

            return;
        }
        $flow = $this->services()->orderFlow($this->event);

        if ($kind === OrderFlow::CALLBACK_CANCEL) {
            $owner = (int) ($this->session->data['order_id'] ?? 0) === $orderId
                || ($this->customer->isLogged() && (int) $order['customer_id'] === $this->customer->getId());
            if (!$owner || !$this->unplaced($order)) {
                $message = Message::of($owner ? 'note_cancel_callback_placed' : 'note_cancel_callback_session');
                $this->log->write('Two error: order ' . $orderId . ': ' . $message->english());
                $this->note($orderId, $message);
                $this->leave('cart', 'generic');

                return;
            }
            $result = $flow->cancel($orderId, $token);
        } elseif (!$this->unplaced($order) && $flow->owns($orderId, $token)) {
            // A second hit (the bounce page's link and its refresh) on the order the first hit placed.
            $this->leave('success', null);

            return;
        } else {
            $this->load->model(self::ROUTE);
            $model = $this->model_extension_two_payment_two;
            $rebuild = OrderFlow::rebuildFrom(static fn (array $company, array $fields, array $terms): ?array => $model->orderInput($orderId, $company, [], $fields, $terms));
            $result = $flow->confirm($orderId, $token, $rebuild, $model->orderRows($orderId));
        }

        if ($result['outcome'] === OrderFlow::UNAUTHORISED) {
            $this->log->write('Two error: ' . $kind . ' callback for order ' . $orderId . ': ' . $result['reason']);
        }
        // Read again before any write, under the lock.
        $order = $this->model_checkout_order->getOrder($orderId);
        $after = OrderFlow::afterCallback($kind, $result['outcome'], $order && !$this->unplaced($order));
        if ($after['place']) {
            // At the merchant's confirmed status (Order management), else the shop's default order status.
            $this->load->model(self::ROUTE);
            $this->model_extension_two_payment_two->place($orderId, $this->services()->settings->placedStatusId((int) $this->config->get('config_order_status_id')), $result['message']);
            // The buyer's next checkout starts on the default chain again.
            unset($this->session->data['two_payment_term'], $this->session->data['two_fee_quotes']);
        } elseif ($after['note']) {
            $this->note($orderId, $result['message']);
        }
        $this->leave($after['goto'], $after['notice']);
    }

    /** @param array<string, mixed> $order */
    private function unplaced(array $order): bool
    {
        $this->load->model(self::ROUTE);

        return $this->model_extension_two_payment_two->unplaced($order);
    }

    /** The reason in the order history of an unplaced order, its status unchanged. Placed orders are left alone. */
    private function note(int $orderId, Message $message): void
    {
        $order = $this->model_checkout_order->getOrder($orderId);
        if ($order && $this->unplaced($order)) {
            $this->model_extension_two_payment_two->historyNote($orderId, (int) $order['order_status_id'], $message);
        }
    }

    /**
     * OpenCart's session cookie is SameSite=Strict by default, so the redirect
     * from Two's checkout arrives without it, and the session startup would
     * replace the buyer's session (cart and login) with an empty one. The
     * first hop therefore drops that cookie and answers with a page that
     * reloads the callback from the shop itself, a same-site navigation that
     * carries the buyer's cookie. The target is rebuilt from the fixed route
     * and the validated order id and token, so it can never point elsewhere.
     */
    private function bounce(string $kind, int $orderId, string $token): bool
    {
        if (isset($this->request->cookie[(string) $this->config->get('session_name')]) || isset($this->request->get['two_bounce'])) {
            return false;
        }
        header_remove('Set-Cookie');
        $target = htmlspecialchars($this->callbackUrl($kind, $orderId, $token, true), ENT_QUOTES, 'UTF-8');
        $this->response->addHeader('Content-Type: text/html; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('Referrer-Policy: no-referrer');
        $this->response->setOutput('<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $target . '"><title>'
            . htmlspecialchars($this->language->get('text_returning'), ENT_QUOTES, 'UTF-8') . '</title></head><body><p><a href="' . $target . '">'
            . htmlspecialchars($this->language->get('text_continue'), ENT_QUOTES, 'UTF-8') . '</a></p></body></html>');

        return true;
    }

    private function callbackUrl(string $kind, int $orderId, string $token, bool $bounced): string
    {
        $args = 'language=' . $this->config->get('config_language') . '&order_id=' . $orderId . '&token=' . $token . ($bounced ? '&two_bounce=1' : '');

        return $this->url->link(self::ROUTE . '.' . $kind, $args, true);
    }

    /**
     * The success page, or the cart with a notice (where OpenCart shows it).
     *
     * @param 'cancelled'|'changed'|'pending'|'generic'|null $notice
     */
    private function leave(string $goto, ?string $notice): void
    {
        $this->releaseLock();
        if ($notice !== null) {
            $this->session->data['error'] = match ($notice) {
                'cancelled' => $this->language->get('text_cancelled'),
                'changed' => $this->language->get('text_cart_changed'),
                'pending' => $this->language->get('text_pending'),
                default => $this->buyerMessage([ErrorMapper::GENERIC]),
            };
        }
        $route = $goto === 'success' ? 'checkout/success' : 'checkout/cart';
        $this->response->redirect($this->url->link($route, 'language=' . $this->config->get('config_language'), true));
    }

    /**
     * The buyer's sentences as one notice, each closed with a full stop and
     * shown once, as the reference plugin joins them.
     *
     * @param list<string> $keys
     */
    private function buyerMessage(array $keys): string
    {
        $sentences = [];
        foreach ($keys as $key) {
            $sentence = trim(sprintf($this->language->get($key), Brand::default()->productName));
            if (preg_match('~[.!?]\z~u', $sentence) !== 1) {
                $sentence .= '.';
            }
            $sentences[$sentence] = true;
        }

        return htmlspecialchars(implode(' ', array_keys($sentences)), ENT_QUOTES, 'UTF-8');
    }

    /** The role whose company is the buyer: the tile's own, else the billing address when the shop collects one, else the shipping address. */
    private function buyerRole(bool $inTile): string
    {
        return $inTile ? 'tile' : ($this->config->get('config_checkout_payment_address') ? 'payment' : 'shipping');
    }

    /**
     * The company the order is placed for: what the buyer captured in the
     * address forms, else what the tile posts.
     *
     * @return array{company_name: string, organization_number: string, country_code: string}
     */
    private function buyerCompany(bool $inAddress): array
    {
        $captured = $inAddress ? $this->addressCompany($this->buyerRole(false)) : null;
        if ($captured !== null) {
            $company = $captured;
        } else {
            // The tile's control saves its capture as the session's `tile` role.
            // A name with no capture behind it has no company id, so placement
            // refuses it, as the sibling plugins do.
            $company = $this->companyFor('tile') ?? [
                'company_name' => trim(html_entity_decode((string) ($this->request->post['two_company_name'] ?? ''), ENT_QUOTES, 'UTF-8')),
                'organization_number' => '',
                'country_code' => '',
            ];
        }
        $country = $company['country_code'];

        return [
            'company_name' => $company['company_name'],
            'organization_number' => $company['organization_number'],
            'country_code' => preg_match('~^[A-Z]{2}\z~', $country) === 1 ? $country : '',
        ];
    }

    /**
     * OpenCart's own edit form for the saved address the checkout is using,
     * where its company field is; '' for a new address or a guest.
     */
    private function editAddressLink(): string
    {
        $role = $this->buyerRole(false);
        $addressId = (int) ($this->session->data[$role . '_address']['address_id'] ?? 0);
        if ($addressId <= 0 || !$this->customer->isLogged()) {
            return '';
        }

        return $this->url->link('account/address.form', 'language=' . $this->config->get('config_language') . '&address_id=' . $addressId);
    }

    /**
     * The company for an address role: on a saved address, what that address
     * keeps (captured with it, or on OpenCart's own edit form); on a new one,
     * what the buyer captured in its form this session.
     *
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    private function addressCompany(string $role): ?array
    {
        return CompanyCapture::forAddress($this->session->data[$role . '_address'] ?? null, $this->companyFor($role), $this->storedAddressFields($role));
    }

    /**
     * The stored `custom_field` of the saved address the role uses, read for
     * the signed-in customer only; null with no such address or row.
     *
     * @return array<mixed>|null
     */
    private function storedAddressFields(string $role): ?array
    {
        $addressId = (int) ($this->session->data[$role . '_address']['address_id'] ?? 0);
        if ($addressId <= 0 || !$this->customer->isLogged()) {
            return null;
        }
        $row = $this->db->query('SELECT `custom_field` FROM `' . DB_PREFIX . 'address` WHERE `address_id` = ' . $addressId . ' AND `customer_id` = ' . (int) $this->customer->getId())->row;
        if (!$row) {
            return null;
        }
        $fields = json_decode((string) ($row['custom_field'] ?? ''), true);

        return is_array($fields) ? $fields : [];
    }

    /**
     * A pick saves before its number arrives, so OpenCart may already have
     * stored the saved address with a numberless capture: give it the number
     * now, so a later checkout on that address is not refused for want of one.
     *
     * @param array{company_name: string, organization_number: string, country_code: string, source: string} $company
     */
    private function topUpSavedAddress(string $role, array $company): void
    {
        $stored = in_array($role, ['payment', 'shipping'], true) ? $this->storedAddressFields($role) : null;
        $updated = $stored === null ? null : CompanyCapture::topUp($stored, $company);
        if ($updated === null) {
            return;
        }
        $addressId = (int) $this->session->data[$role . '_address']['address_id'];
        $this->db->query("UPDATE `" . DB_PREFIX . "address` SET `custom_field` = '" . $this->db->escape((string) json_encode($updated)) . "' WHERE `address_id` = " . $addressId . ' AND `customer_id` = ' . (int) $this->customer->getId());
        $this->session->data[$role . '_address']['custom_field'] = $updated;
    }

    /** @return array{company_name: string, organization_number: string, country_code: string, source: string}|null */
    private function companyFor(string $role): ?array
    {
        $company = $this->state()['companies'][$role] ?? null;

        return is_array($company) ? $company : null;
    }

    /** @return array<string, mixed> the posted optional fields, by field name */
    private function postedFields(): array
    {
        $fields = [];
        foreach (BuyerFields::FIELDS as $field) {
            $fields[$field] = html_entity_decode((string) ($this->request->post['two_' . $field] ?? ''), ENT_QUOTES, 'UTF-8');
        }

        return $fields;
    }

    /** @param list<string> $refused */
    private function fieldMessage(array $refused): string
    {
        $sentences = [];
        foreach ($refused as $field) {
            $sentences[] = $field === 'invoice_email'
                ? sprintf($this->language->get(ErrorMapper::INVOICE_EMAILS), Brand::default()->productName)
                : sprintf($this->language->get('error_field_too_long'), $this->language->get('entry_' . $field), BuyerFields::MAX_LENGTH);
        }

        return htmlspecialchars(implode(' ', $sentences), ENT_QUOTES, 'UTF-8');
    }

    private function declinedNotice(): string
    {
        $brand = Brand::default();

        return htmlspecialchars($brand->intentDeclinedNotice ?? sprintf($this->language->get('text_intent_declined'), $brand->productName), ENT_QUOTES, 'UTF-8');
    }

    private function merchantShortName(Services $services): string
    {
        $record = $services->records->record();

        return is_string($record['short_name'] ?? null) ? $record['short_name'] : '';
    }

    /**
     * Names the buyer's checkout session for idempotency keys: a hash, never
     * the session id. Empty without a session, so nothing keyed on it is sent.
     */
    private function sessionHash(): string
    {
        $id = $this->session->getId();

        return $id === '' ? '' : hash('sha256', 'two-checkout|' . $id);
    }

    /** The checkout's token against requests from other sites, one per session. */
    private function csrfToken(): string
    {
        $token = $this->state()['token'] ?? '';
        if (!is_string($token) || strlen($token) !== 32) {
            $token = bin2hex(random_bytes(16));
            $this->remember('token', $token);
        }

        return $token;
    }

    /**
     * Whether the request carries this session's token (and, for a write, is
     * a POST) and is within the caller's rate limit for the action
     * (RateLimiter); answers the refusal itself, a 429 for the limit.
     */
    private function guarded(bool $write, string $action, bool $queryToken = false): bool
    {
        // The refusals are shown to the buyer, so they come from the language file.
        $this->load->language(self::ROUTE);
        if (!RequestToken::accepts($write, $queryToken, (string) ($this->request->server['REQUEST_METHOD'] ?? ''), $this->request->post, $this->request->get, $this->state()['token'] ?? null)) {
            $this->json(['error' => htmlspecialchars($this->language->get('error_forbidden'), ENT_QUOTES, 'UTF-8'), 'code' => 'forbidden']);

            return false;
        }
        // The merchant's switch removes the per-caller ceiling only; the per-session intent limit stays.
        if (!$this->services()->settings->rateLimitDisabled() && !(new DbRateLimits($this->db))->allow($action, $this->caller(), time())) {
            $this->log->write('Two: rate limit exceeded on ' . $action . ' (more than ' . RateLimiter::CALLER_LIMITS[$action][0] . ' requests in ' . RateLimiter::CALLER_LIMITS[$action][1] . 's from one caller)');
            $this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 429 Too Many Requests');
            $this->json(['error' => htmlspecialchars($this->language->get('error_too_many'), ENT_QUOTES, 'UTF-8'), 'code' => 'rate_limited']);

            return false;
        }

        return true;
    }


    /**
     * Read the stored session again. A relay call can take seconds, and
     * OpenCart writes this request's whole session back when it ends: what a
     * save wrote meanwhile must not be overwritten by the copy read before.
     */
    private function reloadSession(): void
    {
        $this->session->start($this->session->getId());
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $state = $this->session->data[self::SESSION_KEY] ?? [];

        return is_array($state) ? $state : [];
    }

    private function remember(string $key, mixed $value): void
    {
        $state = $this->state();
        $state[$key] = $value;
        $this->session->data[self::SESSION_KEY] = $state;
    }

    /** @param array<string, mixed>|list<string> $json an object, or the bare list the shared company search reads for company types */
    private function json(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput((string) json_encode($json));
    }

    /**
     * The caller the rate limits count: the socket address, read from the
     * server's own environment, because OpenCart's startup overwrites
     * REMOTE_ADDR with the client-sent Client-IP header. Through the
     * merchant's trusted proxies, the nearest forwarded hop that is not one.
     */
    private function caller(): string
    {
        $socket = getenv('REMOTE_ADDR');
        if (!is_string($socket) || $socket === '') {
            // No server environment: REMOTE_ADDR is only the socket when no Client-IP header replaced it.
            $socket = isset($this->request->server['HTTP_CLIENT_IP']) ? '' : (string) ($this->request->server['REMOTE_ADDR'] ?? '');
        }

        return RateLimiter::callerIdentity($socket, (string) ($this->request->server['HTTP_X_FORWARDED_FOR'] ?? ''), $this->services()->settings->trustedProxies());
    }

    private function services(): Services
    {
        return new Services($this->db, $this->log, (int) $this->config->get('config_store_id'));
    }
}
