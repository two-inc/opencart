<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Availability;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CompanyLookup;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\FeeQuote;
use Opencart\System\Library\Extension\Two\Core\FxRates;
use Opencart\System\Library\Extension\Two\Core\MerchantFeeRates;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\OrderEdit;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\OrderRefunds;
use Opencart\System\Library\Extension\Two\Core\PaymentTerms;
use Opencart\System\Library\Extension\Two\Core\PendingCheck;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;
use Opencart\System\Library\Extension\Two\Core\TaxCodeList;
use Opencart\System\Library\Extension\Two\Core\Surcharge;

/**
 * Wires the core for one OpenCart request: the shop's settings table, its
 * error log, cURL, the plugin version and the TWO_INTERNAL_ENVIRONMENTS flag.
 * The only place the adapter builds core objects, so every controller,
 * model and cron entry gets the same graph.
 */
final class Services
{
    /** The scheduled refresh's row in OpenCart's cron table. */
    public const CRON_CODE = 'two_record_refresh';
    public const CRON_ACTION = 'extension/two/cron/two';
    public const CRON_CYCLE = 'day';
    public const CRON_DESCRIPTION = 'Refreshes the cached Two merchant record.';

    /**
     * The pending check's row (TWO-26300): hourly, so a shop that runs only
     * OpenCart's own cron still recovers a verified order within the hour.
     * The token URL, scheduled every 15 minutes, runs the same check.
     */
    public const PENDING_CRON_CODE = 'two_pending_check';
    public const PENDING_CRON_CYCLE = 'hour';
    public const PENDING_CRON_DESCRIPTION = 'Checks Two orders the buyer verified but never returned from, and places them.';

    /**
     * The order lifecycle's row in OpenCart's event table. Admin status
     * changes run the catalog's order model in a store instance, so this one
     * catalog event sees every status change: admin, storefront and the
     * extension's own.
     */
    public const EVENT_CODE = 'two_order_lifecycle';
    public const EVENT_TRIGGER = 'catalog/model/checkout/order.addHistory/after';
    public const EVENT_ACTION = 'extension/two/event/two.history';
    public const EVENT_DESCRIPTION = 'Sends order edits to Two, and fulfils, cancels or refunds the Two order when an order moves into a mapped status.';

    /**
     * The checkout capture's row in OpenCart's event table: puts the
     * checkout script and its settings on the checkout page.
     */
    public const CHECKOUT_EVENT_CODE = 'two_checkout_capture';
    public const CHECKOUT_EVENT_TRIGGER = 'catalog/view/checkout/checkout/after';
    public const CHECKOUT_EVENT_ACTION = 'extension/two/event/two_checkout';
    public const CHECKOUT_EVENT_DESCRIPTION = 'Adds the Two company search and order intent script to the checkout page.';

    /**
     * The saved-address capture (TWO-26721): the company search on OpenCart's
     * own address edit form, and the company a buyer captured kept in the
     * saved address's `custom_field`, so a later checkout on that address
     * reuses it. Code => [trigger, action, description].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const ADDRESS_EVENTS = [
        'two_address_form' => ['catalog/view/account/address_form/after', 'extension/two/event/two_checkout', 'Adds the Two company search to the address edit form.'],
        'two_address_add' => ['catalog/model/account/address.addAddress/before', 'extension/two/event/two_checkout.address', 'Keeps the company captured by the Two company search with a new saved address.'],
        'two_address_edit' => ['catalog/model/account/address.editAddress/before', 'extension/two/event/two_checkout.address', 'Keeps the company captured by the Two company search with an edited saved address.'],
        // The admin's customer address form posts only the configured custom
        // fields, and OpenCart stores exactly what it posts.
        'two_address_admin_edit' => ['admin/model/customer/customer.editAddress/before', 'extension/two/event/two_address', 'Keeps the company captured by the Two company search when the admin edits a saved address.'],
    ];

    /**
     * The product page message and buy button (Build Guide section 20): added
     * to the rendered product page, at the end of its add to cart form.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const PRODUCT_EVENTS = [
        'two_product_page' => ['catalog/view/product/product/after', 'extension/two/event/two_product', 'Adds the Two product page message and buy button.'],
    ];

    /** The payment terms fee order total, and its default sort order: before core's Taxes total (5), so its tax is listed and charged. */
    public const FEE_TOTAL_CODE = 'two_fee';
    public const FEE_TOTAL_SORT_ORDER = '4';

