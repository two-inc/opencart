<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Surcharge;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The buyer surcharge (Build Guide section 5): the grid row mapped onto the
 * fee quote's buyer_fee_share block, whether a term prices a fee, the line
 * title, the decimal comma, and the save rules.
 */
final class SurchargeSpec
{
    private const GRID = '{"30":{"percentage":"1.5","fixed":"2","cap":"10"},"60":{"percentage":"2.5","fixed":"","cap":""},"14":{"percentage":"0","fixed":"0","cap":"0"}}';

    public static function runAll(): void
    {
        self::testFeeShare();
        self::testPricesFee();
        self::testLabel();
        self::testNumber();
        self::testValidate();
    }

    private static function testFeeShare(): void
    {
        $cases = [
            [['surcharge_type' => 'none'], 30, null, 'no surcharge sends no block'],
            [['surcharge_type' => 'percentage'], 30, '{"percentage":1.5,"surcharge_basis":"buyer_pays","cap":10}', 'a percentage carries its cap, not the fixed fee'],
            [['surcharge_type' => 'fixed'], 30, '{"percentage":0,"surcharge_basis":"buyer_pays","surcharge":2}', 'a fixed fee sends 0%, so Two\'s default of 100% never applies, and no cap'],
            [['surcharge_type' => 'fixed_and_percentage'], 30, '{"percentage":1.5,"surcharge_basis":"buyer_pays","surcharge":2,"cap":10}', 'both, with the cap'],
            [['surcharge_type' => 'percentage'], 60, '{"percentage":2.5,"surcharge_basis":"buyer_pays"}', 'a blank cap is no cap'],
            [['surcharge_type' => 'percentage'], 14, '{"percentage":0,"surcharge_basis":"buyer_pays","cap":0}', 'a stored zero cap is a real cap'],
            [['surcharge_type' => 'percentage'], 90, '{"percentage":0,"surcharge_basis":"buyer_pays"}', 'a term with no row prices nothing'],
            [['surcharge_type' => 'percentage', 'surcharge_rounding_basis' => 'up', 'surcharge_rounding_step' => '0.50'], 60, '{"percentage":2.5,"surcharge_basis":"buyer_pays","rounding":{"step":0.5,"basis":"UP"}}', 'rounding with a percentage'],
            [['surcharge_type' => 'fixed', 'surcharge_rounding_basis' => 'up', 'surcharge_rounding_step' => '0.50'], 30, '{"percentage":0,"surcharge_basis":"buyer_pays","surcharge":2}', 'no rounding on a fixed fee'],
            [['surcharge_type' => 'percentage', 'surcharge_rounding_basis' => 'up', 'surcharge_rounding_step' => '0.33'], 60, '{"percentage":2.5,"surcharge_basis":"buyer_pays"}', 'a step not offered sends no rounding'],
            [['surcharge_type' => 'percentage', 'surcharge_differential' => '1'], 60, '{"percentage":2.5,"surcharge_basis":"buyer_pays","reference_terms":{"type":"NET_TERMS","duration_days":30}}', 'fee difference sends the default term as reference'],
        ];
        foreach ($cases as [$settings, $days, $expected, $description]) {
            $share = self::surcharge($settings)->feeShare($days, 30, false);
            TinyAssert::same($expected, $share === null ? null : json_encode($share), 'fee share: ' . $description);
        }
        TinyAssert::throws(static fn () => self::surcharge(['surcharge_type' => 'tiered'])->feeShare(30, 30, false), \UnexpectedValueException::class, 'fee share: an unknown stored method is refused, never priced as none');
    }

    private static function testPricesFee(): void
    {
        $cases = [
            [['surcharge_type' => 'percentage'], 60, true, 'a percentage prices a fee'],
            [['surcharge_type' => 'percentage'], 14, false, 'a cap alone prices nothing'],
            [['surcharge_type' => 'fixed'], 60, false, 'a fixed fee of nothing prices nothing'],
            [['surcharge_type' => 'percentage', 'surcharge_differential' => '1'], 30, false, 'in fee-difference mode the default term is its own reference'],
            [['surcharge_type' => 'fixed_and_percentage', 'surcharge_differential' => '1'], 30, true, 'unless a fixed fee rides along'],
            [['surcharge_type' => 'none'], 30, false, 'no surcharge'],
        ];
        foreach ($cases as [$settings, $days, $expected, $description]) {
            TinyAssert::same($expected, self::surcharge($settings)->pricesFee($days, 30, false), 'prices a fee: ' . $description);
        }
    }

