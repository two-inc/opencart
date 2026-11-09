<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The buyer surcharge (Build Guide section 5): the merchant's grid, one row
 * per payment term, mapped onto the fee quote's `buyer_fee_share` block.
 * Two computes the fee; the plugin only relays the configuration.
 *
 * Percentages carry no currency. Fixed fees and caps are typed in the shop's
 * default currency and converted into the quote's at Two's own rates
 * (FeeQuote). The fee's tax is never decided here: it is what the shop's
 * tax engine gives the tax class the merchant selects for it.
 */
final class Surcharge
{
    public const NONE = 'none';
    public const PERCENTAGE = 'percentage';
    public const FIXED = 'fixed';
    public const FIXED_AND_PERCENTAGE = 'fixed_and_percentage';
    public const TYPES = [self::NONE, self::PERCENTAGE, self::FIXED, self::FIXED_AND_PERCENTAGE];

    public const ROUNDING = ['up' => 'UP', 'down' => 'DOWN', 'standard' => 'STANDARD'];

    /** The rounding steps offered, as the reference plugin's brand descriptor lists them. */
    public const ROUNDING_STEPS = ['0.10', '0.50', '1.00', '5.00', '10.00'];

    /** The grid's columns, each a non-negative number or blank. */
    public const COLUMNS = ['percentage', 'fixed', 'cap'];

    /** Two's pricing API refuses a figure finer than two places rather than rounding it. */
    public const MONEY_DECIMALS = 2;

    /** Save errors. The adapter maps them to copy. */
    public const TYPE_UNKNOWN = 'surcharge_type_unknown';
    public const TAX_CLASS_MISSING = 'surcharge_tax_class_missing';
    public const TAX_CLASS_UNKNOWN = 'surcharge_tax_class_unknown';
    public const STEP_UNKNOWN = 'surcharge_step_unknown';
    public const BASIS_UNKNOWN = 'surcharge_basis_unknown';
    public const CELL_INVALID = 'surcharge_cell_invalid';
    public const CAP_ZERO = 'surcharge_cap_zero';
    public const PERCENT_RANGE = 'surcharge_percent_range';

    /** The percentage is the share of Two's fee passed to the buyer, so Two takes 0 to 100. */
    public const MAX_PERCENTAGE = 100;

    /**
     * @param array<int, array{percentage: float, fixed: float, cap: float|null}> $grid by term
     */
    public function __construct(
        public readonly string $type,
        public readonly bool $differential,
        private readonly array $grid,
        public readonly string $roundingBasis,
        public readonly ?float $roundingStep,
        public readonly string $lineDescription,
        public readonly int $taxClassId,
    ) {
    }

    public static function from(SettingsProvider $settings): self
    {
        $store = $settings->store();
        $grid = [];
        $decoded = json_decode((string) $store->get('surcharge_grid'), true);
        foreach (is_array($decoded) ? $decoded : [] as $days => $row) {
            if (!is_array($row) || !ctype_digit((string) $days)) {
                continue;
            }
            $cap = self::number($row['cap'] ?? null);
            $grid[(int) $days] = [
                'percentage' => (float) (self::number($row['percentage'] ?? null) ?? 0),
                'fixed' => (float) (self::number($row['fixed'] ?? null) ?? 0),
                // Blank is no cap; a stored zero is a real cap, bounding the fee at zero.
                'cap' => $cap === null || (float) $cap < 0 ? null : (float) $cap,
            ];
        }
        $step = trim((string) $store->get('surcharge_rounding_step'));
        $taxClass = trim((string) $store->get('surcharge_tax_class_id'));
        $type = trim((string) $store->get('surcharge_type'));

        return new self(
            $type === '' ? self::NONE : $type,
            trim((string) $store->get('surcharge_differential')) === '1',
            $grid,
            trim((string) $store->get('surcharge_rounding_basis')),
            in_array($step, self::ROUNDING_STEPS, true) ? (float) $step : null,
            trim((string) $store->get('surcharge_line_description')),
            ctype_digit($taxClass) ? (int) $taxClass : 0,
        );
    }

