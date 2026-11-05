<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Event;

use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\ProductPage;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * The product page message and buy button (Build Guide section 20), added to
 * the rendered product page by the `catalog/view/product/product/after` event
 * (`Services::PRODUCT_EVENTS`), at the end of OpenCart's own add to cart form.
 * No core file is edited.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\Url $url
 */
class TwoProduct extends \Opencart\System\Engine\Controller
{
    public const SCRIPT = 'extension/two/catalog/view/javascript/two_product_button.js';

    public const STYLESHEET = 'extension/two/catalog/view/stylesheet/two_product.css';

    /** Logged once per request at most, so a page of products writes one line. */
    private static bool $unplacedLogged = false;

    private static bool $switchLogged = false;

    /** @param array<int, mixed> $args the view's data */
    public function index(string &$route = '', array &$args = [], mixed &$output = null): void
    {
        if ($route !== 'product/product' || !is_string($output)) {
            return;
        }
        // A promotional line is never worth the product page: anything
        // unexpected costs the fragment and is logged, never the page.
        try {
            $fragment = $this->fragment();
            if ($fragment === '') {
                return;
            }
            $placed = ProductPage::place($output, $fragment);
            if ($placed === null) {
                if (!self::$unplacedLogged) {
                    self::$unplacedLogged = true;
                    $this->log->write('Two: product page message and button withheld: the theme has no add to cart form (id="form-product")');
                }

                return;
            }
            $output = $placed;
        } catch (\Throwable $e) {
            $this->log->write('Two: product page message and button withheld: ' . $e->getMessage());
        }
    }

    private function fragment(): string
    {
        $methodEnabled = (bool) $this->config->get('payment_two_status');
        if (!$methodEnabled) {
            return '';
        }
        $services = new Services($this->db, $this->log, (int) $this->config->get('config_store_id'));
        $page = new ProductPage($services->settings, $services->verdict);
        $this->logUnrecognisedSwitches($services);
        $brand = Brand::default()->productName;
        $showsMessage = $page->showsMessage($methodEnabled);
        $showsButton = $page->showsButton($methodEnabled, $brand);
        if (!$showsMessage && !$showsButton) {
            return '';
        }

        // Prefixed, and read back with get(): load->language() answers with en-gb's copy.
        $this->load->language('extension/two/payment/two', 'two_product');
        $language = (string) $this->config->get('config_language');
        $version = Services::pluginVersion();
        $parts = ['brand' => $brand, 'stylesheet' => TwoCheckout::versioned(self::STYLESHEET, $version)];
        if ($showsMessage) {
            $parts['message'] = $page->message((int) $this->config->get('config_language_id'), $this->language->get('two_product_text_about_strong'));
        }
        if ($showsButton) {
            $parts['button'] = [
                'label' => sprintf($this->language->get('two_product_text_product_button'), $brand),
                'add_url' => $this->url->link('checkout/cart.add', 'language=' . $language, true),
                'checkout_url' => $this->url->link('checkout/checkout', 'language=' . $language, true),
                'unreachable' => $this->language->get('two_product_error_product_button_unreachable'),
                'script' => TwoCheckout::versioned(self::SCRIPT, $version),
            ];
        }

        return ProductPage::render($parts);
    }

    /**
     * A switch stored as anything but 0 or 1 (an import, a hand edit) reads
     * as off; say so once per request, so the merchant whose message or
     * button vanished has something to read.
     */
    private function logUnrecognisedSwitches(Services $services): void
    {
        if (self::$switchLogged) {
            return;
        }
        foreach (['product_message_enabled', 'product_button_enabled'] as $key) {
            $stored = $services->settings->store()->get($key);
            if ($stored !== null && !in_array($stored, ['', '0', '1'], true)) {
                self::$switchLogged = true;
                $this->log->write('Two: unrecognised ' . $key . ' value "' . $stored . '"; read as off');
            }
        }
    }
}
