# Two for OpenCart

The Two payment extension for OpenCart: pay by invoice for business buyers. The buyer finds their company at checkout, Two checks their credit and pays you, and the buyer pays Two on the payment terms you offer.

Version 2.x is for OpenCart 4.1. OpenCart 3 is not supported: the 1.x extension for OpenCart 3 can no longer reach Two, so a shop on OpenCart 3 needs to move to OpenCart 4.1 to offer Two.

## Requirements

- OpenCart 4.1.x. Each release is tested on 4.1.0.0 and 4.1.0.4.
- PHP 8.1 or later. Each release is tested on PHP 8.1 and 8.4.
- A Two merchant account and its API key.

## Install

1. Download `two.ocmod.zip` from the latest release on this repository's [Releases](../../releases) page. Keep the file name: OpenCart takes the extension's code from it.
2. In the OpenCart admin, open Extensions > Installer, upload `two.ocmod.zip`, then click Install beside it.
3. Open Extensions > Extensions, choose Payments, and install Two. Then click Edit to configure it.

To upgrade, replace the package in Extensions > Installer with the new release's `two.ocmod.zip`; your settings are kept. Do not uninstall Two under Extensions > Payments to upgrade: that deletes its settings.

## Configure

The settings page has five tabs, saved together. A setting that is not valid is named beside its field.

### General

Switch the payment method on, choose Sandbox or Production, and paste the API key from your Two merchant account; the key is checked as you type. Sort order places Two among your other payment methods. The top of the tab says whether Two can be offered at checkout right now, and why not when it cannot.

### Checkout fields

What the buyer sees at checkout:

- The payment method title and an optional subtitle, per language. The extension ships English, Norwegian (Bokmål), Swedish, Dutch and Spanish, in the admin and at checkout; install the matching OpenCart language pack for each.
- Company search in the address form, and autofill of the company address.
- Optional invoice email, PO number, project and department fields.
- The minimum order value, and whether it is measured with or without tax.
- The order intent pre-approval check, the "What is Two" link and the field tooltips.
- Two optional extras on product pages, each off until you switch it on: a short message naming Two under the add to cart button, and a "Buy with Two" button that adds the item to the basket and opens checkout with Two chosen. Neither promises that Two will be offered: the minimum order, currency and buyer country are checked at checkout as usual. Both sit in the default theme's add to cart form, so a theme that replaces that form shows neither.

### Payment terms

The buyer chooses from the payment terms your Two account offers. This tab narrows them, sets the default, and shows the fee Two charges you for each term. To pass that fee on to the buyer, set a surcharge per term and choose the tax class it is charged at. The surcharge is an order total (Extensions > Order Totals > Two payment terms fee), taxed by your shop's own tax rules; keep its sort order before Taxes.

### Order management

- **Order statuses.** An order Two confirms is placed at the confirmed status (Processing on a fresh install). Moving an order into a fulfilment status fulfils it with Two, the cancel status cancels it, and the refund status refunds whatever is left. Edits to an order before it is fulfilled are sent to Two. To fulfil part of an order, use the Two merchant portal.
- **Order page.** The Two tab on an order (Sales > Orders) refunds chosen lines or an amount, and downloads the invoice and the credit note.
- **Buyers who never come back.** A buyer can pass Two's checkout and close the tab before returning to your shop. The order then waits as a missing order (Sales > Orders, filter on Missing Orders). The scheduled URL below checks such orders with Two every 15 minutes once they are 30 minutes old (for up to 7 days), and places each one Two holds as verified or confirmed, at the confirmed status, with the usual order email. An order Two cancelled stays unplaced with a note; nothing is sent back to Two. On the order's Two tab, **Check status with Two** does the same for that order at once, at any age.
- **Tax codes for 0% lines.** Shown where Two requires a tax code on every 0% line for your country (today Spain). Each tax class has a row for a VAT-registered buyer in another EU state, a row for each 0% tax rate its rules use, and a row for an address no rate covers (for example an export). Pick the code Two should receive for each. A row left on (none) sends no code, and the extension never picks one itself. Set your shop's tax rates correctly: a line falls on the row your own tax setup puts it on.
- **Buyer's VAT number.** OpenCart has no VAT number field, so choose the text custom field (on the address or the account) that holds it.
- **Validate tax subtotals.** Sends a per-rate tax breakdown with each order and refund, for Two to check against the lines. Leave it on unless your developer asks otherwise (see order postprocessing below).

### Diagnostics

Debug logging, the SSL verification bypass (off unless you switch it on, and a security risk whenever it is on), trusted proxies, the checkout rate limit, and custom request headers for when your IT administrator asks for one. A header ticked "Also send from browser" is visible to buyers.

The tab also shows the extension's last 100 error log lines (with the API key and header values hidden), refreshes your Two merchant profile on demand, and shows the scheduled URL. Call it every 15 minutes from your server's crontab, for example `*/15 * * * * curl -fsS -H 'X-Two-Cron-Token: <token>' '<the URL without its token>'`: each call checks the orders above and refreshes the merchant profile when it is due. OpenCart's own cron (the Cron Jobs page) runs the same check hourly and the refresh daily, so a shop running only that still recovers orders, more slowly. The tab warns when orders are waiting and the URL has not run for an hour. The URL carries a random token, not your API key: keep it secret.

## Uninstall

Uninstalling Two in Extensions > Extensions > Payments deletes all of its settings, including the API key, the status mapping and the tax codes, and removes the payment terms fee order total. Note your settings first if you plan to reinstall. The extension's record of orders and refunds placed with Two stays in the shop's database.

To remove the files as well, uninstall the extension in Extensions > Installer.

## Support

- Setup help, onboarding and bug reports: support@two.inc
- Two documentation: [docs.two.inc](https://docs.two.inc/)

When reporting a problem, include the extension version (shown in Extensions > Installer) and, if you can, the error log lines from the Diagnostics tab.

## Stable extension contract: order postprocessing

For developers who need to change what is sent to Two. Every order request the extension sends to Two (order create, update, confirm, cancel, capture, that is fulfil, and refund today; intent as it lands) passes through one OpenCart event just before it is sent, so your code can change the payload. Subscribe the way any OpenCart extension subscribes to an event: an `event` row (Extensions > Events) with the trigger `catalog/extension/two/order_postprocessing` and an action whose method takes the payload by reference:

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

## Development

Needs Docker and PHP 8.1+ on the host.

```sh
cp .env.example .env   # optional: API key and environment
make install           # OpenCart 4.1.0.4 on http://localhost:1238/ with the extension enabled
make test              # offline test suite
make phpstan           # static analysis
make e2e               # Playwright against a second shop and a Two API stub
make archive           # dist/two.ocmod.zip, the package a release attaches
```

`make help` lists every target, and [AGENTS.md](AGENTS.md) covers the layout and conventions. See [CHANGELOG.md](CHANGELOG.md) for what changed in each version.

### Releasing

The version lives in `install.json` (and `bumpver.toml`); bump it on a pull request into staging with `make patch`, `make minor` or `make major`. When a commit reaches main and the Tests workflow passes on it, the Release workflow tags that version, builds `two.ocmod.zip` with `dev/package.sh` and attaches it to a GitHub release whose notes are the version's CHANGELOG entry. A version already released is skipped.
