# Changelog

All notable changes to the Two payment extension for OpenCart will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

A rewrite of the extension for OpenCart 4.1. It does not run on OpenCart 3, and the 1.x extension for OpenCart 3 can no longer reach Two, so moving to OpenCart 4.1 and this version is the way to keep offering Two.

### Added
- **OpenCart 4.1 support** (TWO-26647). Installs through Extensions > Installer from `two.ocmod.zip` and appears under Extensions > Payments. Tested on OpenCart 4.1.0.0 and 4.1.0.4, on PHP 8.1 and 8.4.
- **Settings in five tabs** (TWO-26649, TWO-27051). General, Checkout fields, Payment terms, Order management and Diagnostics, with the API key checked as you type and a line saying whether Two can be offered at checkout right now.
- **Company search at checkout** (TWO-26668, TWO-26721, TWO-26775). The buyer finds their company by name in the address form and the company address is filled in; the company stays with a saved address and works on the address edit form. Sole traders are guided through their own flow.
- **Optional checkout fields** (TWO-26668). Invoice email, PO number, project and department, each switched on separately, plus an optional pre-approval check before the order is placed.
- **Payment terms and the payment terms fee** (TWO-26669). The buyer chooses from the terms your Two account offers; you narrow them, set the default, and can pass Two's fee on as a surcharge per term, taxed by your own tax rules.
- **Orders with Two** (TWO-26651). An order is created with Two at checkout and placed at your confirmed status once Two accepts it. If the cart or the order changes between checkout and the buyer's return, the order is not confirmed and the buyer is asked to review it.
- **Order lifecycle from order statuses** (TWO-26652, TWO-26667). Moving an order to the statuses you map fulfils, cancels or refunds it with Two, and edits made before fulfilment are sent to Two.
- **Refunds and documents on the order page** (TWO-26667). The Two tab on an order refunds chosen lines or an amount, and downloads the invoice and the credit note.
- **Orders the buyer verified but never returned from** (TWO-26300). If a buyer passes Two's checkout and closes the tab, the order is no longer left missing: the scheduled URL, now meant to run every 15 minutes, checks it with Two after 30 minutes and places it, and the Two tab's Check status with Two button does so at once. An order Two cancelled stays unplaced with a note.
- **Five languages** (TWO-26670). English, Norwegian (Bokmål), Swedish, Dutch and Spanish, in the admin and at checkout, with the payment method title and subtitle set per language.
- **Product page message and buy button** (TWO-27055). Optional: a line naming Two under the add to cart button, and a "Buy with Two" button that opens checkout with Two chosen.
- **Tax codes for 0% lines** (TWO-27058). Where Two requires a code on every 0% line (today Spain), you choose the code for each case your own tax setup produces, and pick the customer field that holds the buyer's VAT number.
- **Diagnostics** (TWO-27051). Debug logging, the last 100 error log lines with secrets hidden, an on-demand refresh of your Two merchant profile, a daily scheduled refresh, custom request headers, trusted proxies and checkout rate limiting.
- **Order postprocessing event** (TWO-26651). A permanent, documented OpenCart event that lets your developer adjust every order request just before it is sent to Two.

### Changed
- **Two API connection** (TWO-26648). Calls Two's current API hosts, for both Sandbox and Production.

### Removed
- **OpenCart 3 support.** The 1.x extension for OpenCart 3 can no longer reach Two; move to OpenCart 4.1.
