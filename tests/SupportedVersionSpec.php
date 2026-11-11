<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\SupportedVersion;

/**
 * The settings page's OpenCart version row (TWO-27238): supported only in the
 * 4.1 series CI tests, from the 4.1.0.0 floor up; 4.1.0.1 to 4.1.0.3 carry an
 * upstream bug that breaks orders and are unsupported, naming the running
 * release, its bug and the release to upgrade to; any other release, older or
 * newer, has not been tested. In every admin language.
 */
final class SupportedVersionSpec
{
    private const LOCALES = ['en-gb', 'nb-no', 'sv-se', 'nl-nl', 'es-es'];

    public static function runAll(): void
    {
        $cases = [
            ['version' => '4.0.2.3', 'state' => 'untested', 'reason' => null, 'description' => 'a release below the floor has not been tested'],
            ['version' => '4.1.0.0', 'state' => 'ok', 'reason' => null, 'description' => 'the floor is supported'],
            ['version' => '4.1.0.1', 'state' => 'unsupported', 'reason' => 'text_opencart_bug_guest_checkout', 'description' => '4.1.0.1 breaks guest checkout'],
            ['version' => '4.1.0.2', 'state' => 'unsupported', 'reason' => 'text_opencart_bug_order_edit', 'description' => '4.1.0.2 drops an edited order to status 0'],
            ['version' => '4.1.0.3', 'state' => 'unsupported', 'reason' => 'text_opencart_bug_order_edit', 'description' => '4.1.0.3 drops an edited order to status 0'],
            ['version' => '4.1.0.4', 'state' => 'ok', 'reason' => null, 'description' => '4.1.0.4, which fixes both, is supported'],
            ['version' => '4.1.0.5', 'state' => 'ok', 'reason' => null, 'description' => 'a later 4.1 release is supported'],
            ['version' => '4.2.0.0', 'state' => 'untested', 'reason' => null, 'description' => 'a newer series has not been tested'],
        ];
        $root = dirname(__DIR__);
        foreach (self::LOCALES as $locale) {
            $copy = self::copy($root . '/admin/language/' . $locale . '/payment/two.php');
            $text = static fn (string $key): string => $copy[$key] ?? throw new RuntimeException($locale . ': no ' . $key);
            foreach ($cases as $case) {
                $label = $locale . ', ' . $case['description'];
                TinyAssert::same($case['reason'], SupportedVersion::unsupportedReason($case['version']), $label . ': reason');
                $row = SupportedVersion::row($case['version'], $text);
                TinyAssert::same($case['state'], $row['state'], $label . ': state');
                TinyAssert::true(str_contains($row['text'], $case['version']), $label . ': names the running release');
                $expected = match ($case['state']) {
                    'ok' => sprintf($copy['text_opencart_supported'], $case['version']),
                    'untested' => sprintf($copy['text_opencart_untested'], $case['version']),
                    default => null,
                };
                if ($expected !== null) {
                    TinyAssert::same($expected, $row['text'], $label . ': text');
                }
                if ($case['reason'] !== null) {
                    TinyAssert::true(str_contains($row['text'], $copy[$case['reason']]), $label . ': names the bug');
                    TinyAssert::true(str_contains($row['text'], SupportedVersion::FIXED_IN), $label . ': names the release to upgrade to');
                }
            }
        }
    }

    /** @return array<string, string> */
    private static function copy(string $file): array
    {
        $_ = [];
        require $file;

        return $_;
    }
}
