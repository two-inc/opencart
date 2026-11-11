<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\RateLimiter;

/**
 * The per-caller rate-limit counts (RateLimiter::CALLER_LIMITS) in the
 * shop's database: one row per hashed caller and action. install() and
 * configure.php create the table.
 *
 * Each request counts itself in one atomic upsert, so parallel requests
 * never read the same count, and rows past RateLimiter::PRUNE_AFTER are
 * pruned as requests come in, so the table cannot grow without bound.
 */
final class DbRateLimits
{
    public const TABLE = 'two_rate_limit';

    /** Prune on one request in this many. */
    private const PRUNE_EVERY = 50;

    public function __construct(private readonly \Opencart\System\Library\DB $db)
    {
    }

    public static function createTableSql(string $prefix): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE . '` ('
            . '`rate_key` CHAR(64) NOT NULL,'
            . '`window_start` INT(11) UNSIGNED NOT NULL,'
            . '`hit_count` INT(11) UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`rate_key`),'
            . 'KEY `window_start` (`window_start`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    }

    /** Counts one request by this caller; whether it is within the action's limit. */
    public function allow(string $action, string $identity, int $now): bool
    {
        if (!isset(RateLimiter::CALLER_LIMITS[$action])) {
            return true;
        }
        [$max, $window] = RateLimiter::CALLER_LIMITS[$action];
        $key = $this->db->escape(RateLimiter::key($action, $identity));
        $expired = $now - $window;
        // MySQL applies the assignments left to right: hit_count reads the old window_start.
        $this->db->query('INSERT INTO `' . DB_PREFIX . self::TABLE . "` (`rate_key`, `window_start`, `hit_count`) VALUES ('" . $key . "', " . $now . ', 1)'
            . ' ON DUPLICATE KEY UPDATE `hit_count` = IF(`window_start` <= ' . $expired . ', 1, `hit_count` + 1), `window_start` = IF(`window_start` <= ' . $expired . ', ' . $now . ', `window_start`)');
        $query = $this->db->query('SELECT `hit_count` FROM `' . DB_PREFIX . self::TABLE . "` WHERE `rate_key` = '" . $key . "'");
        if (random_int(1, self::PRUNE_EVERY) === 1) {
            $this->db->query('DELETE FROM `' . DB_PREFIX . self::TABLE . '` WHERE `window_start` < ' . ($now - RateLimiter::PRUNE_AFTER));
        }

        return !is_object($query) || (int) ($query->row['hit_count'] ?? 0) <= $max;
    }
}
