<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

/**
 * OpenCart's cron table, as CronRows needs it. The admin reaches it through
 * the `setting/cron` model (`CronRows::onModel`); `dev/configure.php`, which
 * has no registry, straight through the database.
 */
interface CronTable
{
    public function exists(string $code): bool;

    /** Adds the row, enabled. */
    public function add(string $code, string $description, string $cycle, string $action): void;

    public function delete(string $code): void;
}
