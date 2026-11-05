<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The product page promotional message (Build Guide section 20, TWO-25799)
 * and the "Buy with" button (section 20.8, TWO-25800).
 *
 * The message says the method exists; it never says this buyer will be
 * offered it, because the minimum order, the buyer country and the currency
 * all need a cart, and a product page has none. The button adds the item with
 * the shop's own add to cart and hands the buyer to checkout with the method
 * preselected where it is offered; it never places an order.
 *
 * Each renders only when its own switch is on (strictly "1", off by default),
 * the method is enabled, and the API key is not definitively rejected. A
 * transient outage does not withhold either, as it does not withhold the
 * method. The verdict is read cache-only: this runs on the busiest page in
 * the shop and never calls Two.
 */
final class ProductPage
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly ApiKeyVerdict $verdict,
    ) {
    }

    /** Whether the promotional message renders. */
    public function showsMessage(bool $methodEnabled): bool
    {
        return $methodEnabled && $this->settings->productMessageEnabled() && !$this->verdict->isDefinitelyUnusable();
    }

    /**
     * Whether the buy button renders. A button that names no brand would ask
     * the buyer to commit to a method it does not name, so a blank brand name
     * withholds it.
     */
    public function showsButton(bool $methodEnabled, string $brandName): bool
    {
        return $methodEnabled
            && self::trimmed($brandName) !== ''
            && $this->settings->productButtonEnabled()
            && !$this->verdict->isDefinitelyUnusable();
    }

    /**
     * The merchant's wording in one language, else the default (the checkout
     * tile's own sentence, so the two surfaces cannot drift).
     */
    public function message(int $languageId, string $default): string
    {
        $configured = $this->settings->productMessage($languageId);

        return $configured !== '' ? $configured : self::trimmed($default);
    }

    /**
     * Trims Unicode whitespace as well as ASCII: trim() leaves U+00A0, so an
     * override of nonbreaking spaces would otherwise render an empty badge.
     * Text that is not valid UTF-8 reads as empty.
     */
    public static function trimmed(string $text): string
    {
        $trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);

        return $trimmed ?? '';
    }

    /**
     * The fragment the product page carries: the message, the button, or
     * both, each escaped. The brand name is always text, since OpenCart ships
     * no brand mark and an unattributed offer is the failure this feature
     * invites (section 20.4).
     *
     * @param array{brand: string, message?: string, button?: array{label: string, add_url: string, checkout_url: string, unreachable: string, script: string}, stylesheet: string} $parts
     */
    public static function render(array $parts): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '';
        if (($parts['message'] ?? '') !== '') {
            $html .= '<div class="two-product-promo">'
                . '<span class="two-product-promo__brand">' . $e($parts['brand']) . '</span> '
                . '<span class="two-product-promo__text">' . $e($parts['message']) . '</span>'
                . '</div>';
        }
        if (isset($parts['button'])) {
            $button = $parts['button'];
            $html .= '<div class="two-product-button">'
                . '<button type="button" class="btn btn-lg two-product-button__cta" data-two-buy-now'
                . ' data-two-add-url="' . $e($button['add_url']) . '"'
                . ' data-two-checkout-url="' . $e($button['checkout_url']) . '"'
                . ' data-two-unreachable="' . $e($button['unreachable']) . '">'
                . '<span class="two-product-button__label">' . $e($button['label']) . '</span>'
                . '</button>'
                . '<p class="two-product-button__error" role="alert" aria-live="polite"></p>'
                . '</div>'
                . '<script src="' . $e($button['script']) . '"></script>';
        }
        if ($html === '') {
            return '';
        }

        return '<link rel="stylesheet" href="' . $e($parts['stylesheet']) . '">'
            . '<div class="two-product">' . $html . '</div>';
    }

    /**
     * Puts the fragment at the end of the product's add to cart form
     * (`id="form-product"`, OpenCart's own), under the add to cart button.
     * Null when the page has no such form: a theme that replaced it is left
     * alone rather than guessed at.
     */
    public static function place(string $page, string $fragment): ?string
    {
        if (preg_match('/<form\b[^>]*\bid\s*=\s*["\']form-product["\'][^>]*>/i', $page, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $close = stripos($page, '</form>', $match[0][1] + strlen($match[0][0]));
        if ($close === false) {
            return null;
        }

        return substr($page, 0, $close) . $fragment . substr($page, $close);
    }
}
