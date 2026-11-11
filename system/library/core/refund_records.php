<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The plugin's own record of each refund it sends to Two, one row per
 * refund. A partial refund's row is created before the call, so its id is
 * the durable id its idempotency key is built from (OpenCart has no credit
 * memo of its own). The rest-of-order refund from a status change keeps a
 * row too, for the order panel, though its key does not use the row id.
 */
interface RefundRecords
{
    public const FIELDS = ['two_order_id', 'kind', 'amount', 'currency', 'payload', 'idempotency_key', 'state', 'reason', 'refunded_before'];

    public const KIND_PARTIAL = 'partial';
    public const KIND_REMAINDER = 'remainder';

    /** Created, not yet answered: a row left in this state means the request may have been sent. */
    public const PENDING = 'pending';
    public const DONE = 'done';
    public const REFUSED = 'refused';
    /** Two's answer was not received and the refunded total did not show it: resend under the same key. */
    public const UNKNOWN = 'unknown';
    /** Two answered with an earlier refund under this key: this refund was not applied. */
    public const NOT_APPLIED = 'not_applied';

    /**
     * Creates a row and returns its id; 0 when it could not be stored.
     *
     * @param array<string, string> $fields keys from FIELDS
     */
    public function create(int $orderId, array $fields): int;

    /**
     * Presence-conditional write to one row.
     *
     * @param array<string, string> $fields keys from FIELDS
     */
    public function update(int $refundId, array $fields): void;

    /**
     * Takes the order's refund lock, waiting a while for another holder;
     * false when it could not be had.
     */
    public function lock(int $orderId): bool;

    public function unlock(int $orderId): void;

    /**
     * The order's rows, oldest first, each with its `refund_id` and `date_added`.
     *
     * @return list<array<string, string>>
     */
    public function forOrder(int $orderId): array;
}
