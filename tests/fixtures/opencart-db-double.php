<?php

declare(strict_types=1);

namespace Opencart\System\Library;

/**
 * Offline stand-in for OpenCart 4's DB class over an in-memory `setting`
 * table. It understands only the statement shapes the extension issues
 * against that table and throws on anything else, so a new query shape fails
 * its spec instead of passing silently. Loaded by tests/bootstrap.php only.
 */
final class DB
{
    /** @var list<array<string, string>> */
    public array $settingRows = [];

    private const PAIR = "~`(\\w+)` = '((?:[^'\\\\]|\\\\.)*)'~";

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function query(string $sql): \stdClass|bool
    {
        $table = '`' . DB_PREFIX . 'setting`';

        if (preg_match('~^SELECT `value` FROM ' . preg_quote($table, '~') . ' WHERE (.+)$~s', $sql, $m)) {
            $rows = array_values(array_map(
                static fn (array $row): array => ['value' => $row['value']],
                array_filter($this->settingRows, fn (array $row): bool => $this->matches($row, $m[1]))
            ));
            $result = new \stdClass();
            $result->num_rows = count($rows);
            $result->row = $rows[0] ?? [];
            $result->rows = $rows;

            return $result;
        }

        if (preg_match('~^UPDATE ' . preg_quote($table, '~') . ' SET (.+?) WHERE (.+)$~s', $sql, $m)) {
            foreach ($this->settingRows as $i => $row) {
                if ($this->matches($row, $m[2])) {
                    $this->settingRows[$i] = self::pairs($m[1]) + $row;
                }
            }

            return true;
        }

        if (preg_match('~^INSERT INTO ' . preg_quote($table, '~') . ' SET (.+)$~s', $sql, $m)) {
            $this->settingRows[] = self::pairs($m[1]);

            return true;
        }

        throw new \RuntimeException('DB double: unsupported statement: ' . $sql);
    }

    /** @param array<string, string> $row */
    private function matches(array $row, string $where): bool
    {
        foreach (self::pairs($where) as $column => $value) {
            if (($row[$column] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private static function pairs(string $clause): array
    {
        preg_match_all(self::PAIR, $clause, $matches, PREG_SET_ORDER);
        $pairs = [];
        foreach ($matches as $match) {
            $pairs[$match[1]] = stripslashes($match[2]);
        }
        if ($pairs === []) {
            throw new \RuntimeException('DB double: no column pairs in: ' . $clause);
        }

        return $pairs;
    }
}