    /** Whether the stored method is one the plugin knows. An unknown one prices nothing and withholds Two. */
    public function isKnown(): bool
    {
        return in_array($this->type, self::TYPES, true);
    }

    public function enabled(): bool
    {
        return $this->type !== self::NONE;
    }

    /**
     * The `buyer_fee_share` block for one term, in the shop's default
     * currency, or null when no surcharge is set. A fixed fee goes as
     * `surcharge`; a cap and the rounding go only with a percentage; the
     * percentage goes as 0 for a fixed-only fee, so Two's default of 100%
     * never applies. In fee-difference mode the default term is sent as the
     * reference, and Two computes the difference.
     *
     * @return array<string, mixed>|null
     * @throws \UnexpectedValueException when the stored method is unknown
     */
    public function feeShare(int $days, ?int $defaultTerm, bool $endOfMonth): ?array
    {
        if (!$this->isKnown()) {
            throw new \UnexpectedValueException('Unrecognised surcharge method "' . $this->type . '".');
        }
        if (!$this->enabled()) {
            return null;
        }
        $percent = in_array($this->type, [self::PERCENTAGE, self::FIXED_AND_PERCENTAGE], true);
        $fixed = in_array($this->type, [self::FIXED, self::FIXED_AND_PERCENTAGE], true);
        $row = $this->grid[$days] ?? ['percentage' => 0.0, 'fixed' => 0.0, 'cap' => null];

        $share = ['percentage' => $percent ? $row['percentage'] : 0.0, 'surcharge_basis' => 'buyer_pays'];
        if ($fixed && $row['fixed'] > 0) {
            $share['surcharge'] = round($row['fixed'], self::MONEY_DECIMALS);
        }
        if ($percent && $row['cap'] !== null) {
            $share['cap'] = round($row['cap'], self::MONEY_DECIMALS);
        }
        if ($percent && isset(self::ROUNDING[$this->roundingBasis]) && $this->roundingStep !== null) {
            $share['rounding'] = ['step' => $this->roundingStep, 'basis' => self::ROUNDING[$this->roundingBasis]];
        }
        if ($this->differential && $defaultTerm !== null) {
            $share['reference_terms'] = PaymentTerms::block($defaultTerm, $endOfMonth);
        }

        return $share;
    }

    /**
     * Whether a term prices a fee at all: the gate and the fee line share
     * this, so a term that charges nothing is never quoted. A cap alone
     * charges nothing, and in fee-difference mode the default term is its
     * own reference.
     */
    public function pricesFee(int $days, ?int $defaultTerm, bool $endOfMonth): bool
    {
        $share = $this->feeShare($days, $defaultTerm, $endOfMonth);
        if ($share === null) {
            return false;
        }
        if (($share['surcharge'] ?? 0) > 0) {
            return true;
        }

        return $share['percentage'] > 0 && !($this->differential && $days === $defaultTerm);
    }

    /**
     * The fee line's title: the merchant's own (%s for the days), else the
     * language's sentence for the term type.
     *
     * @param string $standard the language's "Payment terms fee - %d days"
     * @param string $endOfMonth the language's "Payment terms fee - %d days from end of month"
     */
    public function label(int $days, bool $isEndOfMonth, string $standard, string $endOfMonth): string
    {
        if ($this->lineDescription !== '') {
            return str_replace('%s', (string) $days, $this->lineDescription);
        }

        return sprintf($isEndOfMonth ? $endOfMonth : $standard, $days);
    }

    /**
     * A grid cell as a number string, or null when it is not a number. A
     * comma before one or two final digits is a decimal comma; any other
     * comma is refused, so 1,000 never becomes 1.
     */
    public static function number(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = trim((string) $raw);
        if (preg_match('~^[+-]?\d*,\d{1,' . self::MONEY_DECIMALS . '}\z~', $value) === 1) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? $value : null;
    }

