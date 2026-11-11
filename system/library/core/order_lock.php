<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: an exclusive lock on one shop order, so two placements of the same
 * order never run side by side (a second press of Confirm, a retry while the
 * first is still talking to Two). The adapter holds it in the database.
 */
interface OrderLock
{
    /** Whether the lock was taken; false at once when another request holds it. */
    public function acquire(int $orderId): bool;

    public function release(int $orderId): void;
}
