<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

/**
 * Whether the running OpenCart release is one this extension supports. The
 * releases listed here carry an upstream OpenCart bug that breaks orders for
 * every payment method, fixed in FIXED_IN (TWO-27238). The settings page
 * warns on them, a banner and a failing Diagnostics row, and never blocks:
 * saving the settings and the payment method keep working.
 *
 * CI skips the same releases from its own list (oc_unsupported in
 * dev/support-matrix.sh): change the two together.
 */
final class SupportedVersion
{
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

    /** The language key naming the bug that makes $version unsupported, or null when it is supported. */
    public static function unsupportedReason(string $version): ?string
    {
        return self::UNSUPPORTED[$version] ?? null;
    }

    /**
     * The settings page's sentence for $version: `ok` false, with the warning
     * the banner and the Diagnostics row show, on an unsupported release.
     *
     * @param callable(string): string $text the admin language's copy for a key
     * @return array{ok: bool, text: string}
     */
    public static function row(string $version, callable $text): array
    {
        $reason = self::unsupportedReason($version);
        if ($reason === null) {
            return ['ok' => true, 'text' => sprintf($text('text_opencart_supported'), $version)];
        }

        return ['ok' => false, 'text' => sprintf($text('text_opencart_unsupported'), $version, $text($reason), self::FIXED_IN)];
    }
}
