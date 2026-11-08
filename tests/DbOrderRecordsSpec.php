<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\DbOrderRecords;

/**
 * The upgrade schema step (TWO-26300): OpenCart's Installer only copies
 * files, so the settings page and the pending check add the `two_order`
 * columns install() would have added. Each case is the table before, the
 * columns added on the first run, and that a second run adds none.
 */
final class DbOrderRecordsSpec
{
    public static function runAll(): void
    {
        $all = array_merge(['order_id'], DbOrderRecords::FIELDS, ['date_added', 'date_modified']);
        $added = array_keys(DbOrderRecords::ADDED_COLUMNS);
        $base = array_values(array_diff($all, $added));
        $cases = [
            // table exists, its columns, columns the first run adds, description
            [true, $all, [], 'every column present: nothing altered'],
            [true, array_values(array_diff($all, ['checked_at', 'missing_at', 'check_closed'])), ['checked_at', 'missing_at', 'check_closed'], 'a build before the pending check: its three columns added'],
            [true, $base, $added, 'the first shape: every added column, in order'],
            [true, array_values(array_diff($all, ['token_hash'])), [], 'another shape (no token column): left to install()'],
            [false, [], [], 'no table: left to install()'],
        ];
        foreach ($cases as [$exists, $columns, $expected, $description]) {
            $altered = [];
            $query = static function (string $sql) use ($exists, &$columns, &$altered): array {
                if (str_starts_with($sql, 'SHOW TABLES')) {
                    return $exists ? [['Tables' => 'oc_two_order']] : [];
                }
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    return array_map(static fn (string $field): array => ['Field' => $field], $columns);
                }
                if (preg_match('~^ALTER TABLE `oc_two_order` ADD COLUMN `([a-z_]+)` ~', $sql, $match) === 1) {
                    $altered[] = $match[1];
                    $columns[] = $match[1];

                    return [];
                }
                throw new RuntimeException('unexpected statement: ' . $sql);
            };
            $d = 'two_order upgrade: ' . $description;
            TinyAssert::same($expected, DbOrderRecords::addMissingColumns($query, 'oc_'), $d . ': columns reported added');
            TinyAssert::same($expected, $altered, $d . ': ALTER statements run');
            TinyAssert::same([], DbOrderRecords::addMissingColumns($query, 'oc_'), $d . ': a second run adds nothing');
            TinyAssert::same($expected, $altered, $d . ': and alters nothing');
        }
    }
}
