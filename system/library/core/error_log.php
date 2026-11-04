<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The Diagnostics tab's error log view (Build Guide section 3.4): the
 * extension's last LIMIT lines of the shop's error log, of any level. The
 * adapter reads the tail of the file; this picks the lines.
 *
 * OpenCart's Log writes each entry as "<date> <time> - <message>", and every
 * line the extension writes starts its message with "Two" ("Two error: ...",
 * "Two debug: ...", "Two: ..."). A tail read can start mid-line, so the first
 * piece is dropped unless it is itself a whole entry.
 */
final class ErrorLog
{
    public const LIMIT = 100;

    /** How much of the file's end the adapter reads: enough for LIMIT long debug lines among the shop's own. */
    public const TAIL_BYTES = 2_097_152;

    private const ENTRY = '~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} - Two[ :]~';

    /**
     * The extension's last $limit entries in $text, oldest first.
     *
     * @return list<string>
     */
    public static function twoLines(string $text, int $limit = self::LIMIT): array
    {
        $lines = preg_split('~\r\n|\n|\r~', $text) ?: [];
        $ours = array_values(array_filter($lines, static fn (string $line): bool => preg_match(self::ENTRY, $line) === 1));

        return $limit > 0 ? array_slice($ours, -$limit) : [];
    }
}
