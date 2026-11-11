<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The optional buyer fields at checkout (Build Guide section 6.1): invoice
 * email, purchase order number, project and department, each shown when its
 * toggle is on. They go on the order create as `invoice_details`,
 * `buyer_purchase_order_number`, `buyer_project` and `buyer_department`, and
 * are kept with the order so the confirm can rebuild the same payload.
 */
final class BuyerFields
{
    /** The fields in the order the tile renders them. */
    public const FIELDS = ['invoice_email', 'purchase_order_number', 'project', 'department'];
    public const MAX_LENGTH = 255;

    /**
     * The fields the buyer filled, trimmed, keeping only the enabled ones,
     * and the fields refused (too long, or an invoice email that is not one).
     *
     * @param array<string, mixed> $posted
     * @param list<string>         $enabled
     * @return array{fields: array<string, string>, refused: list<string>}
     */
    public static function fromPost(array $posted, array $enabled): array
    {
        $fields = [];
        $refused = [];
        foreach (self::FIELDS as $field) {
            $value = in_array($field, $enabled, true) && is_scalar($posted[$field] ?? null) ? trim((string) $posted[$field]) : '';
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > self::MAX_LENGTH || preg_match('~[\x00-\x1F\x7F]~', $value) === 1
                || ($field === 'invoice_email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
                $refused[] = $field;
                continue;
            }
            $fields[$field] = $value;
        }

        return ['fields' => $fields, 'refused' => $refused];
    }

    /**
     * As stored: a JSON object of the non-empty fields in a fixed order, so
     * the same fields always store the same text; empty for none.
     *
     * @param array<string, mixed> $fields
     */
    public static function encode(array $fields): string
    {
        $kept = [];
        foreach (self::FIELDS as $field) {
            $value = is_scalar($fields[$field] ?? null) ? trim((string) $fields[$field]) : '';
            if ($value !== '') {
                $kept[$field] = $value;
            }
        }

        return $kept === [] ? '' : (string) json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string> */
    public static function decode(string $stored): array
    {
        $decoded = json_decode($stored, true);
        $fields = [];
        foreach (self::FIELDS as $field) {
            if (is_array($decoded) && is_scalar($decoded[$field] ?? null)) {
                $fields[$field] = (string) $decoded[$field];
            }
        }

        return $fields;
    }
}
