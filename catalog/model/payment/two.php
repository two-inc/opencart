<?php

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\FeeQuote;
use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\PaymentTerms;
use Opencart\System\Library\Extension\Two\Core\TaxCodes;
use Opencart\System\Library\Extension\Two\Opencart\NoteText;
use Opencart\System\Library\Extension\Two\Opencart\OrderTax;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Checkout adapter for the Two payment method: whether the tile is offered
 * (the availability chain over the session's cart),
 * and the shop's stored order mapped into OrderBuilder's neutral shapes.
 *
 * Every amount is read from the order's own rows (order, order_product,
 * order_total). OpenCart stores tax per product line and per rate for the
 * order, but none for a charge total such as shipping: there the tax is
 * what the shop's own tax engine gives the stored tax class at the order's
 * addresses and customer group (OrderTax, never the request's session),
 * the computation OpenCart itself made at checkout. The default
 * handler's shop-match check then refuses the order unless the lines add up
 * to the tax and total the shop actually charged, so nothing is invented.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Cart\Currency $currency
 * @property-read \Opencart\System\Library\Cart\Tax $tax
 * @property-read \Opencart\Catalog\Model\Checkout\Order $model_checkout_order
 * @property-read object{getTotals: \Closure} $model_checkout_cart the loader's proxy: getTotals is a closure, so its arguments pass by reference
 */
class Two extends \Opencart\System\Engine\Model
{
    /** Tax classes of the charge totals the shop's own extensions add, by total code. */
    private const CHARGE_TAX_CLASS_KEYS = ['handling' => 'total_handling_tax_class_id', 'low_order_fee' => 'total_low_order_fee_tax_class_id', 'two_fee' => 'payment_two_surcharge_tax_class_id'];

    /** How long a charged fee quote is reused across requests while nothing it depends on changes. */
    public const FEE_CACHE_SECONDS = 60;

    /** Set while the cart's gross without the fee is read, so the fee total adds nothing. */
    private static bool $feeSuspended = false;

    private ?Services $services = null;


    /** Whether this request already logged why Two was withheld: OpenCart asks for the methods more than once per render. */
    private static bool $withholdLogged = false;

    /**
     * The tile, when the availability chain (Build Guide section 18) offers
     * Two for this cart. A withheld tile is silent for the buyer; the log
     * names the reason once per request.
     *
     * @param array<string, mixed> $address the payment address OpenCart passes, empty when it collects none
     * @return array<string, mixed>
     */
    public function getMethods(array $address = []): array
    {
        $services = $this->services();
        $withheld = $services->availability->withholdReason($this->checkoutFacts($address), $this->converter(), fn (): ?string => $this->feeFailure($address));
        if ($withheld !== null) {
            if (!self::$withholdLogged) {
                self::$withholdLogged = true;
                $this->log->write('Two: payment method hidden (' . $withheld['reason'] . '): ' . $withheld['detail']);
            }

            return [];
        }
        // OpenCart 4.1 renders method names unescaped, and the title is stored as typed.
        // Prefixed and read back through the registry, as for the fee line below.
        $this->load->language('extension/two/payment/two', 'two_title');
        $title = htmlspecialchars($services->settings->title((int) $this->config->get('config_language_id'), $this->language->get('two_title_text_title_default')), ENT_QUOTES, 'UTF-8');

        return [
            'code' => 'two',
            'name' => $title,
            'option' => ['two' => ['code' => 'two.two', 'name' => $title]],
            'sort_order' => $this->config->get('payment_two_sort_order'),
        ];
    }

    /**
     * The cart as the availability chain reads it. OpenCart keeps every
     * amount in the shop's default currency and shows the session's, so the
     * gate checks the session currency and the minimum judges the default.
     *
     * @param array<string, mixed> $address
     * @return array{enabled: bool, currency: string, shop_currency: string, gross: float, net: float, buyer_country: string|null, value_currency: string}
     */
    public function checkoutFacts(array $address = []): array
    {
        [$total, $taxes] = $this->totalsWithoutFee();
        $shop = (string) $this->config->get('config_currency');

        return [
            'enabled' => (bool) $this->config->get('payment_two_status'),
            'fee_total' => $this->feeTotalEnabled(),
            'currency' => (string) ($this->session->data['currency'] ?? $shop),
            'shop_currency' => $shop,
            'gross' => (float) $total,
            'net' => (float) $total - (float) array_sum($taxes),
            'buyer_country' => $this->buyerCountry($address),
            'value_currency' => $shop,
        ];
    }

    /**
     * The buyer's country: the payment address, else the shipping address.
     *
     * @param array<string, mixed> $address
     */
    public function buyerCountry(array $address = []): ?string
    {
        foreach ([$address, $this->session->data['payment_address'] ?? [], $this->session->data['shipping_address'] ?? []] as $candidate) {
            $code = is_array($candidate) ? strtoupper(trim((string) ($candidate['iso_code_2'] ?? ''))) : '';
            if ($code === '' && is_array($candidate) && (int) ($candidate['country_id'] ?? 0) > 0) {
                $row = $this->db->query('SELECT `iso_code_2` FROM `' . DB_PREFIX . "country` WHERE `country_id` = '" . (int) $candidate['country_id'] . "'")->row;
                $code = strtoupper(trim((string) ($row['iso_code_2'] ?? '')));
            }
            if (preg_match('~^[A-Z]{2}\z~', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Converts at Two's own FX rates, the ones Two judges the minimum with,
     * or null when Two's table holds no rate for one of the two currencies.
     *
     * @return \Closure(float, string, string): ?float
     */
    public function converter(): \Closure
    {
        $fx = $this->services()->fx;

        return static fn (float $amount, string $from, string $to): ?float => $fx->convert($amount, $from, $to);
    }

    /** Whether the fee total must add nothing: the cart's gross without the fee is being read. */
    public static function feeSuspended(): bool
    {
        return self::$feeSuspended;
    }

    /**
     * The fee line for the cart, when Two is the chosen method and its term
     * prices one: the title, and the net in the shop's default currency (as
     * OpenCart keeps every total). Null when no fee applies, or when it
     * cannot be quoted: the method list then withholds Two and placement
     * refuses an order whose fee is missing. An admin edit puts back the fee
     * the order was placed with (FeeQuote::editLine), never a new quote.
     *
     * @return array{title: string, value: float, tax_class_id: int, taxes?: array<int, array{amount: float, name: string}>}|null
     */
    public function feeTotal(): ?array
    {
        $code = (string) ($this->session->data['payment_method']['code'] ?? '');
        // Only while Two is chosen: every other cart render must not read the record or ask Two.
        if (self::$feeSuspended || $code !== 'two.two') {
            return null;
        }
        // An admin edit keeps the fee the order was placed with, as the reference plugin replays it:
        // none stays none, and a placed fee goes back with its own net and tax rows, never repriced.
        if ($this->inAdminEdit()) {
            $placed = FeeQuote::editLine($this->services()->orders->get($this->editedOrderId()) ?? [], (float) $this->currency->getValue($this->sessionCurrency()), $this->sessionCurrency());

            return $placed === null ? null : ['title' => $placed['title'], 'value' => $placed['value'], 'tax_class_id' => 0, 'taxes' => $placed['tax_rates']];
        }
        $fee = $this->cartFee();
        $value = FeeQuote::lineValue($fee, $code, (float) $this->currency->getValue($this->sessionCurrency()));
        if ($value === null || $fee['days'] === null) {
            return null;
        }
        // Prefixed: the totals run inside other pages' rendering, whose own language keys must not be overwritten.
        // Read back through the registry: the loader returns the default language's copy, and OpenCart's
        // language event then loads the shop language's over it.
        $this->load->language('extension/two/payment/two', 'two_fee');
        $services = $this->services();

        return [
            'title' => $services->surcharge()->label($fee['days'], $services->paymentTerms()->isEndOfMonth(), $this->language->get('two_fee_text_fee_line'), $this->language->get('two_fee_text_fee_line_eom')),
            'value' => $value,
            'tax_class_id' => $services->surcharge()->taxClassId,
        ];
    }

    /**
     * The fee the cart is charged (FeeQuote::charged) with the term it is
     * for, and why it cannot be quoted when it cannot. The term is the one
     * stored with the order on an admin edit, else the buyer's choice, else
     * the default. Quotes are reused for FEE_CACHE_SECONDS by their request's
     * hash (rememberQuotes), so a checkout's many renders ask Two once.
     *
     * @param array<string, mixed> $address
     * @return array{applies: bool, days: int|null, net: float|null, failure: string}
     */
    public function cartFee(array $address = []): array
    {
        $services = $this->services();
        $this->rememberQuotes();
        // Outside the checkout (the header cart on every page) only a quote already held answers.
        $services->fees->askTwo = $this->inCheckout();
        $charged = $services->fees->charged($services->surcharge(), $services->paymentTerms(), $this->chosenTerm(), $this->grossWithoutFee(), $this->buyerCountry($address) ?? '', $this->sessionCurrency(), (string) $this->config->get('config_currency'));
        $this->keepQuotes();

        return $charged + ['failure' => $services->fees->failure];
    }

    /**
     * The fee each offered term would add to the cart, for the chips, by
     * term: quoted together, in parallel, within FeeQuote::PREVIEW_SECONDS,
     * reusing the quotes kept in the session. A term whose quote fails is
     * left out. Read-only on the session: before keeping its quotes it
     * reloads what is stored, so this request's write-back at shutdown can
     * never undo a term the buyer saved while the quotes were out.
     *
     * @return array<int, float>
     */
    public function previewFees(): array
    {
        $services = $this->services();
        $surcharge = $services->surcharge();
        $terms = $services->paymentTerms();
        $gross = $this->grossWithoutFee();
        if (!$surcharge->isKnown() || !$surcharge->enabled() || $gross <= 0) {
            return [];
        }
        $days = array_values(array_filter($terms->offered(), static fn (int $term): bool => $surcharge->pricesFee($term, $terms->defaultTerm(), $terms->isEndOfMonth())));
        $this->rememberQuotes();
        $quotes = $services->fees->quoteAll($surcharge, $terms, $days, $gross, $this->buyerCountry() ?? '', $this->sessionCurrency(), (string) $this->config->get('config_currency'), FeeQuote::PREVIEW_SECONDS);
        $this->session->start($this->session->getId());
        $this->keepQuotes();
        $fees = [];
        foreach ($quotes as $term => $quote) {
            if ($quote !== null && $quote['net'] > 0) {
                $fees[$term] = $quote['net'];
            }
        }

        return $fees;
    }

    /** Hands the fee quotes kept in the session (younger than FEE_CACHE_SECONDS) to FeeQuote. */
    public function rememberQuotes(): void
    {
        $known = [];
        foreach ((array) ($this->session->data['two_fee_quotes'] ?? []) as $hash => $quote) {
            if (!is_array($quote) || (int) ($quote['at'] ?? 0) <= time() - self::FEE_CACHE_SECONDS) {
                continue;
            }
            if (($quote['failed'] ?? false) === true) {
                $known[(string) $hash] = null;
            } elseif (is_numeric($quote['net'] ?? null) && is_string($quote['currency'] ?? null)) {
                $known[(string) $hash] = ['net' => (float) $quote['net'], 'currency' => $quote['currency']];
            }
        }
        $this->services()->fees->remember($known);
    }

    /**
     * Keeps the quotes Two gave in this request in the session, by request
     * hash, dropping expired ones. Only this key is written.
     */
    public function keepQuotes(): void
    {
        $fresh = $this->services()->fees->fresh();
        if ($fresh === []) {
            return;
        }
        $kept = array_filter((array) ($this->session->data['two_fee_quotes'] ?? []), static fn (mixed $quote): bool => is_array($quote) && (int) ($quote['at'] ?? 0) > time() - self::FEE_CACHE_SECONDS);
        foreach ($fresh as $hash => $quote) {
            $kept[$hash] = ($quote ?? ['failed' => true]) + ['at' => time()];
        }
        $this->session->data['two_fee_quotes'] = $kept;
    }

    /**
     * The fee-quote gate (Build Guide section 18): why the cart cannot be
     * offered Two on the fee's account, or null.
     *
     * @param array<string, mixed> $address
     */
    public function feeFailure(array $address = []): ?string
    {
        // An admin edit keeps the placed fee (feeTotal), so no quote can withhold Two there.
        if ($this->inAdminEdit()) {
            return null;
        }
        $services = $this->services();
        $currencyFailure = $services->fees->currencyFailure($services->surcharge(), $services->paymentTerms(), $this->sessionCurrency(), (string) $this->config->get('config_currency'));
        if ($currencyFailure !== null) {
            return $currencyFailure;
        }
        $fee = $this->cartFee($address);

        return $fee['applies'] && $fee['net'] === null ? $fee['failure'] : null;
    }

    /**
     * Placement's term and fee check (FeeQuote::placementRefusal) for the
     * session's order.
     *
     * @return array{reason: string, detail: string}|null
     */
    public function placementTermRefusal(int $orderId): ?array
    {
        $stored = 0.0;
        foreach ($this->db->query('SELECT `value` FROM `' . DB_PREFIX . "order_total` WHERE `order_id` = '" . $orderId . "' AND `code` = 'two_fee'")->rows as $row) {
            $stored += (float) $row['value'];
        }
        $fee = $this->cartFee();
        $cents = (int) round($stored * (float) $this->currency->getValue($this->sessionCurrency()) * 100);

        return FeeQuote::placementRefusal($this->services()->paymentTerms(), $this->chosenTerm(), $fee, $fee['failure'], $cents);
    }

    /**
     * The `terms` block for the session's order, or null when no term is offered.
     *
     * @return array{type: string, duration_days: int, duration_days_calculated_from?: string}|null
     */
    public function termsBlock(): ?array
    {
        $terms = $this->services()->paymentTerms();
        $days = $terms->selected($this->chosenTerm());

        return $days === null ? null : PaymentTerms::block($days, $terms->isEndOfMonth());
    }

    /**
     * The term the cart is priced on. An admin order edit runs in a fresh
     * API session that knows only the order id it posts, so there the term
     * is the one stored with the order (null when none is: the order was
     * placed before terms were kept). Otherwise the buyer's choice, if any.
     */
    public function chosenTerm(): ?int
    {
        if ($this->inAdminEdit()) {
            $orderId = $this->editedOrderId();
            $stored = $orderId > 0 ? PaymentTerms::decode((string) ($this->services()->orders->get($orderId)['payment_terms'] ?? '')) : null;

            return $stored['duration_days'] ?? null;
        }
        $chosen = (int) ($this->session->data['two_payment_term'] ?? 0);

        return $chosen > 0 ? $chosen : null;
    }

    /** Whether the shop runs the payment terms fee order total: installed and enabled. */
    public function feeTotalEnabled(): bool
    {
        return (bool) $this->config->get('total_two_fee_status')
            && $this->db->query('SELECT `extension_id` FROM `' . DB_PREFIX . "extension` WHERE `type` = 'total' AND `code` = 'two_fee'")->num_rows > 0;
    }

    /** An admin order edit: OpenCart's admin runs the catalog's api/ routes in a fresh session. */
    private function inAdminEdit(): bool
    {
        return str_starts_with((string) ($this->request->get['route'] ?? ''), 'api/');
    }

    private function editedOrderId(): int
    {
        return (int) ($this->request->post['order_id'] ?? $this->request->get['order_id'] ?? 0);
    }

    /** The checkout's own requests (and an admin edit), where a fee may be asked of Two. */
    private function inCheckout(): bool
    {
        $route = (string) ($this->request->get['route'] ?? '');

        return str_starts_with($route, 'checkout/') || str_starts_with($route, 'extension/two/') || str_starts_with($route, 'api/');
    }

    /**
     * Why a rebuilt order (a confirm or an edit sync, on its stored term)
     * may not be sent, or null: it was placed with a payment terms fee and no
     * longer carries one. Judged against what the order was placed with,
     * never the current surcharge setting.
     *
     * @param array<int, array{code: string}> $totals
     */
    private function feeRefusal(int $orderId, array $totals): ?Message
    {
        if (($this->services()->orders->get($orderId)['payment_fee'] ?? '') !== '1' || in_array('two_fee', array_column($totals, 'code'), true)) {
            return null;
        }

        return Message::of('refusal_fee_missing');
    }

    /** The cart's gross without the fee, in the session currency at the shop's own currency value (the factor the order is sent at). */
    public function grossWithoutFee(): float
    {
        [$total] = $this->totalsWithoutFee();

        return round($total * (float) $this->currency->getValue($this->sessionCurrency()), 2);
    }

    public function sessionCurrency(): string
    {
        return (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
    }

    /**
     * The cart's total and taxes as OpenCart computes them, without the fee.
     *
     * @return array{0: float, 1: array<int, float>}
     */
    private function totalsWithoutFee(): array
    {
        $this->load->model('checkout/cart');
        $totals = [];
        $taxes = [];
        $total = 0;
        $suspended = self::$feeSuspended;
        self::$feeSuspended = true;
        try {
            ($this->model_checkout_cart->getTotals)($totals, $taxes, $total);
        } finally {
            self::$feeSuspended = $suspended;
        }

        return [(float) $total, $taxes];
    }

    private function services(): Services
    {
        return $this->services ??= new Services($this->db, $this->log, (int) $this->config->get('config_store_id'));
    }

    /** The address columns the order keeps for each role, hashed as stored. */
    private const ADDRESS_FIELDS = ['firstname', 'lastname', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country_id', 'zone_id'];

    /**
     * Not yet placed: status 0, or the shop's void status, which OpenCart
     * 4.1.0.0 sets on every checkout edit of an unplaced order.
     *
     * @param array<string, mixed> $order
     */
    public function unplaced(array $order): bool
    {
        $status = (int) $order['order_status_id'];

        return $status === 0 || $status === (int) $this->config->get('config_void_status_id');
    }

    /**
     * Places an unplaced order at the status given, with no comment (OpenCart
     * puts the placing comment in the buyer's order confirmation email, and
     * the reference plugin places its order with none), then the merchant's
     * note as its own history line. addHistory runs the order events and the
     * order mail, as for any order placed on the storefront.
     */
    public function place(int $orderId, int $statusId, Message $message): void
    {
        $this->load->model('checkout/order');
        $this->model_checkout_order->addHistory($orderId, $statusId);
        $placed = $this->model_checkout_order->getOrder($orderId);
        $this->historyNote($orderId, (int) ($placed['order_status_id'] ?? $statusId), $message);
    }

    /**
     * A line in the order history for the merchant, in the shop's language
     * whatever the buyer's, never notified. Written directly: the status does
     * not change, so no order event or mail is due.
     */
    public function historyNote(int $orderId, int $statusId, Message $message): void
    {
        $this->load->language('extension/two/payment/two');
        $text = $this->language->get('text_history_prefix') . NoteText::render($message, $this->load, $this->language, NoteText::shopCode($this->config));
        $this->db->query('INSERT INTO `' . DB_PREFIX . "order_history` SET `order_id` = '" . $orderId . "', `order_status_id` = '" . $statusId . "', `notify` = '0', `comment` = '"
            . $this->db->escape($text) . "', `date_added` = NOW()");
    }

    /**
     * The order's own rows, as stored: its products (price, tax and
     * quantity), its totals, its billing and shipping addresses, and its
     * currency code (judged apart, not hashed with the rows). Nothing
     * from the catalogue or the tax setup, so only an edit of the order
     * itself changes them. Null when there is no such order.
     *
     * @return array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>, currency?: string}|null
     */
    public function orderRows(int $orderId): ?array
    {
        $order = $this->db->query('SELECT * FROM `' . DB_PREFIX . "order` WHERE `order_id` = '" . $orderId . "'")->row;
        if (!$order) {
            return null;
        }
        $addresses = [];
        foreach (['payment', 'shipping'] as $role) {
            foreach (self::ADDRESS_FIELDS as $field) {
                $addresses[$role . '_' . $field] = $order[$role . '_' . $field] ?? '';
            }
        }

        return [
            'products' => array_values($this->db->query('SELECT `product_id`, `name`, `model`, `quantity`, `price`, `total`, `tax` FROM `' . DB_PREFIX . "order_product` WHERE `order_id` = '" . $orderId . "'")->rows),
            'totals' => array_values($this->db->query('SELECT `code`, `title`, `value` FROM `' . DB_PREFIX . "order_total` WHERE `order_id` = '" . $orderId . "'")->rows),
            'addresses' => $addresses,
            // Not hashed with the rows: the edit sync and the fulfil check judge a currency change on their own.
            'currency' => (string) $order['currency_code'],
        ];
    }

    /**
     * The stored order as OrderBuilder's inputs, or null when there is no
     * such order.
     *
     * @param array{company_name: string, organization_number: string, country_code: string} $company
     * @param array<string, string>                                                           $urls
     * @param array<string, string>                                                           $buyerFields the optional buyer fields (BuyerFields)
     * @param array<string, mixed>|null                                                       $terms the payment terms block the order is booked on: null for the session's, empty for none (an order placed before terms were kept)
     * @return array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context: array<string, mixed>}|null
     */
    public function orderInput(int $orderId, array $company, array $urls, array $buyerFields = [], ?array $terms = null): ?array
    {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($orderId);
        if (!$order) {
            return null;
        }
        $tax = OrderTax::engine($this->db, OrderTax::scope($order, OrderTax::storeSettings($this->db, (int) $order['store_id'])));

        $lines = [];
        foreach ($this->model_checkout_order->getProducts($orderId) as $row) {
            $product = $this->db->query('SELECT `tax_class_id`, `shipping` FROM `' . DB_PREFIX . "product` WHERE `product_id` = '" . (int) $row['product_id'] . "'")->row;
            $lines[] = [
                'name' => (string) $row['name'],
                'quantity' => (int) $row['quantity'],
                'price' => (float) $row['price'],
                'total' => (float) $row['total'],
                'tax' => (float) $row['tax'],
                'type' => !isset($product['shipping']) || $product['shipping'] ? 'PHYSICAL' : 'DIGITAL',
                'tax_key' => OrderTax::codeKey($tax, (float) $row['price'], (int) ($product['tax_class_id'] ?? 0)),
            ] + OrderTax::rateOf($tax, (float) $row['price'], (int) ($product['tax_class_id'] ?? 0));
        }

        $record = $this->services()->orders->get($orderId) ?? [];
        $taxTotal = 0.0;
        $totals = [];
        foreach ($this->model_checkout_order->getTotals($orderId) as $row) {
            $total = ['code' => (string) $row['code'], 'title' => (string) $row['title'], 'value' => (float) $row['value']];
            if ($total['code'] === 'tax') {
                $taxTotal += $total['value'];
            }
            $class = $this->chargeTaxClass($total['code'], $order);
            $placed = $total['code'] === 'two_fee' && $terms !== null ? FeeQuote::decodePlaced((string) ($record['placed_fee'] ?? ''), (float) $order['currency_value'], (string) $order['currency_code']) : null;
            if ($placed !== null) {
                // A rebuilt order's fee is the fee as placed: its stored tax and rate, never recomputed.
                $total += ['tax' => $placed['tax'], 'rate' => $placed['rate'], 'tax_rates' => $placed['tax_rates']];
            } elseif ($class !== null) {
                $rate = OrderTax::rateOf($tax, $total['value'], $class);
                $total += $rate + ['tax' => $class > 0 ? $tax->getTax($total['value'], $class) : 0.0];
                if ($total['code'] === 'two_fee') {
                    $total['tax_rates'] = [];
                    foreach ($class > 0 ? $tax->getRates($total['value'], $class) : [] as $id => $row) {
                        $total['tax_rates'][(int) ($row['tax_rate_id'] ?? $id)] = ['amount' => (float) $row['amount'], 'name' => (string) ($row['name'] ?? '')];
                    }
                }
            }
            if ($class !== null) {
                $total['tax_key'] = OrderTax::codeKey($tax, $total['value'], $class);
            }
            $totals[] = $total;
        }

        $shipping = $order['shipping_method'] ? $this->address($order, 'shipping') : null;
        $method = is_array($order['shipping_method']) ? $order['shipping_method'] : [];
        // Section 21.3: what the shop's shipping tax setting (here the shipping method's
        // tax class) applies at the order's addresses, whether or not the line was taxed.
        $shippingClass = (int) ($method['tax_class_id'] ?? 0);
        $shippingRate = $shippingClass > 0 ? OrderTax::rateOf($tax, (float) ($method['cost'] ?? 0), $shippingClass)['rate'] : null;

        // Section 22: the merchant's tax code rows, and the buyer's VAT number for step 1 and the create.
        $billing = $this->address($order, 'payment');
        $taxAddress = $shipping ?? $billing;
        $merchantCountry = TaxCodes::merchantCountry($this->services()->records->record());
        $vatNumber = $this->buyerVatNumber($order);
        $taxCodes = [
            'map' => $this->services()->settings->store()->get(TaxCodes::MAP_KEY),
            'exempt' => TaxCodes::isExemptBuyer(
                ['country' => $billing['country'], 'postcode' => $billing['postal_code']],
                ['country' => $taxAddress['country'], 'postcode' => $taxAddress['postal_code']],
                $vatNumber,
                $merchantCountry
            ),
        ];

        return [
            'order' => [
                'order_id' => $orderId,
                'currency' => (string) $order['currency_code'],
                'currency_value' => (float) $order['currency_value'],
                'total' => (float) $order['total'],
                'tax_total' => $taxTotal,
                'date_added' => (string) $order['date_added'],
                'comment' => (string) $order['comment'],
                'carrier_name' => (string) ($method['name'] ?? ''),
                'company' => $company,
                'buyer' => [
                    'email' => (string) $order['email'],
                    'first_name' => (string) $order['firstname'],
                    'last_name' => (string) $order['lastname'],
                    'phone_number' => (string) $order['telephone'],
                ],
                'billing_address' => $billing,
                'shipping_address' => $shipping,
                'merchant_urls' => $urls,
                'buyer_fields' => $buyerFields,
                'terms' => $terms ?? $this->termsBlock(),
                'fee_refusal' => $terms === null ? null : $this->feeRefusal($orderId, $totals),
                'currency_refusal' => $terms === null ? null : FeeQuote::currencyChange($record, (string) $order['currency_code']),
                'tax_codes' => $taxCodes,
                'buyer_vat_number' => TaxCodes::buyerVatNumber($vatNumber, $merchantCountry, (string) $company['country_code']),
            ],
            'lines' => $lines,
            'totals' => $totals,
            'context' => ['order' => $order, 'shipping_tax_rate' => $shippingRate],
        ];
    }

    /**
     * The buyer's VAT number (section 22.4): the custom field the merchant
     * named on the Order management tab, from the order's payment address,
     * else from the customer's account, as entered and trimmed. '' when no
     * field is named or it holds nothing.
     *
     * @param array<string, mixed> $order
     */
    private function buyerVatNumber(array $order): string
    {
        $field = (string) $this->services()->settings->store()->get(TaxCodes::VAT_FIELD_KEY);
        if ($field === '') {
            return '';
        }
        foreach (['payment_custom_field', 'custom_field'] as $source) {
            $values = is_array($order[$source] ?? null) ? $order[$source] : [];
            if (is_scalar($values[$field] ?? null) && trim((string) $values[$field]) !== '') {
                return trim((string) $values[$field]);
            }
        }

        return '';
    }

    /**
     * The tax class of a charge total, or null for a total that is not one.
     *
     * @param array<string, mixed> $order
     */
    private function chargeTaxClass(string $code, array $order): ?int
    {
        if ($code === 'shipping') {
            return (int) (is_array($order['shipping_method']) ? ($order['shipping_method']['tax_class_id'] ?? 0) : 0);
        }
        if (isset(self::CHARGE_TAX_CLASS_KEYS[$code])) {
            return (int) $this->config->get(self::CHARGE_TAX_CLASS_KEYS[$code]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    private function address(array $order, string $role): array
    {
        $country = $this->db->query('SELECT `iso_code_2` FROM `' . DB_PREFIX . "country` WHERE `country_id` = '" . (int) $order[$role . '_country_id'] . "'")->row;

        return [
            'street_address' => trim($order[$role . '_address_1'] . ' ' . $order[$role . '_address_2']),
            'postal_code' => (string) $order[$role . '_postcode'],
            'city' => (string) $order[$role . '_city'],
            'region' => (string) $order[$role . '_zone'],
            'country' => (string) ($country['iso_code_2'] ?? ''),
        ];
    }
}
