<?php

declare(strict_types=1);

use Opencart\Admin\Model\Setting\Cron;
use Opencart\System\Library\Extension\Two\Opencart\CronRows;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * The extension's rows in OpenCart's cron table (TWO-27148). install()
 * replaces them; the settings page adds a missing one only once per
 * extension version, so a row the merchant deleted stays deleted until the
 * next upgrade, and a row they disabled stays disabled. Each case seeds the
 * cron table and the stored version, runs its steps, and compares the
 * table (code => enabled) and the stored version afterwards.
 */
final class CronRowsSpec
{
    private const REFRESH = Services::CRON_CODE;
    private const PENDING = Services::PENDING_CRON_CODE;

    /**
     * Steps: ['install', version], ['load', version] (the settings page), ['delete', code], ['disable', code].
     *
     * @var list<array{0: ?string, 1: list<string>, 2: list<array{0: string, 1: string}>, 3: array<string, bool>, 4: ?string, 5: string}>
     */
    private const CASES = [
        // stored version, rows at start, steps, rows expected, version expected, description
        [null, [], [['install', '2.0.0'], ['load', '2.0.0']], [self::REFRESH => true, self::PENDING => true], '2.0.0', 'a fresh install registers both rows and the page adds nothing'],
        [null, [], [['install', '2.0.0'], ['delete', self::PENDING], ['load', '2.0.0'], ['load', '2.0.0']], [self::REFRESH => true], '2.0.0', 'a row deleted after install stays deleted on later loads at the same version'],
        ['2.0.0', [self::REFRESH, self::PENDING], [['load', '2.0.0'], ['delete', self::REFRESH], ['load', '2.0.0']], [self::PENDING => true], '2.0.0', 'a row deleted after a load stays deleted on the next load at the same version'],
        [null, [], [['install', '2.0.0'], ['disable', self::PENDING], ['load', '2.0.0']], [self::REFRESH => true, self::PENDING => false], '2.0.0', 'a disabled row is kept disabled'],
        [null, [], [['install', '2.0.0'], ['disable', self::PENDING], ['load', '2.1.0']], [self::REFRESH => true, self::PENDING => false], '2.1.0', 'an upgrade keeps a disabled row disabled'],
        ['2.0.0', [self::REFRESH], [['load', '2.1.0']], [self::REFRESH => true, self::PENDING => true], '2.1.0', 'a files-only upgrade from an older stored version adds the missing row'],
        [null, [self::REFRESH], [['load', '2.1.0']], [self::REFRESH => true, self::PENDING => true], '2.1.0', 'a files-only upgrade with no stored version adds the missing row'],
        [null, [], [['install', '2.0.0'], ['delete', self::PENDING], ['load', '2.0.0'], ['load', '2.1.0']], [self::REFRESH => true, self::PENDING => true], '2.1.0', 'a deleted row comes back at the next upgrade'],
        [null, [], [['install', '2.0.0'], ['disable', self::PENDING], ['install', '2.0.0']], [self::REFRESH => true, self::PENDING => true], '2.0.0', 'a reinstall replaces the rows, enabled'],
    ];

    public static function runAll(): void
    {
        foreach (self::CASES as [$stored, $rows, $steps, $expectedRows, $expectedVersion, $description]) {
            $cron = new Cron();
            foreach ($rows as $code) {
                $cron->addCron($code, '', 'day', Services::CRON_ACTION, true);
            }
            $store = new MemoryStore($stored === null ? [] : [CronRows::VERSION_KEY => $stored]);
            foreach ($steps as [$step, $argument]) {
                match ($step) {
                    'install' => CronRows::install($cron, $store, $argument),
                    'load' => CronRows::upgrade($cron, $store, $argument),
                    'delete' => $cron->deleteCronByCode($argument),
                    'disable' => $cron->editStatus((int) $cron->getCronByCode($argument)['cron_id'], false),
                };
            }
            $table = [];
            foreach ($cron->getCrons() as $row) {
                $table[(string) $row['code']] = (bool) $row['status'];
            }
            ksort($table);
            ksort($expectedRows);
            TinyAssert::same($expectedRows, $table, $description . ' (rows)');
            TinyAssert::same($expectedVersion, $store->get(CronRows::VERSION_KEY), $description . ' (stored version)');
        }

        self::testRowsCarryTheirSchedule();
    }

    private static function testRowsCarryTheirSchedule(): void
    {
        $cron = new Cron();
        CronRows::upgrade($cron, new MemoryStore(), '2.0.0');
        $rows = array_map(static fn (array $row): array => [$row['code'], $row['description'], $row['cycle'], $row['action']], $cron->getCrons());
        TinyAssert::same([
            [self::REFRESH, Services::CRON_DESCRIPTION, Services::CRON_CYCLE, Services::CRON_ACTION],
            [self::PENDING, Services::PENDING_CRON_DESCRIPTION, Services::PENDING_CRON_CYCLE, Services::CRON_ACTION],
        ], $rows, 'each row carries its description, cycle and the cron action');
    }
}
