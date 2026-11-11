<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\OrderEdit;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\OrderRefunds;

/**
 * What the order history event does once an order has moved into a status:
 * send an edit of the order's rows to Two (OrderEdit), then fulfil, cancel
 * or refund its Two order as the status maps say. OpenCart's order edit
 * writes the edited rows and then adds a history line, so this sees edits. The controller
 * (`catalog/controller/event/two.php`) passes the shop in as closures, so
 * this runs offline in the specs.
 *
 * - Orders paid another way, and unplaced orders (status 0 or the shop's
 *   void status), are left alone: a history line written on an unplaced
 *   order, such as a refused confirm's note, never cancels a live Two order.
 * - The status change has already been written. The outcome is added to the
 *   history at the same status, never by changing it back.
 * - That note goes through addHistory too, so the handler does not re-enter
 *   itself while it runs.
 * - Anything thrown is caught, logged and noted: a failure at Two never
 *   breaks the merchant's status change.
 */
final class OrderStatusEvent
{
    public const PAYMENT_CODE = 'two.two';

    private static bool $running = false;

    /**
     * @param \Closure(int): (array<string, mixed>|null)                                                                $readOrder    the shop order, as OpenCart's getOrder gives it
     * @param \Closure(array<string, mixed>): array{lifecycle: OrderLifecycle, refunds: OrderRefunds, edit: OrderEdit, rebuild: \Closure, fulfil: list<int>, cancel: int|null, refund: int|null} $lifecycleFor
     *        the lifecycle, refunds, edit sync, the order rebuild for an edit, and the status maps of the order's store
     * @param \Closure(int): (array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>, currency?: string}|null) $rows the order's own rows
     * @param \Closure(int, int, Message): void                                                                         $note         adds a history line at a status, in the shop's language
     * @param \Closure(string): void                                                                                    $logError
     */
    public function __construct(
        private readonly \Closure $readOrder,
        private readonly \Closure $lifecycleFor,
        private readonly \Closure $rows,
        private readonly \Closure $note,
        private readonly \Closure $logError,
        private readonly int $voidStatusId,
    ) {
    }

    public function handle(int $orderId): void
    {
        if (self::$running) {
            return;
        }
        $order = ($this->readOrder)($orderId);
        if (!$order || (string) ($order['payment_method']['code'] ?? '') !== self::PAYMENT_CODE) {
            return;
        }
        $statusId = (int) $order['order_status_id'];
        if ($statusId === 0 || $statusId === $this->voidStatusId) {
            return;
        }

        self::$running = true;
        $action = null;
        try {
            $setup = ($this->lifecycleFor)($order);
            $action = OrderLifecycle::action($statusId, $setup['fulfil'], $setup['cancel'], $setup['refund']);
            $rows = ($this->rows)($orderId);
            $edit = $setup['edit']->sync($orderId, $rows, $setup['rebuild']);
            if ($edit !== null) {
                ($this->note)($orderId, $statusId, $edit['message']);
            }
            if ($action === null) {
                return;
            }
            $result = match ($action) {
                OrderLifecycle::FULFIL => $setup['lifecycle']->fulfil($orderId, $rows),
                OrderLifecycle::CANCEL => $setup['lifecycle']->cancel($orderId),
                default => $setup['refunds']->remainder($orderId),
            };
            if ($result['outcome'] !== OrderLifecycle::SKIPPED) {
                ($this->note)($orderId, $statusId, $result['message']);
            }
        } catch (\Throwable $error) {
            $message = Message::of('note_failed_' . ($action ?? 'status'), get_class($error), basename($error->getFile()) . ':' . $error->getLine());
            ($this->logError)('Two error: order ' . $orderId . ': ' . $message->english() . ' ' . $error->getMessage());
            ($this->note)($orderId, $statusId, $message);
        } finally {
            self::$running = false;
        }
    }
}
