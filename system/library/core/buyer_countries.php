<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The buyer countries the merchant may sell to on Two (Build Guide section
 * 8.1), read from the merchant record's `supported_buyer_countries`. Four
 * states, because only an absent field means "no restriction":
 *
 * - absent: no record, or a record without the field. Every country is allowed;
 * - empty: the field is there and names no country. None is allowed;
 * - malformed: the field is there but is not a list. None is allowed;
 * - allowlist: the countries named, as uppercase ISO alpha-2 codes.
 */
final class BuyerCountries
{
    public const ABSENT = 'absent';
    public const EMPTY = 'empty';
    public const MALFORMED = 'malformed';
    public const ALLOWLIST = 'allowlist';

    /** @param list<string> $allowed */
    private function __construct(public readonly string $state, public readonly array $allowed)
    {
    }

    /** @param array<string, mixed>|null $record the merchant record, or null when none is cached */
    public static function fromRecord(?array $record): self
    {
        if ($record === null || !array_key_exists('supported_buyer_countries', $record) || $record['supported_buyer_countries'] === null) {
            return new self(self::ABSENT, []);
        }
        $field = $record['supported_buyer_countries'];
        if (!is_array($field) || !array_is_list($field)) {
            return new self(self::MALFORMED, []);
        }
        $allowed = [];
        foreach ($field as $code) {
            $code = is_string($code) ? strtoupper(trim($code)) : '';
            if (preg_match('~^[A-Z]{2}\z~', $code) === 1 && !in_array($code, $allowed, true)) {
                $allowed[] = $code;
            }
        }

        return new self($allowed === [] ? self::EMPTY : self::ALLOWLIST, $allowed);
    }

    /** Whether a buyer in this country (uppercase alpha-2, or null when none resolves) may be offered Two. */
    public function allows(?string $country): bool
    {
        if ($this->state === self::ABSENT) {
            return true;
        }

        return $country !== null && in_array(strtoupper($country), $this->allowed, true);
    }
}