    private static function testLabel(): void
    {
        $cases = [
            [[], false, 'Fee 30', 'the language\'s sentence'],
            [[], true, 'Fee 30 EOM', 'the end-of-month sentence'],
            [['surcharge_line_description' => 'Terms surcharge (%s days)'], true, 'Terms surcharge (30 days)', 'the merchant\'s own, with the days'],
        ];
        foreach ($cases as [$settings, $eom, $expected, $description]) {
            TinyAssert::same($expected, self::surcharge($settings)->label(30, $eom, 'Fee %d', 'Fee %d EOM'), 'label: ' . $description);
        }
    }

    private static function testNumber(): void
    {
        foreach ([['1,5', '1.5'], ['1,50', '1.50'], ['1,000', null], ['2.5', '2.5'], ['abc', null], [' 3 ', '3'], ['', null]] as [$raw, $expected]) {
            TinyAssert::same($expected, Surcharge::number($raw), 'number: "' . $raw . '"');
        }
    }

    private static function testValidate(): void
    {
        $on = ['surcharge_type' => 'percentage', 'surcharge_tax_class_id' => '9'];
        $cases = [
            [['surcharge_type' => 'tiered'], [], ['surcharge_type' => Surcharge::TYPE_UNKNOWN], 'an unknown method'],
            [['surcharge_type' => 'percentage'], [], ['surcharge_tax_class_id' => Surcharge::TAX_CLASS_MISSING], 'a surcharge with no tax class'],
            [['surcharge_tax_class_id' => '0'] + $on, [], ['surcharge_tax_class_id' => Surcharge::TAX_CLASS_MISSING], 'none is not a tax class: a 0% class says untaxed'],
            [['surcharge_tax_class_id' => '7'] + $on, [], ['surcharge_tax_class_id' => Surcharge::TAX_CLASS_UNKNOWN], 'a tax class the shop does not have'],
            [['surcharge_type' => 'none'], ['surcharge_type' => 'none', 'surcharge_tax_class_id' => ''], [], 'no surcharge needs no tax class'],
            [$on, ['surcharge_type' => 'percentage', 'surcharge_tax_class_id' => '9'], [], 'a surcharge with a tax class'],
            [['surcharge_rounding_step' => '0.33'] + $on, [], ['surcharge_rounding_step' => Surcharge::STEP_UNKNOWN], 'a step not offered'],
            [['surcharge_rounding_basis' => 'sideways'] + $on, [], ['surcharge_rounding_basis' => Surcharge::BASIS_UNKNOWN], 'an unknown rounding'],
            [['surcharge_grid' => ['30' => ['percentage' => '1,5', 'fixed' => '', 'cap' => '5']]] + $on, ['surcharge_grid' => '{"30":{"percentage":"1.5","fixed":"","cap":"5"},"60":{"percentage":"","fixed":"","cap":""}}'], [], 'a valid grid, decimal comma included'],
            [['surcharge_grid' => ['30' => ['percentage' => '-1'], '60' => ['fixed' => 'x']]] + $on, [], ['surcharge_grid' => 'surcharge_cell_invalid:percentage:30,surcharge_cell_invalid:fixed:60'], 'a negative or non-numeric cell names each'],
            [['surcharge_grid' => ['30' => ['percentage' => '100'], '60' => ['percentage' => '250']]] + $on, ['surcharge_grid' => '{"30":{"percentage":"100","fixed":"","cap":""},"60":{"percentage":"250","fixed":"","cap":""}}'], [], 'a percentage has no upper bound, only a floor of 0 (TWO-27110)'],
            [['surcharge_grid' => ['30' => ['cap' => '0.001']]] + $on, [], ['surcharge_grid' => 'surcharge_cap_zero:cap:30'], 'a cap that rounds to zero with a percentage'],
            [['surcharge_type' => 'fixed', 'surcharge_grid' => ['30' => ['cap' => '0']]] + $on, ['surcharge_grid' => '{"30":{"percentage":"","fixed":"","cap":"0"},"60":{"percentage":"","fixed":"","cap":""}}'], [], 'a hidden zero cap on a fixed fee is kept, not refused'],
        ];
        foreach ($cases as [$submitted, $written, $errors, $description]) {
            $result = Surcharge::validate($submitted, [30, 60], [9]);
            TinyAssert::same($errors, $result['errors'], 'validate errors: ' . $description);
            TinyAssert::same($written, array_intersect_key($result['write'], $written), 'validate writes: ' . $description);
        }
    }

    /** @param array<string, string> $settings */
    private static function surcharge(array $settings): Surcharge
    {
        return Surcharge::from(new SettingsProvider(new MemoryStore($settings + ['surcharge_grid' => self::GRID])));
    }
}
