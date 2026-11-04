<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The typed settings view. Each case is what the store holds and what one
 * getter returns, so a missing or malformed value has a pinned reading.
 */
final class SettingsProviderSpec
{
    /** @var list<array{stored: array<string, string>, getter: string, args?: list<int>, expected: mixed, description: string}> */
    private const CASES = [
        ['stored' => [], 'getter' => 'apiKey', 'expected' => '', 'description' => 'no key stored reads as empty'],
        ['stored' => ['api_key' => ' secret '], 'getter' => 'apiKey', 'expected' => 'secret', 'description' => 'the key is trimmed'],
        ['stored' => [], 'getter' => 'environment', 'expected' => null, 'description' => 'no environment stored reads as null (fresh install)'],
        ['stored' => ['environment' => ' Staging '], 'getter' => 'environment', 'expected' => ' Staging ', 'description' => 'the environment is returned unresolved'],
        ['stored' => [], 'getter' => 'verifyTls', 'expected' => true, 'description' => 'TLS verification is on when nothing is stored'],
        ['stored' => ['disable_ssl_verify' => '0'], 'getter' => 'verifyTls', 'expected' => true, 'description' => 'TLS verification is on by default'],
        ['stored' => ['disable_ssl_verify' => '1'], 'getter' => 'verifyTls', 'expected' => false, 'description' => 'the bypass switches it off'],
        ['stored' => ['disable_ssl_verify' => 'yes'], 'getter' => 'verifyTls', 'expected' => true, 'description' => 'only exactly 1 switches it off'],
        ['stored' => [], 'getter' => 'debugLogging', 'expected' => false, 'description' => 'debug logging is off when nothing is stored'],
        ['stored' => ['debug_mode' => '1'], 'getter' => 'debugLogging', 'expected' => true, 'description' => 'debug logging on'],
        ['stored' => [], 'getter' => 'customHeaderRows', 'expected' => [], 'description' => 'no header rows stored'],
        ['stored' => ['custom_headers' => 'not json'], 'getter' => 'customHeaderRows', 'expected' => [], 'description' => 'malformed JSON reads as no rows'],
        ['stored' => ['custom_headers' => '{"name":"X"}'], 'getter' => 'customHeaderRows', 'expected' => [], 'description' => 'an object, not a list of rows, reads as no rows'],
        ['stored' => ['custom_headers' => '[{"name":"X-A","value":"1"},{"name":"X-B"},"junk",{"name":["x"],"value":"v"}]'], 'getter' => 'customHeaderRows', 'expected' => [['name' => 'X-A', 'value' => '1']], 'description' => 'rows without a scalar name and value are skipped'],
        ['stored' => [], 'getter' => 'fulfilStatusIds', 'expected' => [], 'description' => 'no fulfilment map stored'],
        ['stored' => ['fulfil_status_ids' => '[3,"5",3,0,-1,"x",2.5]'], 'getter' => 'fulfilStatusIds', 'expected' => [3, 5], 'description' => 'the fulfilment map keeps positive ids once'],
        ['stored' => ['fulfil_status_ids' => '3'], 'getter' => 'fulfilStatusIds', 'expected' => [], 'description' => 'a fulfilment map that is not a list reads as empty'],
        ['stored' => [], 'getter' => 'cancelStatusId', 'expected' => null, 'description' => 'no cancel status stored'],
        ['stored' => ['cancel_status_id' => '7'], 'getter' => 'cancelStatusId', 'expected' => 7, 'description' => 'the cancel status'],
        ['stored' => ['cancel_status_id' => '0'], 'getter' => 'cancelStatusId', 'expected' => null, 'description' => 'a zero cancel status is none'],
        ['stored' => ['refund_status_id' => ' 11 '], 'getter' => 'refundStatusId', 'expected' => 11, 'description' => 'the refund status, trimmed'],
        ['stored' => ['refund_status_id' => 'x'], 'getter' => 'refundStatusId', 'expected' => null, 'description' => 'a malformed refund status is none'],
        ['stored' => ['custom_headers' => '[{"name":"X-N","value":42}]'], 'getter' => 'customHeaderRows', 'expected' => [['name' => 'X-N', 'value' => '42']], 'description' => 'a numeric value reads as a string'],
        ['stored' => [], 'getter' => 'confirmedStatusId', 'expected' => null, 'description' => 'no confirmed status stored'],
        ['stored' => ['confirmed_status_id' => '2'], 'getter' => 'confirmedStatusId', 'expected' => 2, 'description' => 'the confirmed status'],
        ['stored' => [], 'getter' => 'placedStatusId', 'args' => [1], 'expected' => 1, 'description' => 'with no confirmed status an order is placed at the shop default'],
        ['stored' => ['confirmed_status_id' => '0'], 'getter' => 'placedStatusId', 'args' => [1], 'expected' => 1, 'description' => 'a zero confirmed status places at the shop default'],
        ['stored' => ['confirmed_status_id' => '2'], 'getter' => 'placedStatusId', 'args' => [1], 'expected' => 2, 'description' => 'a confirmed status places the order there'],
        ['stored' => [], 'getter' => 'vendorName', 'expected' => '', 'description' => 'no vendor name stored'],
        ['stored' => ['vendor_name' => ' North site '], 'getter' => 'vendorName', 'expected' => 'North site', 'description' => 'the vendor name, trimmed'],
        ['stored' => [], 'getter' => 'subtitle', 'args' => [1], 'expected' => '', 'description' => 'no subtitle stored shows none'],
        ['stored' => ['subtitle' => '{"1":" Pay later ","2":"Betal senere"}'], 'getter' => 'subtitle', 'args' => [2], 'expected' => 'Betal senere', 'description' => 'the subtitle for the language'],
        ['stored' => ['subtitle' => '{"1":" Pay later "}'], 'getter' => 'subtitle', 'args' => [1], 'expected' => 'Pay later', 'description' => 'the subtitle is trimmed'],
        ['stored' => ['subtitle' => '{"1":"Pay later"}'], 'getter' => 'subtitle', 'args' => [3], 'expected' => '', 'description' => 'a language with no subtitle shows none, never another language\'s'],
        ['stored' => ['subtitle' => 'not json'], 'getter' => 'subtitle', 'args' => [1], 'expected' => '', 'description' => 'malformed subtitles show none'],
        ['stored' => [], 'getter' => 'orderIntentPreview', 'expected' => true, 'description' => 'the intent preview is on for a shop that predates the switch'],
        ['stored' => ['enable_order_intent' => '0'], 'getter' => 'orderIntentPreview', 'expected' => false, 'description' => 'the intent preview switched off'],
        ['stored' => [], 'getter' => 'showAboutLink', 'expected' => true, 'description' => 'the explainer link is on for a shop that predates the switch'],
        ['stored' => ['show_about_link' => '0'], 'getter' => 'showAboutLink', 'expected' => false, 'description' => 'the explainer link switched off'],
        ['stored' => [], 'getter' => 'displayTooltips', 'expected' => true, 'description' => 'tooltips are on for a shop that predates the switch'],
        ['stored' => ['display_tooltips' => '0'], 'getter' => 'displayTooltips', 'expected' => false, 'description' => 'tooltips switched off'],
        ['stored' => [], 'getter' => 'taxSubtotals', 'expected' => true, 'description' => 'tax subtotals are sent for a shop that predates the switch'],
        ['stored' => ['enable_tax_subtotals' => '0'], 'getter' => 'taxSubtotals', 'expected' => false, 'description' => 'tax subtotals switched off'],
    ];

