<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\DbOrderRecords;

/**
 * The upgrade schema step (TWO-26300): OpenCart's Installer only copies
 * files, so the settings page and the pending check add the `two_order`
 * columns install() would have added, and say so, at most hourly, when they
 * cannot.
 */
final class DbOrderRecordsSpec
{
    private const NOW = 1_800_000_000;

    public static function runAll(): void
    {
        self::testAddMissingColumns();
        self::testUpgrade();
    }

    private static function testAddMissingColumns(): void
    {
        $cases = [
            // table exists, its columns, columns the first run adds, description
            [true, self::all(), [], 'every column present: nothing altered'],
            [true, self::without(['checked_at', 'missing_at', 'check_closed']), ['checked_at', 'missing_at', 'check_closed'], 'a build before the pending check: its three columns added'],
            [true, self::without(['tax_codes']), ['tax_codes'], 'a build before the tax code record (TWO-27110): its column added'],
            [true, self::without(array_keys(DbOrderRecords::ADDED_COLUMNS)), array_keys(DbOrderRecords::ADDED_COLUMNS), 'the first shape: every added column, in order'],
            [true, self::without(['token_hash']), [], 'another shape (no token column): left to install()'],
            [false, [], [], 'no table: left to install()'],
        ];
        foreach ($cases as [$exists, $columns, $expected, $description]) {
            $table = self::table($exists, $columns, 'ok');
            $d = 'two_order upgrade: ' . $description;
            TinyAssert::same($expected, DbOrderRecords::addMissingColumns($table['query'], 'oc_'), $d . ': columns reported added');
            TinyAssert::same($expected, $table['altered'](), $d . ': ALTER statements run');
            TinyAssert::same([], DbOrderRecords::addMissingColumns($table['query'], 'oc_'), $d . ': a second run adds nothing');
            TinyAssert::same($expected, $table['altered'](), $d . ': and alters nothing');
        }
    }

    /** upgradeWith: whether the check can select, and errors logged at most once an hour while it cannot. */
    private static function testUpgrade(): void
    {
        $pending = self::without(['checked_at', 'missing_at', 'check_closed']);
        $cases = [
            // table exists, its columns, how an ALTER answers, can select, errors after runs at 0 s, 60 s and 3600 s, error text, description
            [true, self::all(), 'ok', true, [0, 0, 0], '', 'every column present'],
            [true, $pending, 'ok', true, [0, 0, 0], '', 'columns missing and added'],
            [true, $pending, 'race', true, [0, 0, 0], '', 'a concurrent request added them first: the refused ALTER logs nothing'],
            [true, $pending, 'refuse', false, [1, 1, 2], 'adding them failed: ALTER command denied', 'ALTER refused: logged once an hour'],
            [false, [], 'ok', false, [1, 1, 2], 'the table is absent or of another shape', 'no table: no exception, logged once an hour'],
            [true, [], 'unreadable', false, [1, 1, 2], 'adding them failed: SHOW command denied', 'columns unreadable: no exception, counted as missing'],
            [true, self::without(['tax_codes']), 'refuse', true, [1, 1, 2], 'the tax_codes column is missing, so orders keep no record of the tax codes they were placed with: adding them failed: ALTER command denied', 'the tax code record column refused alone (TWO-27110): the check still selects, the missing record logged once an hour'],
            [true, self::without(['tax_codes']), 'ok', true, [0, 0, 0], '', 'the tax code record column missing and added: nothing logged'],
        ];
        foreach ($cases as [$exists, $columns, $alter, $present, $errors, $text, $description]) {
            $table = self::table($exists, $columns, $alter);
            $logger = new RecordingLogger();
            $store = new MemoryStore([]);
            $d = 'two_order upgrade, logged: ' . $description;
            $seen = [];
            foreach ([0, 60, 3_600] as $i => $after) {
                TinyAssert::same($present, DbOrderRecords::upgradeWith($table['query'], 'oc_', $logger, $store, self::NOW + $after), $d . ': can select after ' . $after . ' s');
                $seen[$i] = count($logger->at('error'));
            }
            TinyAssert::same($errors, $seen, $d . ': errors logged after each run');
            if ($text !== '') {
                TinyAssert::same(true, str_contains($logger->at('error')[0], $text), $d . ': the error says why');
            }
        }
        TinyAssert::same(false, DbOrderRecords::columnsPresent(self::table(false, [], 'ok')['query'], 'oc_'), 'two_order columns present: no table is false, not an exception');
    }

    /**
     * A fake `oc_two_order` behind the query closure.
     *
     * @param list<string> $columns
     * @param string $alter ok: the column is added; refuse: the ALTER throws; race: another request added it, then the ALTER throws; unreadable: SHOW COLUMNS throws
     * @return array{query: \Closure(string): list<array<string, mixed>>, altered: \Closure(): list<string>}
     */
    private static function table(bool $exists, array $columns, string $alter): array
    {
        $altered = [];
        $query = static function (string $sql) use ($exists, &$columns, &$altered, $alter): array {
            if (str_starts_with($sql, 'SHOW TABLES')) {
                return $exists ? [['Tables' => 'oc_two_order']] : [];
            }
            if (str_starts_with($sql, 'SHOW COLUMNS')) {
                if ($alter === 'unreadable') {
                    throw new RuntimeException('SHOW command denied');
                }

                return array_map(static fn (string $field): array => ['Field' => $field], $columns);
            }
            if (preg_match('~^ALTER TABLE `oc_two_order` ADD COLUMN `([a-z_]+)` ~', $sql, $match) === 1) {
                if ($alter === 'refuse') {
                    throw new RuntimeException('ALTER command denied');
                }
                $columns = array_values(array_unique(array_merge($columns, $alter === 'race' ? array_keys(DbOrderRecords::ADDED_COLUMNS) : [$match[1]])));
                if ($alter === 'race') {
                    throw new RuntimeException("Duplicate column name '" . $match[1] . "'");
                }
                $altered[] = $match[1];

                return [];
            }
            throw new RuntimeException('unexpected statement: ' . $sql);
        };

        return ['query' => $query, 'altered' => static function () use (&$altered): array {
            return $altered;
        }];
    }

    /** @return list<string> every column install() creates */
    private static function all(): array
    {
        return array_values(array_unique(array_merge(['order_id'], DbOrderRecords::FIELDS, array_keys(DbOrderRecords::ADDED_COLUMNS), ['date_added', 'date_modified'])));
    }

    /**
     * @param list<string> $missing
     * @return list<string>
     */
    private static function without(array $missing): array
    {
        return array_values(array_diff(self::all(), $missing));
    }
}
