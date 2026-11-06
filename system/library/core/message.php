<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * A note for the merchant (an order history line, a refund form outcome) as
 * a language key and its parameters, so the adapter renders it in the shop's
 * or the admin's language. english() is the text for the log, which stays
 * English. TEMPLATES is the en-gb source: the en-gb notes language file holds
 * the same keys and text (LanguageCatalogueSpec pins them). A parameter may
 * itself be a Message (why an order could not be built, inside the note
 * that says so), rendered in the same language. Plain string parameters are
 * data or Two's own text, never the plugin's copy.
 */
final class Message
{
    public const TEMPLATES = [
        // Order create and the callbacks (OrderFlow), and the catalog controller.
        'note_no_company' => 'No company was entered.',
        'note_company_too_long' => 'A company field is longer than the plugin stores.',
        'note_order_unreadable' => 'The order could not be read.',
        'note_create_not_built' => 'Two order not created: %s',
        'note_create_earlier_open' => 'Two order not created. The earlier Two order %1$s for this order could not be cancelled: %2$s',
        'note_create_not_stored' => 'Two order not created: the two_order table did not store %s as given. Run the extension install again.',
        'note_create_refused' => 'Two refused the order: %s',
        'note_create_no_id' => 'Two answered the order create without an order id (HTTP %s).',
        'note_create_no_url' => 'Two created order %s without a payment URL, so it was cancelled.',
        'note_confirm_token' => 'The confirmation callback carried no valid token for this order.',
        'note_confirm_no_order' => 'No Two order was created for this order, so there is nothing to confirm.',
        'note_confirm_no_hash' => 'Two order %s not confirmed and cancelled: no payload hash was stored at create.',
        'note_confirm_unreadable' => 'Two order %s not confirmed and cancelled: the shop order can no longer be read.',
        'note_confirm_rebuild_failed' => 'Two order %1$s not confirmed and cancelled: the shop order no longer builds: %2$s',
        'note_confirm_changed' => 'Two order %s not confirmed and cancelled: the shop order changed after the Two order was created (cart, amounts or addresses).',
        'note_confirm_unread' => 'Two could not be asked for the state of order %s.',
        'note_confirm_cancelled' => 'Two holds order %s as cancelled.',
        'note_confirm_already' => 'Two order %1$s is %2$s.',
        'note_confirm_state' => 'Two holds order %1$s as %2$s, so it was not confirmed.',
        'note_confirm_not_built' => 'Two order %1$s not confirmed: %2$s',
        'note_confirm_refused' => 'Two refused to confirm order %1$s: %2$s',
        'note_confirmed' => 'Two order %s confirmed.',
        // The pending check (PendingCheck, TWO-26300): the schedule and the admin's button.
        'note_check_confirmed' => 'The buyer verified this order with Two but did not return to the shop. Two order %1$s confirmed %2$s.',
        'note_check_already' => 'The buyer verified this order with Two but did not return to the shop. Placed %2$s: Two had already confirmed order %1$s.',
        'note_by_schedule' => 'by the scheduled check',
        'note_by_admin' => 'by %s',
        'note_check_cancelled' => 'Two reports order %s as cancelled. The order is left unplaced to match; nothing was sent to Two.',
        'note_check_unverified' => 'Checked with Two: the buyer has not finished verification of order %s.',
        'note_check_state' => 'Two reports order %1$s as %2$s; nothing was changed. Check the order in the Two merchant portal.',
        'note_check_unread' => 'Two could not be asked for the state of order %1$s: %2$s',
        'note_check_confirm_failed' => 'Two order %1$s is verified, but the confirm failed: %2$s. The order is left unplaced and checked again later.',
        'note_check_unmatched' => 'Two order %s was not confirmed: the shop order no longer matches the order Two holds (cart, amounts or addresses), or can no longer be rebuilt. Nothing was sent to Two; settle the order in the Two merchant portal.',
        'note_check_missing' => 'Two does not know order %s: it was not found on two checks at least 15 minutes apart. The order is left unplaced.',
        'note_check_placed' => 'The order is no longer waiting for Two, so nothing was checked.',
        'note_check_busy' => 'The order is being handled by another request (the buyer\'s return or a scheduled check). Try again in a moment.',
        'note_cancel_token' => 'The cancel callback carried no valid token for this order.',
        'note_cancel_no_order' => 'No Two order was created for this order.',
        'note_cancel_by_buyer' => 'The buyer cancelled Two order %s at Two.',
        'note_cancel_unconfirmed' => 'Two order %s could not be confirmed as cancelled.',
        'note_cancel_callback_placed' => 'Cancel callback refused: the order is already placed.',
        'note_cancel_callback_session' => 'Cancel callback refused: it did not come from the buyer\'s session.',
        'note_intent_no_session' => 'Order intent not sent: no checkout session.',
        'note_intent_not_built' => 'Order intent not sent: %s',
        'note_intent_approved' => 'Two approved the order intent.',
        'note_intent_declined' => 'Two declined the order intent.',
        'note_intent_undecided' => 'Two answered the order intent without a decision (HTTP %s).',
        'note_intent_unanswered' => 'Order intent not answered: %s',
        // Fulfil and cancel (OrderLifecycle).
        'note_fulfil_no_order' => 'Not fulfilled: no Two order is linked to this order.',
        'note_already' => 'Two order %1$s is already %2$s.',
        'note_fulfil_cancelled' => 'Two order %s is cancelled at Two, so it was not fulfilled.',
        'note_fulfil_unhashed' => 'Two order %s not fulfilled: no record of the order as placed was kept, so it cannot be checked. Fulfil it in the Two merchant portal.',
        'note_fulfil_edit_unknown' => 'Two order %s not fulfilled: whether Two holds the last edit of this order is unknown. Save the status again to send the order to Two first; if the notes show it still cannot be sent, fulfil the order in the Two merchant portal.',
        'note_fulfil_edited' => 'Two order %s not fulfilled: the order was edited after it was placed with Two. Two still holds the order as placed.',
        'note_fulfil_unread' => 'Two order %s not fulfilled: Two could not be asked for its state. Save the status again to retry.',
        'note_fulfil_state' => 'Two holds order %1$s as %2$s, so it was not fulfilled.',
        'note_fulfil_not_built' => 'Two order %1$s not fulfilled: %2$s',
        'note_fulfil_currency_changed' => 'Two order %1$s not fulfilled: the order was placed with Two in %2$s and its currency is now %3$s. Two keeps an order in the currency it was placed in: set the order\'s currency back to %2$s and save the status again.',
        'note_fulfil_refused' => 'Two refused to fulfil order %1$s: %2$s Save the status again to retry.',
        'note_fulfilled' => 'Two order %1$s fulfilled (%2$s). The invoice is on the order page under Two.',
        'note_cancel_no_two_order' => 'Not cancelled: no Two order is linked to this order.',
        'note_cancel_already' => 'Two order %s is already cancelled.',
        'note_cancel_fulfilled' => 'Two order %1$s is %2$s, so it was not cancelled. A fulfilled order is refunded, not cancelled.',
        'note_cancelled' => 'Two order %s cancelled.',
        'note_cancel_refused' => 'Two order %1$s not cancelled. Two holds it as %2$s. Save the status again to retry.',
        'note_cancel_refused_why' => 'Two order %1$s not cancelled: %3$s Two holds it as %2$s. Save the status again to retry.',
        'note_cancel_refused_fulfilled' => 'Two order %1$s not cancelled. Two holds it as %2$s. A fulfilled order is refunded, not cancelled.',
        'note_cancel_refused_fulfilled_why' => 'Two order %1$s not cancelled: %3$s Two holds it as %2$s. A fulfilled order is refunded, not cancelled.',
        'note_cancel_refused_unread' => 'Two order %s not cancelled, and Two could not be asked for its state. Save the status again to retry.',
        'note_cancel_refused_unread_why' => 'Two order %1$s not cancelled: %2$s Two could not be asked for its state. Save the status again to retry.',
        'note_failed_fulfil' => 'The fulfil at Two failed: %1$s at %2$s.',
        'note_failed_cancel' => 'The cancel at Two failed: %1$s at %2$s.',
        'note_failed_refund' => 'The refund at Two failed: %1$s at %2$s.',
        'note_failed_status' => 'The status at Two failed: %1$s at %2$s.',
        // Order edits (OrderEdit).
        'note_edit_unread' => 'The edit to this order was not sent to Two: Two could not be asked for the state of order %s. Save the status again to retry.',
        'note_edit_closed' => 'The edit to this order was not sent to Two: Two no longer accepts changes to order %1$s (%2$s).',
        'note_edit_unreadable' => 'The edit to this order was not sent to Two: the order can no longer be read.',
        'note_edit_not_built' => 'The edit to this order was not sent to Two: %s',
        'note_edit_currency_changed' => 'The edit to this order was not sent to Two: the order was placed with Two in %1$s and its currency is now %2$s. Two keeps an order in the currency it was placed in: set the order\'s currency back to %1$s and save it again to send the edit.',
        'note_edit_done' => 'Two order %s updated with this order\'s changes.',
        'note_edit_refused' => 'Two refused the edit to order %1$s: %2$s Two still holds the order as before, so it will not be fulfilled until the order matches. Edit the order to what Two can accept, or fulfil it in the Two merchant portal.',
        'note_edit_not_taken' => 'Two did not take the edit to order %1$s: %2$s It is sent again on the next status save.',
        'note_edit_unknown' => 'The edit to order %1$s may not have reached Two: %2$s It is sent again on the next status save.',
        'note_edit_invoiced' => 'Two has invoiced all or part of order %s, so this order\'s edit was not sent to Two. Two invoices the order as it holds it; refund any difference from the Two tab.',
        // Refunds (OrderRefunds).
        'note_refund_no_order' => 'Not refunded: no Two order is linked to this order.',
        'note_refund_status_unread' => 'Two order %s not refunded: Two could not be asked for the order. Save the status again to retry.',
        'note_refund_state' => 'Two holds order %1$s as %2$s, so it was not refunded. Two refunds a fulfilled order; an order not fulfilled yet is cancelled.',
        'note_refund_total_unread' => 'Two order %s not refunded: its total could not be read from Two.',
        'note_refund_already' => 'Two order %s is already refunded in full.',
        'note_refund_nothing_left' => 'Nothing was left to refund on Two order %s: Two holds it as refunded in full, by the earlier attempt or by a refund made meanwhile in the Two merchant portal. Nothing was sent.',
        'note_refund_split' => 'Two order %1$s not refunded: the rest (%2$s) could not be split across the order\'s tax rates as Two holds them. Refund it in the Two merchant portal.',
        'note_refund_unread' => 'Not refunded: Two could not be asked for order %s. Try again.',
        'note_refund_not_fulfilled' => 'Not refunded: Two holds order %1$s as %2$s. Two refunds a fulfilled order only.',
        'note_refund_form_not_built' => 'Not refunded: %s',
        'note_retry_not_waiting' => 'Not retried: that refund is not waiting for a retry.',
        'note_retry_unreadable' => 'Not retried: the stored refund cannot be read. Check the order in the Two merchant portal.',
        'note_retry_unread' => 'Not retried: Two could not be asked for order %s. Try again.',
        'note_refund_not_built' => 'Two order %1$s not refunded: %2$s',
        'note_refund_too_much' => 'Two order %1$s not refunded: %2$s is more than remains to refund (%3$s).',
        'note_refund_not_stored' => 'Two order %s not refunded: the refund could not be stored, so it was not sent. Run the extension install again.',
        'note_refund_refused' => 'Two refused the refund of %1$s on order %2$s: %3$s',
        'note_refunded' => 'Two refunded %1$s on order %2$s. The credit note is on the order page under Two.',
        'note_refunded_earlier' => 'Two refunded %1$s on order %2$s. The credit note is on the order page under Two. Two had applied the earlier attempt.',
        'note_refunded_risen' => 'Two refunded %1$s on order %2$s. The credit note is on the order page under Two. Two\'s refunded total has risen by the amount since the first attempt, so that attempt was applied.',
        'note_refund_not_applied' => 'Two answered the refund of %1$s on order %2$s with an earlier refund made under the same key (%3$s), so this refund was not applied. Two\'s refunded total did not rise by the amount sent.',
        'note_refund_unknown_unread' => 'The outcome of the refund of %1$s on order %2$s is unknown (the order could not be read back). Retry it on the Two tab of the order page; no other refund is sent until then.',
        'note_refund_unknown' => 'The outcome of the refund of %1$s on order %2$s is unknown: %3$s Retry it on the Two tab of the order page; no other refund is sent until then.',
        'note_refund_locked' => 'Not refunded: another refund on this order is being sent. Try again in a minute.',
        'note_refund_blocked' => 'Not refunded: the refund of %1$s sent on %2$s has an unknown outcome. Retry it on the Two tab of the order page first, so Two never refunds twice.',
        // Why an order or a refund could not be built (OrderRefused), a parameter of the notes above.
        'refusal_currency_value' => 'The order has no usable currency value.',
        'refusal_currency_changed' => 'the order was placed with Two in %1$s and its currency is now %2$s. Two keeps an order in the currency it was placed in.',
        'refusal_empty_order' => 'The order has no product lines.',
        'refusal_tax_code_map' => 'The saved tax codes for 0% lines cannot be read. Clear them on the Order management tab.',
        'refusal_unsupported_total' => 'The order total "%s" is not one the plugin can send.',
        'refusal_fee_missing' => 'the order was placed with a payment terms fee but no longer carries one (is the Two payment terms fee order total enabled?)',
        'refusal_negative_line' => 'The line "%s" has a negative or empty quantity or amount.',
        'refusal_line_rate_unknown' => 'The shop charged tax on "%s" but its tax class gives no rate at the order address.',
        'refusal_discount_tax_positive' => 'The stored tax total is %s more than the lines carry, which a discount cannot explain: a line\'s tax does not match what the shop charged.',
        'refusal_discount_tax_unexplained' => 'The stored tax total is %s less than the lines carry, but no line carries tax for the discount to reduce.',
        'refusal_discount_tax_ambiguous' => 'The discount reduced tax on lines at more than one rate (%s), and the shop does not store how much at each.',
        'refusal_fixed_amount_tax' => 'fixed-amount tax rate on "%s": a Two line carries one percentage rate.',
        'refusal_order_date' => 'The order has no readable date added.',
        'refusal_shop_match_gross' => 'The lines add up to %1$s but the shop charged %2$s.',
        'refusal_shop_match_tax' => 'The lines carry %1$s tax but the shop charged %2$s.',
        'refusal_shop_match_both' => '%1$s %2$s',
        'refusal_hook_threw' => 'A postprocessing subscriber threw %s.',
        'refusal_hook_returned' => 'A postprocessing subscriber returned %s, not an array.',
        'refusal_hook_unencodable' => 'A postprocessing subscriber returned a payload that cannot be JSON-encoded.',
        'refusal_cancel_not_sent' => 'not sent: %s',
        'refusal_refund_line_unknown' => 'a picked line is not on the Two order.',
        'refusal_refund_line_quantity' => 'more of "%1$s" than is left to refund (%2$s of %3$s).',
        'refusal_refund_amount_net' => 'the amount line\'s net is not an amount (digits, and up to two decimals after a dot).',
        'refusal_refund_amount_tax' => 'the amount line\'s tax is not an amount (digits, and up to two decimals after a dot).',
        'refusal_refund_amount_rate' => 'the amount line\'s tax rate is not one of the order\'s.',
        'refusal_refund_amount_zero' => 'the amount line has no net amount.',
        'refusal_refund_empty' => 'nothing to refund. Pick a quantity on a line, or enter an amount.',
        'refusal_refund_line_untaxed' => '"%1$s" has a net amount but no tax at %2$s. Enter the tax the shop charged.',
        // Why a call to Two failed when Two was not reached (ErrorMapper), a parameter of the notes above.
        'reason_unreachable' => 'Two could not be reached (%s).',
        'reason_no_response' => 'Two could not be reached (no response).',
    ];

