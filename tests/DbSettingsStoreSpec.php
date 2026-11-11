<?php

declare(strict_types=1);

use Opencart\System\Library\DB;
use Opencart\System\Library\Extension\Two\Opencart\DbSettingsStore;

/**
 * The OpenCart settings store upserts. OpenCart's own editValue() only
 * UPDATEs, so a key with no row (added after a shop installed the extension)
 * would be lost on save. Each case seeds the setting table, writes through
 * the store, and compares the whole table afterwards.
 */
final class DbSettingsStoreSpec
{
    /** @var list<array{description: string, stored: array<string, string>, write: array<string, string>, expected: array<string, string>}> */
    private const CASES = [
        ['description' => 'a key with no row yet is inserted', 'stored' => [], 'write' => ['status' => '1'], 'expected' => ['payment_two_status' => '1']],
        ['description' => 'an existing key is updated in place, not duplicated', 'stored' => ['payment_two_status' => '0'], 'write' => ['status' => '1'], 'expected' => ['payment_two_status' => '1']],
        ['description' => 'keys not written are left alone', 'stored' => ['payment_two_api_key' => 'k', 'payment_two_status' => '0'], 'write' => ['status' => '1'], 'expected' => ['payment_two_api_key' => 'k', 'payment_two_status' => '1']],
        ['description' => 'one save updates one key and inserts another', 'stored' => ['payment_two_status' => '0'], 'write' => ['status' => '1', 'sort_order' => '3'], 'expected' => ['payment_two_sort_order' => '3', 'payment_two_status' => '1']],
        ['description' => 'a value with quotes round-trips', 'stored' => [], 'write' => ['title' => "Buyer's 'invoice'"], 'expected' => ['payment_two_title' => "Buyer's 'invoice'"]],
    ];

    public static function runAll(): void
    {
        foreach (self::CASES as $case) {
            $db = self::db($case['stored']);
            (new DbSettingsStore($db))->setMany($case['write']);
            TinyAssert::same(self::table($case['expected']), self::table($db), $case['description']);
        }

        self::testOtherStoresAreUntouched();
        self::testGetReadsTheStoredValueOrNull();
    }

    private static function testOtherStoresAreUntouched(): void
    {
        $db = self::db([]);
        $db->settingRows[] = ['store_id' => '1', 'code' => 'payment_two', 'key' => 'payment_two_status', 'value' => '0', 'serialized' => '0'];
        (new DbSettingsStore($db, 0))->setMany(['status' => '1']);
        TinyAssert::same(['0|payment_two_status=1', '1|payment_two_status=0'], self::table($db), 'a write to store 0 leaves store 1 alone');
    }

    private static function testGetReadsTheStoredValueOrNull(): void
    {
        $store = new DbSettingsStore(self::db(['payment_two_environment' => 'sandbox']));
        TinyAssert::same('sandbox', $store->get('environment'), 'get returns the stored value');
        TinyAssert::same(null, $store->get('api_key'), 'get returns null for a key with no row');
    }

    /** @param array<string, string> $stored */
    private static function db(array $stored): DB
    {
        $db = new DB();
        foreach ($stored as $key => $value) {
            $db->settingRows[] = ['store_id' => '0', 'code' => 'payment_two', 'key' => $key, 'value' => $value, 'serialized' => '0'];
        }

        return $db;
    }

    /**
     * The table as sorted "store|key=value" lines, so a duplicated row shows.
     *
     * @param DB|array<string, string> $source
     * @return list<string>
     */
    private static function table(DB|array $source): array
    {
        $lines = [];
        if ($source instanceof DB) {
            foreach ($source->settingRows as $row) {
                $lines[] = $row['store_id'] . '|' . $row['key'] . '=' . $row['value'];
            }
        } else {
            foreach ($source as $key => $value) {
                $lines[] = '0|' . $key . '=' . $value;
            }
        }
        sort($lines);

        return $lines;
    }
}