    /**
     * The surcharge fields' save rules: the method known; while a surcharge
     * is on, a tax class the shop has (the fee is never left untaxed by
     * default: a 0% class says so explicitly), a known rounding, and every
     * grid cell blank or a non-negative number, with no zero cap where a
     * percentage applies (a zero cap silently wipes the whole fee).
     *
     * @param array{surcharge_type?: string, surcharge_differential?: string, surcharge_rounding_basis?: string, surcharge_rounding_step?: string, surcharge_line_description?: string, surcharge_tax_class_id?: string, surcharge_grid?: array<int|string, array<string, string>>} $submitted
     * @param list<int> $terms       the terms the grid renders a row for
     * @param list<int> $taxClassIds the shop's tax classes
     * @return array{write: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $submitted, array $terms, array $taxClassIds): array
    {
        $write = [];
        $errors = [];
        if (!isset($submitted['surcharge_type'])) {
            return ['write' => $write, 'errors' => $errors];
        }
        $type = trim($submitted['surcharge_type']);
        if (!in_array($type, self::TYPES, true)) {
            return ['write' => $write, 'errors' => ['surcharge_type' => self::TYPE_UNKNOWN]];
        }
        $on = $type !== self::NONE;
        $write['surcharge_type'] = $type;
        $write['surcharge_differential'] = trim($submitted['surcharge_differential'] ?? '0') === '1' ? '1' : '0';
        $write['surcharge_line_description'] = trim($submitted['surcharge_line_description'] ?? '');

        $basis = trim($submitted['surcharge_rounding_basis'] ?? 'none');
        if ($basis === 'none' || isset(self::ROUNDING[$basis])) {
            $write['surcharge_rounding_basis'] = $basis;
        } else {
            $errors['surcharge_rounding_basis'] = self::BASIS_UNKNOWN;
        }
        $step = trim($submitted['surcharge_rounding_step'] ?? '');
        if ($step === '' || in_array($step, self::ROUNDING_STEPS, true)) {
            $write['surcharge_rounding_step'] = $step;
        } elseif ($on) {
            $errors['surcharge_rounding_step'] = self::STEP_UNKNOWN;
        }

        $class = trim($submitted['surcharge_tax_class_id'] ?? '');
        $known = ctype_digit($class) && in_array((int) $class, $taxClassIds, true);
        if ($known) {
            $write['surcharge_tax_class_id'] = $class;
        } elseif ($on) {
            $errors['surcharge_tax_class_id'] = $class === '' || $class === '0' ? self::TAX_CLASS_MISSING : self::TAX_CLASS_UNKNOWN;
        } else {
            $write['surcharge_tax_class_id'] = '';
        }

        $capApplies = in_array($type, [self::PERCENTAGE, self::FIXED_AND_PERCENTAGE], true);
        $grid = [];
        $gridErrors = [];
        foreach ($terms as $days) {
            $row = $submitted['surcharge_grid'][$days] ?? $submitted['surcharge_grid'][(string) $days] ?? [];
            foreach (self::COLUMNS as $column) {
                $raw = trim((string) ($row[$column] ?? ''));
                if ($raw === '') {
                    $grid[$days][$column] = '';
                    continue;
                }
                $value = self::number($raw);
                if ($value === null || (float) $value < 0) {
                    $gridErrors[] = self::CELL_INVALID . ':' . $column . ':' . $days;
                    continue;
                }
                if ($column === 'percentage' && (float) $value > self::MAX_PERCENTAGE) {
                    $gridErrors[] = self::PERCENT_RANGE . ':' . $column . ':' . $days;
                    continue;
                }
                if ($column === 'cap' && $capApplies && round((float) $value, self::MONEY_DECIMALS) === 0.0) {
                    $gridErrors[] = self::CAP_ZERO . ':' . $column . ':' . $days;
                    continue;
                }
                $grid[$days][$column] = (string) (float) $value;
            }
        }
        if ($gridErrors !== []) {
            $errors['surcharge_grid'] = implode(',', $gridErrors);
        } elseif (isset($submitted['surcharge_grid'])) {
            $write['surcharge_grid'] = (string) json_encode((object) $grid);
        }

        return ['write' => $write, 'errors' => $errors];
    }
}
