<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Cron;

use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * The scheduled merchant-record refresh, reached two ways.
 *
 * - OpenCart 4's native cron: the merchant's crontab calls
 *   index.php?route=cron/cron (the URL the admin Cron Jobs page shows),
 *   which runs every due row of the cron table and passes a cron id.
 *   install() and dev/configure.php register this one daily.
 * - Directly, on the URL the Diagnostics tab shows: a web request with no
 *   cron id must present the stored refresh token (query string, POST
 *   field or X-Two-Cron-Token header), so the public route cannot be used
 *   to make the shop call Two. Accepted calls are throttled.
 *
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Response $response
 */
class Two extends \Opencart\System\Engine\Controller
{
    /** HTTP status per outcome of a direct call: a crontab sees only the code. */
    private const STATUS = [
        RecordProvider::CRON_REFRESHED => '200 OK',
        RecordProvider::CRON_UNCONFIGURED => '200 OK',
        RecordProvider::CRON_FAILED => '502 Bad Gateway',
        RecordProvider::CRON_THROTTLED => '429 Too Many Requests',
        RecordProvider::CRON_REJECTED => '403 Forbidden',
    ];

    public function index(int $cron_id = 0, string $code = '', string $cycle = '', string $date_added = '', string $date_modified = ''): void
    {
        $records = (new Services($this->db, $this->log))->records;
        if ($cron_id > 0) {
            if ($code === Services::CRON_CODE) {
                $records->runScheduledRefresh();
            }

            return;
        }

        $result = $records->runTokenRefresh($this->presentedToken());
        $this->response->addHeader('HTTP/1.1 ' . self::STATUS[$result]);
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput($result . "\n");
    }

    private function presentedToken(): string
    {
        foreach ([$this->request->post['token'] ?? null, $this->request->get['token'] ?? null, $this->request->server['HTTP_X_TWO_CRON_TOKEN'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
