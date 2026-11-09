<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Fits a registry postcode to its country's written format before company
 * autofill copies it into the address form (TWO-27110). A register can give
 * "1234AB" where the Netherlands writes "1234 AB", or "00950" where Poland
 * writes "00-950". OpenCart keeps no postcode format per country, so the
 * formats are held here.
 *
 * In a format, N is a digit and L is a letter; a space or a hyphen is a
 * separator.
 */
final class PostcodeFormat
{
    /** The postcode format of each country that has one fixed pattern. */
    public const FORMATS = [
        'AT' => 'NNNN', 'BE' => 'NNNN', 'BG' => 'NNNN', 'CH' => 'NNNN', 'CY' => 'NNNN', 'CZ' => 'NNN NN',
        'DE' => 'NNNNN', 'DK' => 'NNNN', 'EE' => 'NNNNN', 'ES' => 'NNNNN', 'FI' => 'NNNNN', 'FR' => 'NNNNN',
        'GR' => 'NNN NN', 'HR' => 'NNNNN', 'HU' => 'NNNN', 'IT' => 'NNNNN', 'LU' => 'NNNN', 'NL' => 'NNNN LL',
        'NO' => 'NNNN', 'PL' => 'NN-NNN', 'PT' => 'NNNN-NNN', 'RO' => 'NNNNNN', 'SE' => 'NNN NN', 'SK' => 'NNN NN',
        'IS' => 'NNN', 'LI' => 'NNNN', 'MT' => 'LLL NNNN', 'SI' => 'NNNN',
    ];

    /**
     * Spaces and hyphens are dropped and the format's separators put back
     * when what remains fills the format's slots exactly. Anything else, and
     * a country with no format, is returned unchanged. Letter case is kept.
     */
    public static function fit(string $postcode, string $country): string
    {
        $format = self::FORMATS[strtoupper(trim($country))] ?? '';
        if ($format === '' || $postcode === '') {
            return $postcode;
        }
        $chars = str_replace([' ', '-'], '', $postcode);
        $length = strlen($chars);
        $out = '';
        $at = 0;
        for ($i = 0, $n = strlen($format); $i < $n; $i++) {
            $slot = $format[$i];
            if ($slot === ' ' || $slot === '-') {
                $out .= $slot;
                continue;
            }
            $char = substr($chars, $at, 1);
            if (preg_match($slot === 'N' ? '/^[0-9]$/' : '/^[a-zA-Z]$/', $char) !== 1) {
                return $postcode;
            }
            $out .= $char;
            $at++;
        }

        return $at === $length ? $out : $postcode;
    }
}