    /** @param list<string|self> $params */
    private function __construct(public readonly string $key, public readonly array $params)
    {
    }

    public static function of(string $key, string|int|float|self ...$params): self
    {
        if (!isset(self::TEMPLATES[$key])) {
            throw new \InvalidArgumentException('Unknown message key "' . $key . '".');
        }

        return new self($key, array_values(array_map(static fn (string|int|float|self $param): string|self => $param instanceof self ? $param : (string) $param, $params)));
    }

    /** The English text, for the log and wherever no language file has the key. */
    public function english(): string
    {
        $params = array_map(static fn (string|self $param): string => $param instanceof self ? $param->english() : $param, $this->params);

        return self::format(self::TEMPLATES[$this->key], $params) ?? self::TEMPLATES[$this->key];
    }

    /**
     * The text in a language: $template gives that language's copy of a key
     * (OpenCart's Language::get answers the key itself when it has none).
     *
     * @param \Closure(string): string $template
     */
    public function in(\Closure $template): string
    {
        $copy = $template($this->key);
        $params = array_map(static fn (string|self $param): string => $param instanceof self ? $param->in($template) : $param, $this->params);

        return $copy === '' || $copy === $this->key ? $this->english() : (self::format($copy, $params) ?? $this->english());
    }

    /**
     * A template filled in, or null when its placeholders do not fit the
     * parameters (a stored message from another version).
     *
     * @param list<string> $params
     */
    private static function format(string $template, array $params): ?string
    {
        try {
            return vsprintf($template, $params);
        } catch (\ValueError) {
            return null;
        }
    }

    /** The message as stored (a refund row's outcome), to render later in the reader's language. */
    public function encode(): string
    {
        return (string) json_encode($this->stored());
    }

    /** @return array{key: string, params: list<string|array<string, mixed>>} */
    private function stored(): array
    {
        return ['key' => $this->key, 'params' => array_map(static fn (string|self $param): string|array => $param instanceof self ? $param->stored() : $param, $this->params)];
    }

    /** A stored message, or null for text stored before messages were (shown as it stands). */
    public static function decode(string $stored): ?self
    {
        return self::fromStored(json_decode($stored, true));
    }

    private static function fromStored(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['key'] ?? null) || !isset(self::TEMPLATES[$data['key']]) || !is_array($data['params'] ?? null)) {
            return null;
        }
        $params = array_map(static fn (mixed $param): string|self => is_scalar($param) ? (string) $param : (self::fromStored($param) ?? ''), array_values($data['params']));

        return self::of($data['key'], ...$params);
    }
}
