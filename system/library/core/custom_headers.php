<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Merchant-defined request headers (Build Guide section 3.5): validated on
 * save with fully anchored rules, and validated again on read so a row edited
 * in the database can never reach the wire. Reserved names cover what the
 * Client itself sets.
 *
 * The save path halts and shows: validate() names every bad row and the
 * adapter refuses the whole table, so a row the merchant saw saved is a row
 * that is sent. The table is on the admin's Diagnostics tab; a row flagged
 * send_from_browser is also handed to the buyer's browser for its own calls
 * to Two (SettingsProvider::browserCustomHeaders).
 */
final class CustomHeaders
{
    /**
     * Names a row may not take: what the Client or cURL sets, the hop-by-hop
     * headers, headers that change how a response or connection is framed,
     * credentials or caller identity that must not be forgeable, and the
     * idempotency key, which the plugin sets per request.
     */
    public const RESERVED = [
        'host', 'content-type', 'content-length', 'accept', 'accept-language', 'accept-encoding',
        'x-api-key', 'x-vendor-name', 'x-forwarded-for', 'x-real-ip',
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer',
        'transfer-encoding', 'upgrade', 'expect', 'authorization', 'cookie',
        'idempotency-key', 'x-idempotency-key',
    ];

    /** validate() error codes, in the order a row is checked. The adapter maps them to copy. */
    public const NAME_INVALID = 'name_invalid';
    public const NAME_RESERVED = 'name_reserved';
    public const NAME_DUPLICATE = 'name_duplicate';
    public const VALUE_MISSING = 'value_missing';
    public const VALUE_INVALID = 'value_invalid';

    /** RFC 9110 field-name token. \z, because $ also matches before a trailing newline. */
    private const NAME = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/';

    /** Printable ASCII only: no CR or LF (request splitting), no control bytes, no non-ASCII. */
    private const VALUE = '/^[\x20-\x7E]+\z/';

    /**
     * Rows that pass validation, as header name => value. A row that fails is
     * dropped, never repaired. The first row wins when two share a name.
     *
     * @param list<array{name: string, value: string}> $rows
     * @return array<string, string>
     */
    public function forRequest(array $rows): array
    {
        $headers = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = trim($row['name']);
            $value = trim($row['value']);
            $lower = strtolower($name);
            if (!self::isSendable($name, $value) || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * One error code per rejected row, keyed by row index; empty when every
     * row is valid. The save is refused as a whole when this is not empty.
     * Name and value are trimmed first, as on read, and a row with both blank
     * is no row at all. A named row with no value, or a second row with the
     * same name, is refused rather than stored, because the read path would
     * silently drop it.
     *
     * @param list<array{name: string, value: string}> $rows
     * @return array<int, string>
     */
    public function validate(array $rows): array
    {
        $errors = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $name = trim($row['name']);
            $value = trim($row['value']);
            if ($name === '' && $value === '') {
                continue;
            }
            $lower = strtolower($name);
            $error = match (true) {
                preg_match(self::NAME, $name) !== 1 => self::NAME_INVALID,
                in_array($lower, self::RESERVED, true) => self::NAME_RESERVED,
                isset($seen[$lower]) => self::NAME_DUPLICATE,
                $value === '' => self::VALUE_MISSING,
                preg_match(self::VALUE, $value) !== 1 => self::VALUE_INVALID,
                default => null,
            };
            $seen[$lower] = true;
            if ($error !== null) {
                $errors[$index] = $error;
            }
        }

        return $errors;
    }

    private static function isSendable(string $name, string $value): bool
    {
        return preg_match(self::NAME, $name) === 1
            && !in_array(strtolower($name), self::RESERVED, true)
            && preg_match(self::VALUE, $value) === 1;
    }
}
