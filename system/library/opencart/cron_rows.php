<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\SettingsStore;

/**
 * The extension's rows in OpenCart's cron table: the daily merchant-record
 * refresh and the hourly pending check. Each method takes the admin's
 * `setting/cron` model as the controller holds it: OpenCart's loader wraps
 * a model in a Proxy, so it is never an instance of the model class.
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

    /**
     * install() replaces the rows, enabled, and stores the version.
     *
     * @param \Opencart\System\Engine\Proxy<\Opencart\Admin\Model\Setting\Cron>|\Opencart\Admin\Model\Setting\Cron $cron
     */
    public static function install(\Opencart\System\Engine\Proxy|\Opencart\Admin\Model\Setting\Cron $cron, SettingsStore $store, string $version): void
    {
        foreach (self::ROWS as $code => [$description, $cycle]) {
            $cron->deleteCronByCode($code);
            $cron->addCron($code, $description, $cycle, Services::CRON_ACTION, true);
        }
        $store->setMany([self::VERSION_KEY => $version]);
    }

    /**
     * The settings page adds a missing row once per extension version, since
     * an upgrade copies files and never runs install() again. A row the
     * merchant deleted stays deleted until the next upgrade (TWO-27148); one
     * they disabled is kept as it is.
     *
     * @param \Opencart\System\Engine\Proxy<\Opencart\Admin\Model\Setting\Cron>|\Opencart\Admin\Model\Setting\Cron $cron
     */
    public static function upgrade(\Opencart\System\Engine\Proxy|\Opencart\Admin\Model\Setting\Cron $cron, SettingsStore $store, string $version): void
    {
        if ($store->get(self::VERSION_KEY) === $version) {
            return;
        }
        foreach (self::ROWS as $code => [$description, $cycle]) {
            if ($cron->getCronByCode($code) === []) {
                $cron->addCron($code, $description, $cycle, Services::CRON_ACTION, true);
            }
        }
        $store->setMany([self::VERSION_KEY => $version]);
    }
}
