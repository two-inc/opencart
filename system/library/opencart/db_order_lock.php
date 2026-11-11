<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\OrderLock;

/**
 * The per-order lock as a MySQL named lock, named from the shop's database
 * and table prefix so two shops on one server never share one. Placement
 * asks without waiting; the callbacks wait up to their own limit.
 */
final class DbOrderLock implements OrderLock
{
    public function __construct(private readonly \Opencart\System\Library\DB $db, private readonly int $waitSeconds = 0)
    {
    }

    /** The lock's name for an order, shared by placement and the callbacks. */
    public static function name(int $orderId): string
    {
        return 'two_order_' . substr(sha1(DB_DATABASE . DB_PREFIX), 0, 12) . '_' . $orderId;
    }

    public function acquire(int $orderId): bool
    {
        $query = $this->db->query("SELECT GET_LOCK('" . self::name($orderId) . "', " . max(0, $this->waitSeconds) . ') AS `held`');

        return is_object($query) && (int) ($query->row['held'] ?? 0) === 1;
    }

    public function release(int $orderId): void
    {
        $this->db->query("SELECT RELEASE_LOCK('" . self::name($orderId) . "')");
    }
}
