<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ProductPage;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The product page message and buy button (Build Guide section 20): the
 * gates, the wording read through the real settings provider, the escaped
 * fragment, and where it goes in the page.
 */
final class ProductPageSpec
{
    private const NOW = 1_800_000_000;

    /**
     * Each gate case: the stored settings, the cached key verdict (null for
     * none), whether the method is enabled, the brand name, and what the
     * message and the button gates answer.
     *
     * @var list<array{stored: array<string, string>, verdict: string|null, enabled: bool, brand: string, message: bool, button: bool, description: string}>
     */
    private const GATES = [
        ['stored' => ['product_message_enabled' => '1', 'product_button_enabled' => '1'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => 'Two', 'message' => true, 'button' => true, 'description' => 'both switched on, the method enabled and the key good'],
        ['stored' => [], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => 'Two', 'message' => false, 'button' => false, 'description' => 'off by default, so an upgrade never starts advertising'],
        ['stored' => ['product_message_enabled' => '0', 'product_button_enabled' => '0'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => 'Two', 'message' => false, 'button' => false, 'description' => 'switched off'],
        ['stored' => ['product_message_enabled' => '1'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => 'Two', 'message' => true, 'button' => false, 'description' => 'the message alone: the button has its own switch'],
        ['stored' => ['product_button_enabled' => '1'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => 'Two', 'message' => false, 'button' => true, 'description' => 'the button alone: the message has its own switch'],
        ['stored' => ['product_message_enabled' => 'yes', 'product_button_enabled' => 'on'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => 'Two', 'message' => false, 'button' => false, 'description' => 'a corrupt switch reads as off, never coerced on'],
        ['stored' => ['product_message_enabled' => ' 1', 'product_button_enabled' => '2'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => 'Two', 'message' => false, 'button' => false, 'description' => 'only exactly 1 is on'],
        ['stored' => ['product_message_enabled' => '1', 'product_button_enabled' => '1'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => false, 'brand' => 'Two', 'message' => false, 'button' => false, 'description' => 'the method disabled withholds both'],
        ['stored' => ['product_message_enabled' => '1', 'product_button_enabled' => '1'], 'verdict' => ApiKeyVerdict::INVALID, 'enabled' => true, 'brand' => 'Two', 'message' => false, 'button' => false, 'description' => 'a key Two rejected withholds both'],
        ['stored' => ['product_message_enabled' => '1', 'product_button_enabled' => '1', 'api_key' => ''], 'verdict' => null, 'enabled' => true, 'brand' => 'Two', 'message' => false, 'button' => false, 'description' => 'no key at all withholds both'],
        ['stored' => ['product_message_enabled' => '1', 'product_button_enabled' => '1'], 'verdict' => ApiKeyVerdict::UNREACHABLE, 'enabled' => true, 'brand' => 'Two', 'message' => true, 'button' => true, 'description' => 'an outage does not withhold, as it does not withhold the method'],
        ['stored' => ['product_message_enabled' => '1', 'product_button_enabled' => '1'], 'verdict' => ApiKeyVerdict::SERVICE_ERROR, 'enabled' => true, 'brand' => 'Two', 'message' => true, 'button' => true, 'description' => 'a 5xx does not withhold'],
        ['stored' => ['product_message_enabled' => '1', 'product_button_enabled' => '1'], 'verdict' => null, 'enabled' => true, 'brand' => 'Two', 'message' => true, 'button' => true, 'description' => 'a key not yet checked does not withhold, and the page never asks Two'],
        ['stored' => ['product_button_enabled' => '1'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => '', 'message' => false, 'button' => false, 'description' => 'a button naming no brand is withheld'],
        ['stored' => ['product_button_enabled' => '1'], 'verdict' => ApiKeyVerdict::OK, 'enabled' => true, 'brand' => "\u{a0} ", 'message' => false, 'button' => false, 'description' => 'a brand of nonbreaking spaces names no brand'],
    ];

    /**
     * The wording in one language: the stored override map, the language
     * asked for, the default copy, and the line shown.
     *
     * @var list<array{stored: string|null, language: int, default: string, expected: string, description: string}>
     */
    private const MESSAGES = [
        ['stored' => null, 'language' => 1, 'default' => 'Buy now, pay later.', 'expected' => 'Buy now, pay later.', 'description' => 'no override shows the default'],
        ['stored' => '{"1":"Pay by invoice","2":"Betal med faktura"}', 'language' => 2, 'default' => 'Buy now, pay later.', 'expected' => 'Betal med faktura', 'description' => 'the override for the language'],
        ['stored' => '{"1":"Pay by invoice"}', 'language' => 3, 'default' => 'Buy now, pay later.', 'expected' => 'Buy now, pay later.', 'description' => 'a language with no override shows the default, never another language\'s'],
        ['stored' => '{"1":"  Pay by invoice  "}', 'language' => 1, 'default' => 'x', 'expected' => 'Pay by invoice', 'description' => 'the override is trimmed of Unicode whitespace'],
        ['stored' => '{"1":"  "}', 'language' => 1, 'default' => 'Buy now, pay later.', 'expected' => 'Buy now, pay later.', 'description' => 'nonbreaking spaces are empty, not a blank badge'],
        ['stored' => '{"1":" 　"}', 'language' => 1, 'default' => 'Buy now, pay later.', 'expected' => 'Buy now, pay later.', 'description' => 'other Unicode spaces are empty too'],
        ['stored' => '{"1":["Pay by invoice"]}', 'language' => 1, 'default' => 'Buy now, pay later.', 'expected' => 'Buy now, pay later.', 'description' => 'a non-scalar override reads as absent, never cast'],
        ['stored' => '{"1":{"text":"x"}}', 'language' => 1, 'default' => 'Buy now, pay later.', 'expected' => 'Buy now, pay later.', 'description' => 'an object override reads as absent'],
        ['stored' => '{"1":42}', 'language' => 1, 'default' => 'x', 'expected' => '42', 'description' => 'a numeric override reads as text'],
        ['stored' => 'not json', 'language' => 1, 'default' => 'Buy now, pay later.', 'expected' => 'Buy now, pay later.', 'description' => 'a malformed map shows the default'],
        ['stored' => '"Pay by invoice"', 'language' => 1, 'default' => 'Buy now, pay later.', 'expected' => 'Buy now, pay later.', 'description' => 'a bare string, not a map by language, shows the default'],
    ];

    /**
     * The rendered fragment: the parts, what it must contain and what it must not.
     *
     * @var list<array{parts: array{brand: string, message?: string, button?: array{label: string, add_url: string, checkout_url: string, unreachable: string, script: string}, stylesheet: string}, contains: list<string>, absent: list<string>, description: string}>
     */
    private const RENDERS = [
        [
            'parts' => ['brand' => 'Two', 'message' => 'Buy now, pay later.', 'stylesheet' => 'p.css?v=1'],
            'contains' => ['<link rel="stylesheet" href="p.css?v=1">', '<span class="two-product-promo__brand">Two</span>', '<span class="two-product-promo__text">Buy now, pay later.</span>'],
            'absent' => ['<button', '<script'],
            'description' => 'the message alone, a brand with no mark: its name shown as text',
        ],
        [
            'parts' => ['brand' => 'Two', 'mark' => 'two_logo.svg?v=1', 'message' => 'Buy now, pay later.', 'stylesheet' => 'p.css'],
            'contains' => ['<img class="two-product-mark" src="two_logo.svg?v=1" alt="" onerror="this.nextElementSibling.classList.remove(\'two-product-hidden\');this.remove()">', '<span class="two-product-promo__brand two-product-hidden">Two</span>', '<span class="two-product-promo__text">Buy now, pay later.</span>'],
            'absent' => ['<span class="two-product-promo__brand">'],
            'description' => 'the message with the brand mark: the mark shown, the name kept as text for assistive technology (TWO-27110)',
        ],
        [
            'parts' => ['brand' => 'Two', 'message' => '<script>alert(1)</script> & "x"', 'stylesheet' => 'p.css'],
            'contains' => ['&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;'],
            'absent' => ['<script>alert(1)'],
            'description' => 'the override is escaped',
        ],
        [
            'parts' => ['brand' => 'Two', 'button' => ['label' => 'Buy with %s', 'add_url' => 'index.php?route=checkout/cart.add&language=en-gb', 'checkout_url' => 'https://shop/index.php?route=checkout/checkout&language=en-gb', 'unreachable' => 'Sorry, "try" again.', 'script' => 'b.js?v=1'], 'stylesheet' => 'p.css'],
            'contains' => ['<button type="button"', 'data-two-buy-now', 'data-two-add-url="index.php?route=checkout/cart.add&amp;language=en-gb"', 'data-two-checkout-url="https://shop/index.php?route=checkout/checkout&amp;language=en-gb"', 'data-two-unreachable="Sorry, &quot;try&quot; again."', '<span class="two-product-button__label">Buy with <span class="two-product-button__brand">Two</span></span>', 'role="alert"', '<script src="b.js?v=1"></script>'],
            'absent' => ['two-product-promo'],
            'description' => 'the button alone: a non-submitting button naming the brand, its URLs escaped once, and its script',
        ],
        [
            'parts' => ['brand' => 'Two', 'mark' => 'm.svg', 'button' => ['label' => 'Kjøp med %s & <b>', 'add_url' => 'a', 'checkout_url' => 'c', 'unreachable' => 'u', 'script' => 'b.js'], 'stylesheet' => 'p.css'],
            'contains' => ['<span class="two-product-button__label">Kjøp med <img class="two-product-mark" src="m.svg" alt="" onerror="this.nextElementSibling.classList.remove(\'two-product-hidden\');this.remove()"><span class="two-product-button__brand two-product-hidden">Two</span> &amp; &lt;b&gt;</span>'],
            'absent' => ['%s', '<b>'],
            'description' => 'the button with the brand mark where the label names the brand, the name kept as text, the rest escaped (TWO-27110)',
        ],
        [
            'parts' => ['brand' => 'Two', 'message' => 'Buy now.', 'button' => ['label' => 'Buy with %s', 'add_url' => 'a', 'checkout_url' => 'c', 'unreachable' => 'u', 'script' => 'b.js'], 'stylesheet' => 'p.css'],
            'contains' => ['two-product-promo', 'two-product-button__cta'],
            'absent' => [],
            'description' => 'both together',
        ],
    ];

    /**
     * Where the fragment goes: the page, and the page after (null when it is left alone).
     *
     * @var list<array{page: string, expected: string|null, description: string}>
     */
    private const PLACES = [
        ['page' => '<form method="post"><input name="product_id"/></form><form id="form-product"><button id="button-cart"></button></form>', 'expected' => '<form method="post"><input name="product_id"/></form><form id="form-product"><button id="button-cart"></button>[F]</form>', 'description' => 'inside the add to cart form, under the button, not in the form before it'],
        ['page' => "<form class='x' id='form-product' data-y><div></div></FORM><form></form>", 'expected' => "<form class='x' id='form-product' data-y><div></div>[F]</FORM><form></form>", 'description' => 'single quotes and upper case close tag'],
        ['page' => '<form id="form-product-review"></form>', 'expected' => null, 'description' => 'a form whose id only starts with form-product is not it'],
        ['page' => '<div id="form-product"></div>', 'expected' => null, 'description' => 'an element that is not a form is not it'],
        ['page' => '<form id="form-product"><button></button>', 'expected' => null, 'description' => 'an unclosed form is left alone'],
        ['page' => '<div>a theme with its own product form</div>', 'expected' => null, 'description' => 'a page without the form is left alone'],
    ];

    public static function runAll(): void
    {
        foreach (self::GATES as $case) {
            [$page, $transport] = self::productPage($case['stored'], $case['verdict']);
            TinyAssert::same($case['message'], $page->showsMessage($case['enabled']), 'message gate: ' . $case['description']);
            TinyAssert::same($case['button'], $page->showsButton($case['enabled'], $case['brand']), 'button gate: ' . $case['description']);
            TinyAssert::same([], $transport->calls, 'gates never call Two: ' . $case['description']);
        }

        foreach (self::MESSAGES as $case) {
            [$page] = self::productPage($case['stored'] === null ? [] : ['product_message' => $case['stored']], ApiKeyVerdict::OK);
            TinyAssert::same($case['expected'], $page->message($case['language'], $case['default']), 'message: ' . $case['description']);
        }

        foreach (self::RENDERS as $case) {
            $html = ProductPage::render($case['parts']);
            foreach ($case['contains'] as $needle) {
                TinyAssert::true(str_contains($html, $needle), 'render: ' . $case['description'] . ' (has ' . $needle . ')');
            }
            foreach ($case['absent'] as $needle) {
                TinyAssert::true(!str_contains($html, $needle), 'render: ' . $case['description'] . ' (lacks ' . $needle . ')');
            }
        }
        TinyAssert::same('', ProductPage::render(['brand' => 'Two', 'message' => '', 'stylesheet' => 'p.css']), 'render: nothing to show renders nothing, not even the stylesheet');

        // The mark the fragment shows comes from the brand descriptor, and ships with the extension (TWO-27110).
        $logo = Brand::default()->logo;
        TinyAssert::true($logo !== null && is_file(dirname(__DIR__) . '/' . $logo) && str_starts_with((string) file_get_contents(dirname(__DIR__) . '/' . $logo), '<svg'), 'brand: the default brand ships its mark as an SVG');
        TinyAssert::same(null, Brand::fromArray(['product_name' => 'X', 'provider_full_name' => 'X', 'about_url' => 'https://x.example'])->logo, 'brand: a descriptor with no mark has none, so its name is shown');

        foreach (self::PLACES as $case) {
            TinyAssert::same($case['expected'], ProductPage::place($case['page'], '[F]'), 'place: ' . $case['description']);
        }
    }

    /**
     * The real settings provider over a memory store, with the key verdict
     * cached as given (null: none cached yet).
     *
     * @param array<string, string> $stored
     * @return array{0: ProductPage, 1: ScriptedTransport}
     */
    private static function productPage(array $stored, ?string $verdict): array
    {
        $store = new MemoryStore($stored + ['api_key' => 'k']);
        $settings = new SettingsProvider($store);
        if ($verdict !== null) {
            $store->values[ApiKeyVerdict::CACHE_KEY] = (string) json_encode(['status' => $verdict, 'code' => null, 'fingerprint' => $settings->credentialFingerprint(), 'claim' => false, 'verified_on' => self::NOW, 'checked_on' => self::NOW]);
        }
        $transport = new ScriptedTransport([]);
        $client = new Client(new Environment(false), $settings, new CustomHeaders(), $transport, new RecordingLogger(), '1.0.0');

        return [new ProductPage($settings, new ApiKeyVerdict($client, $settings, static fn (): int => self::NOW)), $transport];
    }
}
