# Two for OpenCart

The Two payment extension for OpenCart 4.1: pay by invoice for business buyers.

**Status: being rewritten.** This branch holds the new OpenCart 4.1 foundation. It installs, appears under Extensions > Payments and offers Two at checkout with company search, payment terms and the payment terms fee, in English, Norwegian (Bokmål), Swedish, Dutch and Spanish. The 1.x releases for OpenCart 3 call API hosts that no longer exist, so they cannot reach Two.

## Requirements

- OpenCart 4.1.0.0 or later
- PHP 8.1 or later

## Install

Upload `two.ocmod.zip` in Extensions > Installer, install it, then install and enable Two in Extensions > Extensions > Payments.

## Development

Needs Docker and PHP 8.1+ on the host.

```sh
cp .env.example .env   # optional: API key and environment
make install           # OpenCart 4.1.0.4 on http://localhost:1238/ with the extension enabled
make test              # offline test suite
make phpstan           # static analysis
make e2e               # Playwright against a second shop and a Two API stub
make archive           # dist/two.ocmod.zip
```

`make help` lists every target. See [AGENTS.md](AGENTS.md) for the layout and conventions.

## Languages

The extension ships OpenCart language files for en-gb, nb-no, sv-se, nl-nl and es-es, in the admin and at checkout. A shop language is matched by its OpenCart language code, so install the matching OpenCart language pack. The payment method title and an optional subtitle are set per language on the Checkout fields tab; a language with no title set shows that language's default, and one with no subtitle shows none.

## Payment terms and the fee

The buyer chooses from the payment terms your Two account offers; the Payment terms tab narrows them and sets the default, and shows beside each term the fee Two charges you for offering it. To pass Two's fee on to the buyer, set a surcharge per term there and select the tax class it is charged at: the fee is an order total (Extensions > Order Totals > Two payment terms fee), taxed by your shop's own tax rules like any other charge. Keep its sort order before Taxes.

## Product page message and buy button

Two optional extras on the Checkout fields tab, each off until you switch it on. Show message on product pages adds a short line naming Two under the add to cart button; leave its wording empty to use the default, or set your own per language (avoid naming a number of days: terms run from fulfilment and depend on your Two account). Show buy button on product pages adds a "Buy with Two" button there that puts the item in the basket with the quantity and options the buyer chose and opens checkout with Two already chosen in the payment method list. Neither promises the buyer will be offered Two: the minimum order, currency and buyer country are checked at checkout as usual, and where Two is not offered the buyer picks another method. Both sit inside the default theme's add to cart form; a theme that replaces that form shows neither, and where exactly they land beside other badges depends on your theme.

## Tax codes for 0% lines

Where Two requires a tax code on every 0% line for your country (today Spain), the Order management tab's Tax section shows "Tax codes for 0% lines": one table per tax class, with a row for a VAT-registered buyer in another EU state, a row for each 0% tax rate the class's rules use, and a row for a buyer address no rate covers (for example an export). Pick the code Two should receive for each case. A 0% line takes a code only from these rows: a row left on (none) sends none, and the extension never picks one itself. A line with no tax class of its own (a discount, store credit, a voucher) takes the code your coded 0% lines share, and none when they differ. Set your shop's tax rates correctly: the line falls on the row your own tax setup puts it on.

The buyer's VAT number is read from the customer field you choose in the same section (a text custom field on the address or the account; OpenCart has no VAT number field of its own). It decides the VAT-registered buyer row, and a Spanish merchant's order sends it to Two, except for a Spanish buyer. Saved codes keep applying at checkout even when the section is hidden; it then says so and offers Clear saved tax codes.

## Order lifecycle

Map your order statuses in the extension's Order management tab: an order Two confirms is placed at the confirmed status (Processing on a fresh install), moving an order into the fulfilment status fulfils it with Two, the cancel status cancels it, and the refund status refunds whatever is left. Edits to an order before it is fulfilled are sent to Two. The Two tab on the admin order page refunds chosen lines or an amount, and downloads the invoice and the credit note.

Partial fulfilment is not offered from the shop: to fulfil part of an order, use the Two merchant portal.

## Diagnostics

