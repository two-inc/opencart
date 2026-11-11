<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The payment terms a buyer may choose at checkout (Build Guide section 4).
 *
 * The terms come from the merchant record (`available_terms`), never from a
 * list held by the plugin: a term Two withdraws drops out of checkout even
 * while the merchant's own choice still ticks it. The merchant's settings
 * only narrow that set: the terms ticked on the Payment terms tab (30, 60
 * and 90 days on a fresh install), and the term type (end of month offers
 * 30, 45 and 60 only).
 *
 * With no offered term there is no default either: a substituted day count
 * would book the order under a term the merchant's account cannot honour,
 * so placement is refused instead.
 */
final class PaymentTerms
{
    public const STANDARD = 'STANDARD';
    public const END_OF_MONTH = 'EOM';
    public const TYPES = [self::STANDARD, self::END_OF_MONTH];

    /** The day counts an end-of-month term can take. */
    public const END_OF_MONTH_DAYS = [30, 45, 60];

    /** The preference every platform shares, when it is offered. */
    public const PREFERRED_DAYS = 30;

    /**
     * @param list<int>      $recordTerms the record's terms, ascending
     * @param list<int>      $ticked      the merchant's ticked terms
     */
    public function __construct(
        public readonly array $recordTerms,
        private readonly array $ticked,
        public readonly string $type,
        private readonly ?int $merchantDefault,
        private readonly ?int $dueInDays,
    ) {
    }

    /** @param array<string, mixed>|null $record */
    public static function from(?array $record, SettingsProvider $settings): self
    {
        $due = $record['due_in_days'] ?? null;

        return new self(
            self::recordTerms($record),
            $settings->tickedPaymentTerms(),
            $settings->paymentTermType(),
            $settings->defaultPaymentTerm(),
            is_numeric($due) && (int) $due > 0 ? (int) $due : null,
        );
    }

    /**
     * The record's `available_terms` as unique positive day counts, ascending.
     *
     * @param array<string, mixed>|null $record
     * @return list<int>
     */
    public static function recordTerms(?array $record): array
    {
        $days = [];
        foreach (is_array($record['available_terms'] ?? null) ? $record['available_terms'] : [] as $term) {
            if (is_numeric($term) && (int) $term > 0 && (float) $term === (float) (int) $term) {
                $days[(int) $term] = (int) $term;
            }
        }
        sort($days);

        return $days;
    }

    /**
     * The terms offered at checkout, ascending: the record's, by the term
     * type, narrowed to the merchant's ticked terms.
     *
     * @return list<int>
     */
    public function offered(): array
    {
        return array_values(array_filter($this->configurable(), fn (int $days): bool => in_array($days, $this->ticked, true)));
    }

    /**
     * The terms the Payment terms tab lists: the record's, by the term type.
     *
     * @return list<int>
     */
    public function configurable(): array
    {
        return $this->isEndOfMonth() ? array_values(array_intersect($this->recordTerms, self::END_OF_MONTH_DAYS)) : $this->recordTerms;
    }

    /**
     * The term a buyer starts on. A single offered term wins outright;
     * otherwise the merchant's own default, then the record's `due_in_days`,
     * then 30 days, each only when offered, then the shortest offered term.
     * Null when nothing is offered.
     */
    public function defaultTerm(): ?int
    {
        $offered = $this->offered();
        if (count($offered) === 1) {
            return $offered[0];
        }
        foreach ([$this->merchantDefault, $this->dueInDays, self::PREFERRED_DAYS] as $candidate) {
            if ($candidate !== null && in_array($candidate, $offered, true)) {
                return $candidate;
            }
        }

        return $offered[0] ?? null;
    }

    /** The buyer's chosen term when it is offered, else the default; null when nothing is offered. */
    public function selected(?int $chosen): ?int
    {
        return $chosen !== null && $this->offers($chosen) ? $chosen : $this->defaultTerm();
    }

    /** A posted term (the tile's `two.term`) as days, when it is a whole number of days the record offers; else null. */
    public function accept(mixed $posted): ?int
    {
        $days = is_string($posted) || is_int($posted) ? trim((string) $posted) : '';

        return ctype_digit($days) && $this->offers((int) $days) ? (int) $days : null;
    }

    public function offers(int $days): bool
    {
        return in_array($days, $this->offered(), true);
    }

    public function isEndOfMonth(): bool
    {
        return $this->type === self::END_OF_MONTH;
    }

    /**
     * The `terms` block Two takes on the order and the fee quote.
     *
     * @return array{type: string, duration_days: int, duration_days_calculated_from?: string}
     */
    public static function block(int $days, bool $endOfMonth): array
    {
        $block = ['type' => 'NET_TERMS', 'duration_days' => $days];
        if ($endOfMonth) {
            $block['duration_days_calculated_from'] = 'END_OF_MONTH';
        }

        return $block;
    }

    /**
     * A stored `terms` block read back, or null when it is not one.
     *
     * @return array{type: string, duration_days: int, duration_days_calculated_from?: string}|null
     */
    public static function decode(string $stored): ?array
    {
        $block = json_decode($stored, true);
        if (!is_array($block) || !is_int($block['duration_days'] ?? null) || $block['duration_days'] <= 0) {
            return null;
        }

        return self::block($block['duration_days'], ($block['duration_days_calculated_from'] ?? null) === 'END_OF_MONTH');
    }
}
