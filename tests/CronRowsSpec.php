<?php

declare(strict_types=1);

use Opencart\Admin\Model\Setting\Cron;
use Opencart\System\Engine\Proxy;
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
     * Steps, run in order: "install <version>", "load <version>" (the settings page), "delete <code>", "disable <code>".
     *
     * @var list<array{stored: ?string, rows: list<string>, steps: list<string>, expected: array<string, bool>, version: string, description: string}>
     */
    private const CASES = [
        ['stored' => null, 'rows' => [], 'steps' => ['install 2.0.0', 'load 2.0.0'], 'expected' => [self::REFRESH => true, self::PENDING => true], 'version' => '2.0.0', 'description' => 'a fresh install registers both rows and the page adds nothing'],
        ['stored' => null, 'rows' => [], 'steps' => ['install 2.0.0', 'delete ' . self::PENDING, 'load 2.0.0', 'load 2.0.0'], 'expected' => [self::REFRESH => true], 'version' => '2.0.0', 'description' => 'a row deleted after install stays deleted on later loads at the same version'],
        ['stored' => '2.0.0', 'rows' => [self::REFRESH, self::PENDING], 'steps' => ['load 2.0.0', 'delete ' . self::REFRESH, 'load 2.0.0'], 'expected' => [self::PENDING => true], 'version' => '2.0.0', 'description' => 'a row deleted after a load stays deleted on the next load at the same version'],
        ['stored' => null, 'rows' => [], 'steps' => ['install 2.0.0', 'disable ' . self::PENDING, 'load 2.0.0'], 'expected' => [self::REFRESH => true, self::PENDING => false], 'version' => '2.0.0', 'description' => 'a disabled row is kept disabled'],
        ['stored' => null, 'rows' => [], 'steps' => ['install 2.0.0', 'disable ' . self::PENDING, 'load 2.1.0'], 'expected' => [self::REFRESH => true, self::PENDING => false], 'version' => '2.1.0', 'description' => 'an upgrade keeps a disabled row disabled'],
        ['stored' => '2.0.0', 'rows' => [self::REFRESH], 'steps' => ['load 2.1.0'], 'expected' => [self::REFRESH => true, self::PENDING => true], 'version' => '2.1.0', 'description' => 'a files-only upgrade from an older stored version adds the missing row'],
        ['stored' => null, 'rows' => [self::REFRESH], 'steps' => ['load 2.1.0'], 'expected' => [self::REFRESH => true, self::PENDING => true], 'version' => '2.1.0', 'description' => 'a files-only upgrade with no stored version adds the missing row'],
        ['stored' => null, 'rows' => [], 'steps' => ['install 2.0.0', 'delete ' . self::PENDING, 'load 2.0.0', 'load 2.1.0'], 'expected' => [self::REFRESH => true, self::PENDING => true], 'version' => '2.1.0', 'description' => 'a deleted row comes back at the next upgrade'],
        ['stored' => null, 'rows' => [], 'steps' => ['install 2.0.0', 'disable ' . self::PENDING, 'install 2.0.0'], 'expected' => [self::REFRESH => true, self::PENDING => true], 'version' => '2.0.0', 'description' => 'a reinstall replaces the rows, enabled'],
    ];

    public static function runAll(): void
    {
        foreach (self::CASES as $case) {
            $cron = new Cron();
            foreach ($case['rows'] as $code) {
                $cron->addCron($code, '', 'day', Services::CRON_ACTION, true);
            }
            // What the controller passes: OpenCart's loader hands a model over wrapped in a Proxy.
            $model = self::proxied($cron);
            $store = new MemoryStore($case['stored'] === null ? [] : [CronRows::VERSION_KEY => $case['stored']]);
            foreach ($case['steps'] as $step) {
                [$verb, $argument] = explode(' ', $step, 2);
                if ($verb === 'install') {
                    CronRows::install($model, $store, $argument);
                } elseif ($verb === 'load') {
                    CronRows::upgrade($model, $store, $argument);
                } elseif ($verb === 'delete') {
                    $cron->deleteCronByCode($argument);
                } else {
                    $cron->editStatus((int) $cron->getCronByCode($argument)['cron_id'], false);
                }
            }
            $table = [];
            foreach ($cron->getCrons() as $row) {
                $table[(string) $row['code']] = (bool) $row['status'];
            }
            ksort($table);
            $expected = $case['expected'];
            ksort($expected);
            TinyAssert::same($expected, $table, $case['description'] . ' (rows)');
            TinyAssert::same($case['version'], $store->get(CronRows::VERSION_KEY), $case['description'] . ' (stored version)');
        }

        self::testRowsCarryTheirSchedule();
    }

    private static function testRowsCarryTheirSchedule(): void
    {
        $cron = new Cron();
        CronRows::upgrade(self::proxied($cron), new MemoryStore(), '2.0.0');
        $rows = array_map(static fn (array $row): array => [$row['code'], $row['description'], $row['cycle'], $row['action']], $cron->getCrons());
        TinyAssert::same([
            [self::REFRESH, Services::CRON_DESCRIPTION, Services::CRON_CYCLE, Services::CRON_ACTION],
            [self::PENDING, Services::PENDING_CRON_DESCRIPTION, Services::PENDING_CRON_CYCLE, Services::CRON_ACTION],
        ], $rows, 'each row carries its description, cycle and the cron action');
    }

    /**
     * The cron model as OpenCart's loader wraps it: each public method set on a Proxy as a closure.
     *
     * @return Proxy<Cron>
     */
    private static function proxied(Cron $cron): Proxy
    {
        /** @var Proxy<Cron> $proxy the template cannot be inferred from an empty proxy */
        $proxy = new Proxy();
        foreach (get_class_methods($cron) as $method) {
            if (!str_starts_with($method, '__')) {
                $proxy->{$method} = \Closure::fromCallable([$cron, $method]);
            }
        }

        return $proxy;
    }
}