The Diagnostics tab holds the operational settings: debug logging, the SSL verification bypass (off unless you switch it on, and a security risk whenever it is on), trusted proxies and the checkout rate limit, and custom request headers. Add a header row when your IT administrator asks for one: each is sent on every call the shop makes to Two, and a row ticked "Also send from browser" is also sent by the buyer's browser on the sole-trader autofill call, so its value is public. While any row is invalid nothing on the tab is saved, and each bad row is named.

The tab also links to the extension's last 100 error log lines (the API key and header values redacted), refreshes the cached merchant profile on demand, and shows the scheduled refresh URL. Call that URL once a day from your crontab, or rely on OpenCart's own cron URL, which runs the same refresh daily. The URL carries a random token, not your API key: keep it secret. The token can also be sent as an `X-Two-Cron-Token` header or a POST field.

## Stable extension contract: order postprocessing

Every order request the extension sends to Two (order create, update, confirm, cancel, capture, that is fulfil, and refund today; intent as it lands) passes through one OpenCart event just before it is sent, so your code can change the payload. Subscribe the way any OpenCart extension subscribes to an event: an `event` row (Extensions > Events) with the trigger `catalog/extension/two/order_postprocessing` and an action whose method takes the payload by reference:

```php
public function handle(array &$payload, array $context): void
```

Your subscriber runs for requests sent from the admin too (the refund form on the order page): the extension fires the event on the order's store front (with the order's store as `config_store_id`), the way OpenCart's own admin order edits run, so a `catalog/` subscriber sees every request whichever side sends it.

- `context` carries `request_type` (`order_intent`, `order_create`, `order_update`, `order_confirm`, `capture`, `refund`, `cancel`), `trigger`, `endpoint`, `order_id`, the shop's `order`, `shipping_tax_rate`, `fallback_shipping_tax_rate` and `contract_version` (1). Confirm, cancel and capture carry an empty payload, and so does a refund of a whole order nothing has been refunded from yet; what your handler returns is what is sent. A refund that waits for a retry is resent exactly as your handler returned it the first time, without running the handlers again.
- With Validate tax subtotals switched off (Order management), the payload reaches your handler without `tax_subtotals`; a handler may add its own, which is sent.
- Handlers run in the event's sort order. The extension never recomputes after them: a handler that edits a line also updates the totals it affects, for example with the totals helper `\Opencart\System\Library\Extension\Two\Core\Postprocessing::recomputeTotals($payload)`.
- With no handler registered, the extension refuses an order whose lines do not add up to the total and tax the shop charged. With one registered and enabled, even one that changes nothing, those shop-match checks are yours, and the log says so once per request. To run them yourself: `\Opencart\System\Library\Extension\Two\Core\OrderBuilder::shopMatchFailures($payload, $order)`.
- A handler that throws, or leaves the payload as something that is not an encodable array, refuses the request. The buyer sees the generic refusal and the log names `TWO_ORDER_POSTPROCESSING_HOOK_FAILED`, the exception class and where it was thrown.
- The same input must give the same output: no clock, no randomness. When the buyer comes back from Two, the extension rebuilds the order create payload from the stored order and runs your handlers on it again (`request_type` `order_create`, `trigger` `snapshot_hash`, nothing sent), then compares its hash with the payload Two was sent. On any difference (the cart, an amount or an address changed, or a handler gave a different answer) the order is not confirmed: the Two order is cancelled, the reason goes to the order history and the buyer is asked to review the cart. An order edited after it was placed is rebuilt the same way and sent to Two as an `order_update` when its payload differs from what Two holds. Before the order is fulfilled, a separate check compares the order's own rows (products, totals and addresses as stored on the order) with a hash kept when Two last accepted them; your handlers do not take part in it. If the order was edited since and Two did not accept the edit, it is not fulfilled and the order history says why.
- The extension makes no determination about how tax is levied: it sends the nets, taxes and rates your shop recorded. If your shop records an amount differently from your books (for example untaxed shipping with VAT inside it), this event is where you correct it.

This contract is permanent: the event name, the v1 context keys and request types, and the totals helper are never removed or renamed. Changes are additive only.
