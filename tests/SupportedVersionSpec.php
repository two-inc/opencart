<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\SupportedVersion;

/**
 * OpenCart 4.1.0.1 to 4.1.0.3 carry an upstream bug that breaks orders, so
 * the settings page warns on them (TWO-27238); every other release, a future
 * one included, reads as supported. The warning names the running release,
 * its bug and the release to upgrade to, in every admin language.
 */
final class SupportedVersionSpec
{
    private const LOCALES = ['en-gb', 'nb-no', 'sv-se', 'nl-nl', 'es-es'];

    public static function runAll(): void
    {
        $cases = [
            ['version' => '4.1.0.0', 'reason' => null, 'description' => 'the floor is supported'],
            ['version' => '4.1.0.1', 'reason' => 'text_opencart_bug_guest_checkout', 'description' => '4.1.0.1 breaks guest checkout'],
            ['version' => '4.1.0.2', 'reason' => 'text_opencart_bug_order_edit', 'description' => '4.1.0.2 drops an edited order to status 0'],
            ['version' => '4.1.0.3', 'reason' => 'text_opencart_bug_order_edit', 'description' => '4.1.0.3 drops an edited order to status 0'],
            ['version' => '4.1.0.4', 'reason' => null, 'description' => '4.1.0.4, which fixes both, is supported'],
            ['version' => '4.2.0.0', 'reason' => null, 'description' => 'a future release is supported'],
        ];
        $root = dirname(__DIR__);
        foreach (self::LOCALES as $locale) {
            $copy = self::copy($root . '/admin/language/' . $locale . '/payment/two.php');
            $text = static fn (string $key): string => $copy[$key] ?? throw new RuntimeException($locale . ': no ' . $key);
            foreach ($cases as $case) {
                $label = $locale . ', ' . $case['description'];
                TinyAssert::same($case['reason'], SupportedVersion::unsupportedReason($case['version']), $label . ': reason');
                $row = SupportedVersion::row($case['version'], $text);
                TinyAssert::same($case['reason'] === null, $row['ok'], $label . ': ok');
                TinyAssert::true(str_contains($row['text'], $case['version']), $label . ': names the running release');
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
