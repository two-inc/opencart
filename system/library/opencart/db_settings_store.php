<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\SettingsStore;

/**
 * The OpenCart 4 implementation of the core's SettingsStore port, over the
 * shop's `setting` table with the payment_two prefix.
 *
 * Writes upsert. OpenCart's own editValue() only UPDATEs, so a key with no
 * row yet (any key added after a shop installed the extension) would be
 * dropped without a word; editSetting() deletes every key it is not given.
 * Neither fits a selective save, hence this class.
 */
final class DbSettingsStore implements SettingsStore
{
    public const CODE = 'payment_two';

    public function __construct(
        private readonly \Opencart\System\Library\DB $db,
        private readonly int $storeId = 0,
    ) {
    }

    public function get(string $key): ?string
    {
        $query = $this->db->query('SELECT `value` FROM `' . DB_PREFIX . 'setting` WHERE ' . $this->where($key));

        return is_object($query) && $query->num_rows ? (string) $query->row['value'] : null;
    }

    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $set = '`value` = \'' . $this->db->escape($value) . '\', `serialized` = \'0\'';

            if ($this->get($key) === null) {
                $this->db->query('INSERT INTO `' . DB_PREFIX . 'setting` SET `store_id` = \'' . $this->storeId . '\', `code` = \'' . self::CODE . '\', `key` = \'' . $this->db->escape(self::CODE . '_' . $key) . '\', ' . $set);
            } else {
                $this->db->query('UPDATE `' . DB_PREFIX . 'setting` SET ' . $set . ' WHERE ' . $this->where($key));
            }
        }
    }

    private function where(string $key): string
    {
        return '`store_id` = \'' . $this->storeId . '\' AND `code` = \'' . self::CODE . '\' AND `key` = \'' . $this->db->escape(self::CODE . '_' . $key) . '\'';
    }
}
