<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Event;

use Opencart\System\Library\Extension\Two\Core\CompanyCapture;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Puts the checkout capture script on the checkout page: its settings as a
 * JSON island and the script itself, just before the body closes.
 * Registered on `catalog/view/checkout/checkout/after`
 * (Services::CHECKOUT_EVENT_TRIGGER). Nothing is added while Two is switched
 * off or has no API key; whether Two is offered for the cart is the method
 * list's question, and the script only acts on the panels it finds.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Cart\Customer $customer
 * @property-read \Opencart\Catalog\Model\Account\Address $model_account_address
 */
class TwoCheckout extends \Opencart\System\Engine\Controller
{
    public const SCRIPT = 'extension/two/catalog/view/javascript/two_checkout.js';

    /**
     * The shared company search, loaded before the checkout script: the four
     * vendored modules (byte for byte the sibling plugins', checked in CI),
     * then this extension's host for them.
     */
    public const COMPANY_SEARCH = ['company-identity', 'company-search-panel', 'sole-trader', 'company-capture-component', 'company-search-host'];

    public const STYLESHEET = 'extension/two/catalog/view/stylesheet/two_checkout.css';

    /** The product page buy button's hand-off: checks Two in the payment method list (section 20.8). */
    public const PRESELECT = 'extension/two/catalog/view/javascript/two_preselect.js';

    /** @param array<int, mixed> $args the view's data */
    public function index(string &$route = '', array &$args = [], mixed &$output = null): void
    {
        $pages = ['checkout/checkout' => null, 'account/address_form' => ['account']];
        if (!array_key_exists($route, $pages) || !is_string($output) || !$this->config->get('payment_two_status')) {
            return;
        }
        if ((new Services($this->db, $this->log, (int) $this->config->get('config_store_id')))->settings->apiKey() === '') {
            return;
        }
        $config = $this->load->controller('extension/two/payment/two.scriptConfig');
        if (!is_array($config)) {
            return;
        }
        if ($pages[$route] !== null) {
            // The address edit form: one control on its own company field, in
            // address placement only (the tile placement leaves it plain).
            if (!$config['in_address']) {
                return;
            }
            $config['roles'] = $pages[$route];
            // Seeded from the edited address's own capture, or from nothing:
            // never from a capture made on another address this session.
            unset($this->session->data['two_checkout']['companies']['account']);
            $config['companies'] = ['account' => $this->editedAddressCapture()];
            if ($config['companies']['account'] === null) {
                $config['companies'] = (object) [];
            }
        }
        $version = Services::pluginVersion();
        // JSON_HEX_* so no value can close the script element or open markup.
        $island = '<link rel="stylesheet" href="' . self::versioned(self::STYLESHEET, $version) . '">'
            . '<script type="application/json" id="two-checkout-config">'
            . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)
            . '</script>';
        foreach (self::COMPANY_SEARCH as $module) {
            $island .= '<script src="' . self::versioned('extension/two/catalog/view/javascript/two/' . $module . '.js', $version) . '"></script>';
        }
        $island .= '<script src="' . self::versioned(self::SCRIPT, $version) . '"></script>';
        if ($pages[$route] === null) {
            $island .= '<script src="' . self::versioned(self::PRESELECT, $version) . '"></script>';
        }
        $at = strripos($output, '</body>');
        $output = $at === false ? $output . $island : substr($output, 0, $at) . $island . substr($output, $at);
    }

    /**
     * An asset's URL, versioned by the plugin version and the file's own
     * modification time, so a changed file gets a new URL even when the
     * version stays the same (a shop that tracks a branch) and no cache in
     * front of the shop keeps serving the old copy. is_file() first, as
     * OpenCart's error handler acts on a warning even under `@`.
     */
    public static function versioned(string $path, string $version): string
    {
        $file = DIR_EXTENSION . substr($path, strlen('extension/'));
        $modified = is_file($file) ? filemtime($file) : false;

        return $path . '?v=' . rawurlencode($version . ($modified === false ? '' : '.' . $modified));
    }

    /**
     * The capture the address on OpenCart's edit form keeps; null for a new
     * address, a guest, or an address that is not the customer's.
     *
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    private function editedAddressCapture(): ?array
    {
        $addressId = (int) ($this->request->get['address_id'] ?? 0);
        if ($addressId <= 0 || !$this->customer->isLogged()) {
            return null;
        }
        $this->load->model('account/address');
        $row = $this->model_account_address->getAddress((int) $this->customer->getId(), $addressId);

        return CompanyCapture::ofAddress($row);
    }

    /**
     * Before OpenCart saves a customer's address: keep the company the buyer
     * captured for it in the address's own `custom_field` (`two_company`), so
     * a later checkout on the saved address reuses it. The capture is the
     * session's for the form being saved, and only while its name is still
     * the address's company; an edit with no fresh capture keeps the stored
     * one on the same terms.
     *
     * @param array<int, mixed> $args addAddress(customer_id, data) or editAddress(customer_id, address_id, data)
     */
    public function address(string &$route, array &$args = []): void
    {
        $edit = str_ends_with($route, 'editAddress');
        $at = $edit ? 2 : 1;
        if (!is_array($args[$at] ?? null)) {
            return;
        }
        $customerId = (int) ($args[0] ?? 0);
        $stored = [];
        if ($edit) {
            $this->load->model('account/address');
            // Scoped to the customer by OpenCart, so another customer's address reads as none.
            $row = $this->model_account_address->getAddress($customerId, (int) $args[1]);
            $stored = $row === [] ? [] : ['customer_id' => $customerId] + $row;
        }
        $routeRole = ['checkout/payment_address.save' => 'payment', 'checkout/shipping_address.save' => 'shipping', 'account/address.save' => 'account'][(string) ($this->request->get['route'] ?? '')] ?? '';
        $roles = array_values(array_unique(array_filter([$routeRole, 'payment', 'shipping', 'account'])));
        $data = $args[$at];
        $data['custom_field'] = CompanyCapture::keep(
            is_array($data['custom_field'] ?? null) ? $data['custom_field'] : [],
            (string) ($data['company'] ?? ''),
            (array) ($this->session->data['two_checkout']['companies'] ?? []),
            $roles,
            $stored,
            $customerId,
        );
        $args[$at] = $data;
    }
}