    public readonly SettingsProvider $settings;
    public readonly Environment $environment;
    public readonly Client $client;
    public readonly ApiKeyVerdict $verdict;
    public readonly RecordProvider $records;
    public readonly SettingsSave $save;
    public readonly DbOrderRecords $orders;
    public readonly Availability $availability;
    public readonly CompanyLookup $companies;
    public readonly DbRefundRecords $refunds;
    public readonly FxRates $fx;
    public readonly FeeQuote $fees;
    /** The merchant's own fee per term, for the Payment terms tab. */
    public readonly MerchantFeeRates $feeRates;
    public readonly TaxCodeList $taxCodes;
    private readonly LogLogger $logger;

    public function __construct(private readonly \Opencart\System\Library\DB $db, \Opencart\System\Library\Log $log, int $storeId = 0)
    {
        $this->settings = new SettingsProvider(new DbSettingsStore($db, $storeId));
        $this->environment = new Environment(self::internalEnvironmentsEnabled());
        $logger = $this->logger = new LogLogger($log, $this->settings->debugLogging());
        $this->client = new Client($this->environment, $this->settings, new CustomHeaders(), new CurlTransport(), $logger, self::pluginVersion());
        $this->verdict = new ApiKeyVerdict($this->client, $this->settings);
        $this->records = new RecordProvider($this->client, $this->settings, $this->verdict, $logger);
        $this->save = new SettingsSave($this->settings, $this->environment, $this->verdict, $this->records);
        $this->orders = new DbOrderRecords($db);
        $this->availability = new Availability($this->settings, $this->records, $this->verdict);
        $this->companies = new CompanyLookup($this->client, $this->environment, $this->settings);
        $this->refunds = new DbRefundRecords($db);
        $this->fx = new FxRates($this->client, $this->settings, $logger);
        $this->fees = new FeeQuote($this->client, $this->fx, $logger);
        $this->feeRates = new MerchantFeeRates($this->client, $this->settings, $logger);
        $this->taxCodes = new TaxCodeList($this->client, $this->settings->store(), $logger);
    }

    /** The payment terms offered now: the merchant record's, narrowed by the merchant's settings. */
    public function paymentTerms(): PaymentTerms
    {
        return PaymentTerms::from($this->records->record(), $this->settings);
    }

    public function surcharge(): Surcharge
    {
        return Surcharge::from($this->settings);
    }

    /**
     * The hosted checkout flow. Every order request it sends passes through
     * the shop's order postprocessing event first.
     */
    public function orderFlow(\Opencart\System\Engine\Event $event): OrderFlow
    {
        $postprocessing = new Postprocessing(new EventSubscribers($this->db, $event), $this->logger, $this->settings->taxSubtotals());

        return new OrderFlow($this->client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $this->orders, $this->logger);
    }

    /** The pending check: the schedule's and the admin button's way to the checkout flow's resolver. */
    public function pendingCheck(\Opencart\System\Engine\Event $event): PendingCheck
    {
        return new PendingCheck($this->orderFlow($event), $this->orders, $this->logger);
    }

    /** Fulfil, cancel and the invoice download, sharing the checkout flow's Two reads and snapshot check. */
    public function orderLifecycle(\Opencart\System\Engine\Event $event): OrderLifecycle
    {
        $postprocessing = new Postprocessing(new EventSubscribers($this->db, $event), $this->logger, $this->settings->taxSubtotals());
        $flow = new OrderFlow($this->client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $this->orders, $this->logger);

        return new OrderLifecycle($flow, $this->client, $postprocessing, new ErrorMapper(), $this->orders, $this->logger);
    }

    /** Refunds and the credit note. */
    public function orderRefunds(\Opencart\System\Engine\Event $event): OrderRefunds
    {
        return new OrderRefunds($this->client, new Postprocessing(new EventSubscribers($this->db, $event), $this->logger, $this->settings->taxSubtotals()), new ErrorMapper(), $this->orders, $this->refunds, $this->logger);
    }

    /** Order-edit sync, sharing the checkout flow's rebuild and Two reads. */
    public function orderEdit(\Opencart\System\Engine\Event $event): OrderEdit
    {
        $postprocessing = new Postprocessing(new EventSubscribers($this->db, $event), $this->logger, $this->settings->taxSubtotals());
        $flow = new OrderFlow($this->client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $this->orders, $this->logger);

        return new OrderEdit($flow, $this->client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $this->orders, $this->logger);
    }

    public static function internalEnvironmentsEnabled(): bool
    {
        $raw = getenv('TWO_INTERNAL_ENVIRONMENTS');

        return Environment::flagEnabled($raw === false ? null : $raw);
    }

    /** The version in install.json, the single source bumpver writes. */
    public static function pluginVersion(): string
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/install.json'), true);

        return is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : '';
    }
}
