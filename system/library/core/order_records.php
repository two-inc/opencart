<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: the plugin's own order-scoped store (Build Guide section 12.4), one
 * row per shop order id, holding what later requests need after the checkout
 * session is gone: the Two order id and state, the callback token's hash, the create payload's hash,
 * the company the buyer entered, the optional buyer fields, the payment terms block the order is booked on and whether it was placed with a payment terms fee ('1' or '0') and that fee as placed (its title, net, tax, rate and tax rows), the hash of the order's own rows as Two last
 * accepted them, the last accepted edit's idempotency key and the rows hash
 * of the last edit Two refused, and the pending check's own (PendingCheck):
 * when it last checked the order and when Two first answered 404 (Unix
 * seconds, '0' for never), and why it stopped checking ('' while it checks).
 */
interface OrderRecords
{
    public const FIELDS = ['two_order_id', 'two_order_state', 'two_order_status', 'token_hash', 'payload_hash', 'company_name', 'organization_number', 'country_code', 'rows_hash', 'edit_key', 'edit_refused_hash', 'buyer_fields', 'payment_terms', 'payment_fee', 'placed_fee', 'checked_at', 'missing_at', 'check_closed'];

    /** Column widths of the buyer's fields: longer values are refused at entry, never truncated. */
    public const MAX_LENGTHS = ['company_name' => 255, 'organization_number' => 64, 'country_code' => 2];

    /**
     * The row for a shop order, or null when the plugin has none.
     *
     * @return array<string, string>|null
     */
    public function get(int $orderId): ?array;

    /**
     * Presence-conditional write: only the keys given are written, so an
     * absent key leaves the stored value unchanged. Creates the row when
     * there is none.
     *
     * @param array<string, string> $fields keys from FIELDS
     */
    public function put(int $orderId, array $fields): void;
}