    /** @var list<array{a: array<string, string>, b: array<string, string>, same: bool, description: string}> */
    private const FINGERPRINTS = [
        ['a' => ['api_key' => 'k'], 'b' => ['api_key' => 'k', 'environment' => 'sandbox'], 'same' => true, 'description' => 'no environment and sandbox are the same credentials'],
        ['a' => ['api_key' => 'k', 'environment' => 'prod'], 'b' => ['api_key' => 'k', 'environment' => ' Production '], 'same' => true, 'description' => 'environment spellings that resolve alike match'],
        ['a' => ['api_key' => 'k', 'environment' => 'sandbox'], 'b' => ['api_key' => 'k', 'environment' => 'production'], 'same' => false, 'description' => 'another environment is other credentials'],
        ['a' => ['api_key' => 'k1'], 'b' => ['api_key' => 'k2'], 'same' => false, 'description' => 'another key is other credentials'],
    ];

    /** @var list<array{stored: string|null, language: int, default?: string, expected: string, description: string}> */
    private const TITLES = [
        ['stored' => null, 'language' => 1, 'expected' => 'Business invoice', 'description' => 'no titles stored reads as the default'],
        ['stored' => '{"1":" Pay by invoice ","2":"Faktura"}', 'language' => 2, 'expected' => 'Faktura', 'description' => 'the title for the language'],
        ['stored' => '{"1":" Pay by invoice "}', 'language' => 1, 'expected' => 'Pay by invoice', 'description' => 'the title is trimmed'],
        ['stored' => '{"1":"Pay by invoice"}', 'language' => 3, 'expected' => 'Business invoice', 'description' => 'a language added after the save reads as the default'],
        ['stored' => '{"1":"  "}', 'language' => 1, 'expected' => 'Business invoice', 'description' => 'a blank title reads as the default'],
        ['stored' => 'not json', 'language' => 1, 'expected' => 'Business invoice', 'description' => 'malformed titles read as the default'],
        ['stored' => null, 'language' => 2, 'default' => 'Bedriftsfaktura', 'expected' => 'Bedriftsfaktura', 'description' => 'no title reads as the language\'s own default'],
        ['stored' => '{"2":"Faktura"}', 'language' => 2, 'default' => 'Bedriftsfaktura', 'expected' => 'Faktura', 'description' => 'a stored title wins over the language\'s default'],
        ['stored' => null, 'language' => 2, 'default' => ' ', 'expected' => 'Business invoice', 'description' => 'a language file with no default reads as the built-in one'],
    ];

    public static function runAll(): void
    {
        foreach (self::TITLES as $case) {
            $settings = new SettingsProvider(new MemoryStore($case['stored'] === null ? [] : ['title' => $case['stored']]));
            TinyAssert::same($case['expected'], $settings->title($case['language'], $case['default'] ?? ''), 'title: ' . $case['description']);
        }

        foreach (self::CASES as $case) {
            $settings = new SettingsProvider(new MemoryStore($case['stored']));
            TinyAssert::same($case['expected'], $settings->{$case['getter']}(...($case['args'] ?? [])), $case['description']);
        }

        foreach (self::FINGERPRINTS as $case) {
            $a = (new SettingsProvider(new MemoryStore($case['a'])))->credentialFingerprint();
            $b = (new SettingsProvider(new MemoryStore($case['b'])))->credentialFingerprint();
            TinyAssert::same($case['same'], $a === $b, 'fingerprint: ' . $case['description']);
        }
    }
}
