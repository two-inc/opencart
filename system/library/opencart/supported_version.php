<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

/**
 * Whether the running OpenCart release is one this extension supports
 * (TWO-27238). Supported means the series CI tests (SERIES, from FLOOR up)
 * and not listed in UNSUPPORTED. The releases listed there carry an upstream
 * OpenCart bug that breaks orders for every payment method, fixed in
 * FIXED_IN: the settings page warns on them, a banner and a failing
 * Diagnostics row. Any other release (an older series, or a newer one CI does
 * not test yet) gets a neutral "not tested" row and no banner. Nothing is
 * ever blocked: saving the settings and the payment method keep working.
 *
 * CI skips the same releases from its own list (oc_unsupported in
 * dev/support-matrix.sh), and FLOOR is OPENCART_FLOOR in the Makefile: change
 * them together.
 */
final class SupportedVersion
{
    /** The declared floor, the first release CI tests. */
    public const FLOOR = '4.1.0.0';

    /** The release series CI tests, from FLOOR up. */
    public const SERIES = '4.1';

    /** The first release with every listed bug fixed. */
    public const FIXED_IN = '4.1.0.4';

    /** Each unsupported release, with the language key naming its bug. */
    public const UNSUPPORTED = [
        // TWO-27233: guest checkout breaks.
        '4.1.0.1' => 'text_opencart_bug_guest_checkout',
        // TWO-27232: an admin order edit drops the order to status 0, a missing order.
        '4.1.0.2' => 'text_opencart_bug_order_edit',
        '4.1.0.3' => 'text_opencart_bug_order_edit',
    ];

    public const STATE_OK = 'ok';
    public const STATE_UNTESTED = 'untested';
    public const STATE_UNSUPPORTED = 'unsupported';

    /** The language key naming the bug that makes $version unsupported, or null when it is not listed. */
    public static function unsupportedReason(string $version): ?string
    {
        return self::UNSUPPORTED[$version] ?? null;
    }

    /** Whether $version is in the tested series, from the floor up. */
    public static function tested(string $version): bool
    {
        return str_starts_with($version, self::SERIES . '.') && version_compare($version, self::FLOOR, '>=');
    }

    /**
     * The settings page's sentence for $version, and its state: `ok` in the
     * tested series, `unsupported` (with the warning the banner and the
     * Diagnostics row show) on a listed release, else `untested`.
     *
     * @param callable(string): string $text the admin language's copy for a key
     * @return array{state: string, text: string}
     */
    public static function row(string $version, callable $text): array
    {
        $reason = self::unsupportedReason($version);
        if ($reason !== null) {
            return ['state' => self::STATE_UNSUPPORTED, 'text' => sprintf($text('text_opencart_unsupported'), $version, $text($reason), self::FIXED_IN)];
        }
        if (self::tested($version)) {
            return ['state' => self::STATE_OK, 'text' => sprintf($text('text_opencart_supported'), $version)];
        }

        return ['state' => self::STATE_UNTESTED, 'text' => sprintf($text('text_opencart_untested'), $version)];
    }
}
