<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Event;

use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Opencart\NoteText;
use Opencart\System\Library\Extension\Two\Opencart\OrderStatusEvent;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * The order lifecycle off OpenCart's order history: after an order moves
 * into a status, send any edit of the order to Two, then fulfil, cancel or
 * refund its Two order as the status maps say (`OrderStatusEvent`). Registered on
 * `catalog/model/checkout/order.addHistory/after` (Services::EVENT_TRIGGER).
 * Admin status changes run the catalog's order model in a store instance,
 * so this sees them too.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Engine\Event $event
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\Catalog\Model\Checkout\Order $model_checkout_order
 * @property-read \Opencart\Catalog\Model\Extension\Two\Payment\Two $model_extension_two_payment_two
 */
class Two extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/two/payment/two';

    private const MODEL_ROUTE = 'checkout/order.addHistory';

    /**
     * @param array<int, mixed> $args addHistory's: order id, status id, comment, notify, override
     */
    public function history(string &$route = '', array &$args = [], mixed &$output = null): void
    {
        // Also reachable as a URL route, with no arguments: only the model event acts.
        if ($route !== self::MODEL_ROUTE || !is_numeric($args[0] ?? null)) {
            return;
        }
        $this->load->model('checkout/order');
        $this->load->model(self::ROUTE);
        $orders = $this->model_checkout_order;
        $model = $this->model_extension_two_payment_two;

        (new OrderStatusEvent(
            static fn (int $orderId): ?array => $orders->getOrder($orderId) ?: null,
            function (array $order): array {
                $services = new Services($this->db, $this->log, (int) $order['store_id']);

                $model = $this->model_extension_two_payment_two;

                return [
                    'lifecycle' => $services->orderLifecycle($this->event),
                    'refunds' => $services->orderRefunds($this->event),
                    'edit' => $services->orderEdit($this->event),
                    // The payload's identity leaves out the callback URLs, so an edit rebuilds without them.
                    'rebuild' => OrderFlow::rebuildFrom(static fn (array $company, array $fields, array $terms): ?array => $model->orderInput((int) $order['order_id'], $company, [], $fields, $terms)),
                    'fulfil' => $services->settings->fulfilStatusIds(),
                    'cancel' => $services->settings->cancelStatusId(),
                    'refund' => $services->settings->refundStatusId(),
                ];
            },
            static fn (int $orderId): ?array => $model->orderRows($orderId),
            function (int $orderId, int $statusId, Message $message) use ($orders): void {
                // In the shop's language, whoever changed the status; override skips the fraud check a second time.
                $this->load->language(self::ROUTE);
                $note = NoteText::render($message, $this->load, $this->language, NoteText::shopCode($this->config));
                $orders->addHistory($orderId, $statusId, $this->language->get('text_history_prefix') . $note, false, true);
            },
            fn (string $line) => $this->log->write($line),
            (int) $this->config->get('config_void_status_id'),
        ))->handle((int) $args[0]);
    }
}
