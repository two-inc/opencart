<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\SettingsStore;

/**
 * The extension's rows in OpenCart's cron table: the daily merchant-record
 * refresh and the hourly pending check. install() replaces them; the
 * settings page and `dev/configure.php` add a missing one once per extension
 * version (TWO-27148).
 */
final class CronRows
{
    /** The extension version that last registered the rows. */
    public const VERSION_KEY = 'cron_rows_version';

    /** The rows, by code: description and cycle. */
    private const ROWS = [
        Services::CRON_CODE => [Services::CRON_DESCRIPTION, Services::CRON_CYCLE],
        Services::PENDING_CRON_CODE => [Services::PENDING_CRON_DESCRIPTION, Services::PENDING_CRON_CYCLE],
    ];

    /** install() replaces the rows, enabled, and stores the version. */
    public static function install(CronTable $table, SettingsStore $store, string $version): void
    {
        foreach (self::ROWS as $code => [$description, $cycle]) {
            $table->delete($code);
            $table->add($code, $description, $cycle, Services::CRON_ACTION);
        }
        $store->setMany([self::VERSION_KEY => $version]);
    }

    /**
     * Adds a missing row once per extension version, since an upgrade copies
     * files and never runs install() again, then stores the version. A row
     * the merchant deleted stays deleted until the next upgrade; one they
     * disabled is kept as it is.
     *
     * @return list<string> the codes added
     */
    public static function upgrade(CronTable $table, SettingsStore $store, string $version): array
    {
        if ($store->get(self::VERSION_KEY) === $version) {
            return [];
        }
        $added = [];
        foreach (self::ROWS as $code => [$description, $cycle]) {
            if (!$table->exists($code)) {
                $table->add($code, $description, $cycle, Services::CRON_ACTION);
                $added[] = $code;
            }
        }
        $store->setMany([self::VERSION_KEY => $version]);

        return $added;
    }

    /**
     * The table through the admin's `setting/cron` model, as the controller
     * holds it: OpenCart's loader wraps a model in a Proxy, so it is never an
     * instance of the model class.
     *
     * @param \Opencart\System\Engine\Proxy<\Opencart\Admin\Model\Setting\Cron>|\Opencart\Admin\Model\Setting\Cron $model
     */
    public static function onModel(\Opencart\System\Engine\Proxy|\Opencart\Admin\Model\Setting\Cron $model): CronTable
    {
        return new class ($model) implements CronTable {
            /** @param \Opencart\System\Engine\Proxy<\Opencart\Admin\Model\Setting\Cron>|\Opencart\Admin\Model\Setting\Cron $model */
            public function __construct(private readonly \Opencart\System\Engine\Proxy|\Opencart\Admin\Model\Setting\Cron $model)
            {
            }

            public function exists(string $code): bool
            {
                return $this->model->getCronByCode($code) !== [];
            }

            public function add(string $code, string $description, string $cycle, string $action): void
            {
                $this->model->addCron($code, $description, $cycle, $action, true);
            }

            public function delete(string $code): void
            {
                $this->model->deleteCronByCode($code);
            }
        };
    }
}
