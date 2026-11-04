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

    public const REDACTED = '[REDACTED]';

    public const MIN_SECRET = 4;

    private const ENTRY ='~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} - Two[ :]~';

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

    /**
     * The lines the tab shows, ready for HTML: the extension's last $limit
     * entries with every secret (the API key, each custom header value)
     * replaced by REDACTED, then each line HTML-escaped. The client never
     * logs a secret; this holds for lines written by anything else, or by an
     * older version. A secret shorter than MIN_SECRET is left alone: it
     * would blank out ordinary text and reveals nothing on its own.
     *
     * @param list<string> $secrets
     * @return list<string>
     */
    public static function view(string $text, array $secrets, int $limit = self::LIMIT): array
    {
        $secrets = array_values(array_filter(array_map('trim', $secrets), static fn (string $secret): bool => strlen($secret) >= self::MIN_SECRET));
        // Longest first, so a secret containing another is replaced whole.
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return array_map(static fn (string $line): string => htmlspecialchars($secrets === [] ? $line : str_replace($secrets, self::REDACTED, $line), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), self::twoLines($text, $limit));
    }
}
