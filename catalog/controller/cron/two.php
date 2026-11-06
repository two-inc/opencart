<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Cron;

use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\PendingCheck;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Opencart\DbOrderLock;
use Opencart\System\Library\Extension\Two\Opencart\DbOrderRecords;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * The scheduled jobs: the merchant-record refresh and the pending check
 * (TWO-26300), which asks Two about orders the buyer verified but never
 * returned from and places them. Reached three ways.
 *
 * - OpenCart 4's native cron: the merchant's crontab calls
 *   index.php?route=cron/cron (the URL the admin Cron Jobs page shows),
 *   which runs every due row of the cron table and passes a cron id and
 *   code. install() and dev/configure.php register the refresh daily and
 *   the pending check hourly.
 * - Directly, on the URL the Diagnostics tab shows, scheduled every 15
 *   minutes: a web request with no cron id must present the stored token
 *   (query string, POST field or X-Two-Cron-Token header), so the public
 *   route cannot be used to make the shop call Two. Every accepted call runs
 *   the pending check; the refresh runs only when due.
 * - The admin's "Check status with Two" button, through a store instance
 *   (check()), so placing runs in the storefront's context: its order
 *   events and the order mail, as on the buyer's return.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Engine\Event $event
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Response $response
 * @property-read \Opencart\Catalog\Model\Checkout\Order $model_checkout_order
 * @property-read \Opencart\Catalog\Model\Extension\Two\Payment\Two $model_extension_two_payment_two
 */
class Two extends \Opencart\System\Engine\Controller
{
    /** HTTP status per outcome of a direct call: a crontab sees only the code. */
    private const STATUS = [
        RecordProvider::CRON_REFRESHED => '200 OK',
        RecordProvider::CRON_UNCONFIGURED => '200 OK',
        RecordProvider::CRON_FAILED => '502 Bad Gateway',
        RecordProvider::CRON_THROTTLED => '200 OK',
        RecordProvider::CRON_REJECTED => '403 Forbidden',
    ];

    /** How long the button waits for a buyer's return or a scheduled check on the same order. */
    private const BUTTON_WAIT_SECONDS = 10;
    /** A run starts no new check after this many seconds, so a slow Two cannot outlast the request. */
    private const RUN_BUDGET_SECONDS = 20;

    public function index(int $cron_id = 0, string $code = '', string $cycle = '', string $date_added = '', string $date_modified = ''): void
    {
        $services = new Services($this->db, $this->log);
        if ($cron_id > 0) {
            if ($code === Services::CRON_CODE) {
                $services->records->runScheduledRefresh();
            } elseif ($code === Services::PENDING_CRON_CODE) {
                $this->runPendingCheck();
            }

            return;
        }

        $result = $services->records->runTokenRefresh($this->presentedToken());
        $body = $result . "\n";
        if ($result !== RecordProvider::CRON_REJECTED) {
            // A crontab that gives up waiting must not stop a placement half way.
            ignore_user_abort(true);
            $services->settings->store()->setMany([PendingCheck::LAST_RUN_KEY => (string) time()]);
            $body .= 'pending: ' . $this->runPendingCheck() . "\n";
        }
        $this->response->addHeader('HTTP/1.1 ' . self::STATUS[$result]);
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput($body);
    }

    /**
     * The admin's button for one order, called through a store instance of
     * the order's store with the admin user's name. A web request passes no
     * arguments, so it reaches nothing here.
     *
     * @return array{outcome: string, message: Message}|null null when the order id is missing
     */
    public function check(int $order_id = 0, string $by = ''): ?array
    {
        if ($order_id <= 0) {
            return null;
        }
        $result = $this->checkOrder($order_id, OrderFlow::TRIGGER_ADMIN, self::BUTTON_WAIT_SECONDS, Message::of('note_by_admin', $by !== '' ? $by : '-'));

        return $result ?? ['outcome' => 'busy', 'message' => Message::of('note_check_busy')];
    }

    /** One run over the orders due, oldest check first. A short summary for the crontab's output. */
    private function runPendingCheck(): string
    {
        $started = microtime(true);
        $ids = DbOrderRecords::waiting($this->db, (int) $this->config->get('config_void_status_id'), time());
        $counts = [];
        $skipped = 0;
        foreach ($ids as $orderId) {
            if (microtime(true) - $started > self::RUN_BUDGET_SECONDS) {
                break;
            }
            $result = $this->checkOrder($orderId, OrderFlow::TRIGGER_SCHEDULED, 0, null);
            if ($result === null) {
                $skipped++;
                continue;
            }
            $counts[$result['outcome']] = ($counts[$result['outcome']] ?? 0) + 1;
        }
        (new Services($this->db, $this->log))->pendingCheck($this->event)->summary(OrderFlow::TRIGGER_SCHEDULED, $counts, $skipped);

        return 'checked ' . array_sum($counts) . ' of ' . count($ids) . ', placed ' . ($counts[OrderFlow::CONFIRMED] ?? 0) . ', skipped ' . $skipped;
    }

    /**
     * Checks one order under its lock (the lock the buyer's return and
     * placement take), and only while it is still an unplaced Two order. The
     * order is read again before any write.
     *
     * @return array{outcome: string, message: Message}|null null when another request holds the order
     */
    private function checkOrder(int $orderId, string $trigger, int $waitSeconds, ?Message $by): ?array
    {
        $lock = new DbOrderLock($this->db, $waitSeconds);
        if (!$lock->acquire($orderId)) {
            return null;
        }
        try {
            $this->load->model('checkout/order');
            $this->load->model('extension/two/payment/two');
            $model = $this->model_extension_two_payment_two;
            $order = $this->model_checkout_order->getOrder($orderId);
            if (!$this->waiting($order)) {
                return ['outcome' => 'not_waiting', 'message' => Message::of('note_check_placed')];
            }
            // The order's own store: its API key and status settings.
            $services = new Services($this->db, $this->log, (int) $order['store_id']);
            $rebuild = OrderFlow::rebuildFrom(static fn (array $company, array $fields, array $terms): ?array => $model->orderInput($orderId, $company, [], $fields, $terms));
            $result = $services->pendingCheck($this->event)->check($orderId, $trigger, $rebuild, $model->orderRows($orderId), $by);

            $order = $this->model_checkout_order->getOrder($orderId);
            if (!$this->waiting($order)) {
                return ['outcome' => 'not_waiting', 'message' => Message::of('note_check_placed')];
            }
            if ($result['place']) {
                $model->place($orderId, $services->settings->placedStatusId((int) $this->config->get('config_order_status_id')), $result['message']);
            } elseif ($result['note']) {
                $model->historyNote($orderId, (int) $order['order_status_id'], $result['message']);
            }

            return ['outcome' => $result['outcome'], 'message' => $result['message']];
        } finally {
            $lock->release($orderId);
        }
    }

    /**
     * An unplaced order still paid with Two: one whose buyer switched to
     * another method is never adopted.
     *
     * @param array<string, mixed> $order getOrder's answer, empty when there is no such order
     */
    private function waiting(array $order): bool
    {
        if ($order === [] || !$this->model_extension_two_payment_two->unplaced($order)) {
            return false;
        }
        $method = $order['payment_method'] ?? null;
        $method = is_string($method) ? json_decode($method, true) : $method;

        return is_array($method) && ($method['code'] ?? '') === 'two.two';
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
