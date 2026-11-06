// A stand-in for Two's API for the E2E suite, run in its own container by
// tests/e2e/compose.yml. No dependencies: Node's own http and https.
//
// The shop calls https://api.sandbox.two.inc as it would in production: the
// compose network gives this container that name, and the shop trusts the
// throwaway CA tests/e2e/bin/tls.sh made for it. So the extension runs
// unchanged, with no host override.
//
// It listens twice, both over HTTPS:
//   - on 443, inside the compose network only: the shop's API calls.
//   - HTTPS on TWO_API_STUB_PORT, published on the host's loopback: Two's hosted
//     checkout (the order's payment_url, which the browser follows; the
//     extension only redirects to an https URL) and the /__stub/ control
//     routes the specs use. The port has no default, so the published port
//     and the payment_url built from it cannot drift apart. The browser does
//     not trust the throwaway CA, so the suite ignores certificate errors.
//
// It mints the sole-trader signup's tokens, which every checkout asks for at
// load, and keeps orders in memory and answers with the fields the extension reads:
// state, the totals and lines it was sent, and the refunds it holds. It
// refuses what Two refuses, with Two's status and error codes: a merchant call
// without the shop's API key (TWO_API_STUB_KEY) is 401, and an order, edit or
// refund whose amounts do not add up is 400, by the checks Two's API applies.
// A write repeated under the same Idempotency-Key gets the first answer back
// and changes nothing, as Two does. /__stub/fault makes the next matching
// request fail without applying it, or apply it and drop the connection with
// no answer at all, so the specs reach both halves of the refund-unknown path.
// POST /__stub/orders/<id> sets an order's state, as Two changes it on its own.
import fs from "node:fs";
import https from "node:https";
import { randomUUID } from "node:crypto";

const PORT = process.env.TWO_API_STUB_PORT;
const API_KEY = process.env.TWO_API_STUB_KEY;
if (!PORT || !API_KEY) {
  console.error("TWO_API_STUB_PORT and TWO_API_STUB_KEY must be set: the payment_url is built from the port, and the key is the shop's.");
  process.exit(1);
}
const PUBLIC_URL = `https://localhost:${PORT}`;
const MERCHANT_ID = "e2e-merchant";

let orders = new Map();
let replies = new Map();
let requests = [];
let faults = [];
let checkoutOutcome = "verify";

// Signed cents: a discount line is negative, and adds up as one.
const cents = (value) => Math.round(Number(value || 0) * 100);
const absCents = (value) => Math.abs(cents(value));
const money = (c) => (c / 100).toFixed(2);
const within = (a, b, tolerance) => Math.abs(a - b) <= tolerance;
const sum = (items, field) => (items || []).reduce((total, item) => total + cents(item[field]), 0);

// Two's error body.
const error = (status, code, message, details) => [status, { error_code: code, error_message: message, error_details: details }];

// The unit price Two checks a line with. Two takes a unit price given with too
// few decimals as the exact one, net (plus discount) over quantity to at most
// six places, when the two differ by no more than one step of the given
// price's last decimal (a price with no decimals counts as two). So 100 at
// 0.33 is 33.33 net, as Two allows. A whole-number price is kept.
function unitPrice(line) {
  const given = Number(line.unit_price);
  const quantity = Number(line.quantity);
  const net = cents(line.net_amount);
  const discount = cents(line.discount_amount);
  if (quantity === 0 || Math.round(given * quantity * 100) - discount === net) return given;
  const text = String(line.unit_price);
  if (!text.includes(".")) return given;
  const places = text.replace(/0+$/, "").split(".")[1].length || 2;
  const exact = Math.round(((net + discount) / 100 / quantity) * 1e6) / 1e6;
  const step = 10 ** -places;
  return Math.round(Math.abs(given - exact) / step) <= 1 ? exact : given;
}

// Each line's own identities, as Two checks them on an order and a refund:
// gross is net plus tax, tax is net at the rate, net is quantity at the unit
// price less the discount (each within two pence but gross on an order, which
// is exact).
const lineGrossOk = (l, tolerance) => within(cents(l.gross_amount), cents(l.net_amount) + cents(l.tax_amount), tolerance);
const lineTaxOk = (l) => within(cents(l.tax_amount), cents(l.net_amount) * Number(l.tax_rate), 2);
const lineNetOk = (l, correct = true) => within(cents(l.net_amount), Math.round((correct ? unitPrice(l) : Number(l.unit_price)) * Number(l.quantity) * 100) - cents(l.discount_amount), 2);

// What Two checks of an order or an edit, in cents with Two's tolerances: the
// lines add up to the order's net and tax, net and tax to its gross, each line
// to its own gross, tax rate and unit price, and the tax subtotals to the tax.
// Two's 400 naming every failed check, or null.
function orderAmountsError(body) {
  const lines = body?.line_items || [];
  const failures = [];
  if (!within(cents(body?.net_amount), sum(lines, "net_amount"), 2)) failures.push("Order net amount is not equal to total line item net amounts");
  if (!within(cents(body?.tax_amount), sum(lines, "tax_amount"), 99)) failures.push("Order tax amount is not equal to total line item tax amounts");
  if (cents(body?.gross_amount) !== cents(body?.net_amount) + cents(body?.tax_amount)) failures.push("Order invoice total is not equal to tax + net amount");
  if (!lines.every((l) => lineGrossOk(l, 0))) failures.push("Line item gross amount is not equal to net + tax amount");
  if (!lines.every(lineTaxOk)) failures.push("Line item tax amount differs from tax rate * net amount");
  if (Array.isArray(body?.tax_subtotals) && sum(body.tax_subtotals, "tax_amount") !== cents(body?.tax_amount)) failures.push("Order tax amount mismatch with tax subtotals");
  if (!lines.every((l) => lineNetOk(l))) failures.push("Line item net amount is not equal to (quantity * unit price) - discount amount");
  return failures.length ? error(400, "INVALID_ORDER_AMOUNT_DATA", "Invalid data found in order/line amounts", failures.join(", ")) : null;
}

// What Two checks of a refund body, in Two's order. Fields Two does not know
// are ignored. A refund without an amount is a full refund, which takes no
// prepaid amount or currency. A partial one has an amount and the order's
// currency; the amount plus any prepaid amount equals its lines' gross less
// any buyer refund surcharges (with their tax); its tax subtotals add up to
// the amount plus any prepaid amount within a pound; and each line adds up.
// Two takes a rounded unit price as the exact one on a refund only when it
// makes the tax subtotals itself, so not when the body carries them (as the
// plugin's do). Two's 400, or null.
const SCHEMA = (details) => error(400, "SCHEMA_ERROR", "Error parsing schema", details);
function refundBodyError(order, body) {
  if (body == null || body.amount == null) {
    const named = ["prepaid_amount", "currency"].filter((k) => body?.[k] != null);
    return named.length ? SCHEMA(`A refund without an amount takes no ${named.join(" or ")}.`) : null;
  }
  const isMoney = (v) => /^-?\d+(\.\d{1,2})?$/.test(String(v));
  if (!isMoney(body.amount) || (body.prepaid_amount != null && !isMoney(body.prepaid_amount)) || !body.currency) {
    return SCHEMA("A partial refund needs an amount with at most two decimals and a currency.");
  }
  if (body.currency !== order.currency) {
    return error(400, "REFUND_CURRENCY_ERROR", "Refund currency should be same as Order currency", `${body.currency} is not the order's ${order.currency}.`);
  }
  const lines = body.line_items || [];
  if (!lines.length) return null;
  const surcharges = (body.buyer_refund_surcharges || [])
    .reduce((total, s) => total + Math.round(absCents(s.amount) * (1 + Number(s.tax_rate || 0))), 0);
  const asked = absCents(body.amount) + absCents(body.prepaid_amount);
  const covered = Math.abs(sum(lines, "gross_amount")) - surcharges;
  if (asked !== covered) {
    return error(400, "REFUND_AMOUNT_INCONSISTENT", "Refund amount is inconsistent with original invoice", `Refund amount ${money(asked)} does not equal the lines less surcharges, ${money(covered)}.`);
  }
  const subtotals = Array.isArray(body.tax_subtotals) && body.tax_subtotals.length > 0;
  if (subtotals) {
    const given = body.tax_subtotals.reduce((t, s) => t + cents(s.taxable_amount) + cents(s.tax_amount), 0);
    if (!within(Math.abs(given), asked, 100)) {
      return error(400, "REFUND_AMOUNT_INCONSISTENT", "Refund amount is inconsistent with original invoice", `Inconsistent refund tax subtotals: ${money(given)}.`);
    }
  }
  const bad = lines.find((l) => !(lineGrossOk(l, 2) && lineTaxOk(l) && lineNetOk(l, !subtotals)));
  return bad ? error(400, "REFUND_LINE_ITEMS_INVALID", "Refund line item amounts are invalid.", `Line ${bad.name} does not add up.`) : null;
}

function refundedCents(order) {
  return order.refunds.reduce((total, refund) => total + absCents(refund.total_amount), 0);
}

// What is left per tax rate: the order's subtotals less every refund's.
function remainingSubtotals(order) {
  const buckets = new Map();
  const add = (subtotals, sign) => {
    for (const s of subtotals || []) {
      const b = buckets.get(String(Number(s.tax_rate))) || { net: 0, tax: 0 };
      b.net += sign * absCents(s.taxable_amount);
      b.tax += sign * absCents(s.tax_amount);
      buckets.set(String(Number(s.tax_rate)), b);
    }
  };
  add(order.tax_subtotals, 1);
  for (const refund of order.refunds) add(refund.tax_subtotals, -1);
  return [...buckets].filter(([, b]) => b.net + b.tax > 0)
    .map(([rate, b]) => ({ tax_rate: rate, taxable_amount: money(b.net), tax_amount: money(b.tax) }));
}

function refund(order, body) {
  const refused = refundBodyError(order, body);
  if (refused) return refused;
  const full = !body || body.amount == null;
  const remaining = cents(order.gross_amount) - refundedCents(order);
  const amount = full ? remaining : absCents(body.amount) + absCents(body.prepaid_amount);
  if (amount <= 0 || amount > remaining) {
    return error(400, "INVALID_REFUND", "Invalid refund", `Refund of ${money(amount)} exceeds the ${money(remaining)} left.`);
  }
  const item = {
    id: randomUUID(),
    amount: money(amount),
    total_amount: `-${money(amount)}`,
    currency: order.currency,
    tax_subtotals: full ? remainingSubtotals(order) : body.tax_subtotals || [],
    line_items: full ? [] : body.line_items || [],
  };
  order.refunds.push(item);
  order.state = refundedCents(order) >= cents(order.gross_amount) ? "REFUNDED" : order.state;
  return [201, item];
}

// Two gives every order line an id, which refunds then reference.
const withLineIds = (lines) => (lines || []).map((line) => ({ ...line, id: randomUUID() }));

const STATES_FOR = {
  confirm: ["VERIFIED"],
  fulfillments: ["CONFIRMED", "VERIFIED"],
  cancel: ["UNVERIFIED", "VERIFIED", "CONFIRMED"],
  refund: ["FULFILLED", "DELIVERED", "REFUNDED"],
  update: ["VERIFIED", "CONFIRMED"],
};

function orderAction(order, action, body) {
  if (!STATES_FOR[action].includes(order.state)) {
    return error(400, "INVALID_STATE", "Invalid order state", `Order is ${order.state}.`);
  }
  switch (action) {
    case "confirm": order.state = "CONFIRMED"; break;
    case "fulfillments": order.state = "FULFILLED"; break;
    case "cancel": order.state = "CANCELLED"; break;
    case "refund": return refund(order, body);
    case "update": {
      const refused = orderAmountsError(body);
      if (refused) return refused;
      const { merchant_urls: _ignored, ...edit } = body || {};
      Object.assign(order, edit, { line_items: withLineIds(edit.line_items) });
      break;
    }
  }
  return [200, order];
}

// The Two API: what the shop calls. Every merchant call (/v1/) needs the key.
function api(method, path, body, key) {
  if (path.startsWith("/v1/") && key !== API_KEY) {
    return error(401, "AUTHENTICATION_INVALID", "Authentication headers invalid", "X-API-Key is incorrect or has expired");
  }
  // The sole-trader signup's two tokens, which the checkout mints at load.
  // Two answers each in a header, and only with the merchant's key.
  const mint = { "/registry/v1/delegation": "delegation", "/autofill/v1/delegation": "autofill" }[path];
  if (method === "POST" && mint) {
    if (key !== API_KEY) return error(401, "AUTHENTICATION_INVALID", "Authentication headers invalid", "X-API-Key is incorrect or has expired");
    return [200, {}, { "two-delegated-authority-token": `e2e-${mint}-${randomUUID()}` }];
  }
  if (method === "GET" && path === "/v1/merchant/verify_api_key") {
    return [200, { id: MERCHANT_ID, short_name: "e2e" }];
  }
  if (method === "GET" && path === `/v1/merchant/${MERCHANT_ID}`) {
    return [200, { id: MERCHANT_ID, short_name: "e2e", available_terms: [30, 60, 90], due_in_days: 30, invoice_distributed_by_merchant: false }];
  }
  if (method === "GET" && path === "/refdata/v1/fx-rates") {
    return [200, { base: "EUR", as_of: new Date().toISOString().slice(0, 10), rates: { EUR: 1, GBP: 0.85, USD: 1.08, NOK: 11.6, SEK: 11.3, DKK: 7.46 } }];
  }
  if (method === "POST" && path === "/v1/pricing/order/fee") {
    return [200, { buyer_fee_share: "0.00", currency: body?.currency }];
  }
  if (method === "POST" && path === "/pricing/v1/merchant/rates") {
    // What Two charges the merchant per term, numbers as strings as Two sends them: 1% plus 0.10 per 30 days.
    if (key !== API_KEY) return error(401, "AUTHENTICATION_INVALID", "Authentication headers invalid", "X-API-Key is incorrect or has expired");
    const terms = Array.isArray(body?.net_terms) ? body.net_terms : [];
    return [200, { currency: "GBP", rates: terms.map((days) => ({ net_terms: days, percentage_fee: String(days / 30), fixed_fee: (days / 300).toFixed(2) })) }];
  }
  if (method === "GET" && path === "/companies/v2/company") {
    return [200, { items: [{ name: "E2E Trading Ltd", national_identifier: { id: "12345678" }, highlight: "", lookup_id: "e2e-1" }] }];
  }
  if (method === "GET" && path === "/companies/v2/company/e2e-1") {
    return [200, { national_identifier: { id: "12345678" }, addresses: [{ type: "REGISTERED", street_address: "1 Registry Road", city: "London", postal_code: "EC1A 1AA" }] }];
  }
  if (method === "GET" && path === "/companies/v2/supported-countries") {
    return [200, { supported_countries: ["GB", "NO", "SE", "NL", "ES"] }];
  }
  if (method === "GET" && path.startsWith("/registry/v1/supported-company-types/")) {
    return [200, { supported_company_types: ["LIMITED_COMPANY"] }];
  }
  if (method === "POST" && path === "/v1/order_intent") {
    return [200, { approved: true }];
  }
  if (method === "POST" && path === "/v1/order") {
    const refused = orderAmountsError(body);
    if (refused) return refused;
    const id = randomUUID();
    const order = { ...body, line_items: withLineIds(body?.line_items), id, state: "UNVERIFIED", status: "APPROVED", refunds: [], payment_url: `${PUBLIC_URL}/checkout/${id}` };
    orders.set(id, order);
    return [201, order];
  }
  const m = path.match(/^\/v1\/order\/([0-9a-f-]{36})(?:\/(confirm|fulfillments|cancel|refund))?$/);
  const order = m && orders.get(m[1]);
  if (m && !order) {
    return error(404, "NOT_FOUND", "Order not found", "No such order.");
  }
  if (order && method === "GET" && !m[2]) return [200, order];
  if (order && method === "PUT" && !m[2]) return orderAction(order, "update", body);
  if (order && method === "POST" && m[2]) return orderAction(order, m[2], body);
  return error(404, "NOT_STUBBED", "Not stubbed", `${method} ${path} is not stubbed.`);
}

// Two's hosted checkout: the buyer passes it at once, or leaves (cancel).
function hostedCheckout(id, res) {
  const order = orders.get(id);
  if (!order) return send(res, 404, { error: "no such order" });
  if (checkoutOutcome === "cancel") {
    res.writeHead(302, { Location: order.merchant_urls.merchant_cancel_order_url }).end();
    return;
  }
  order.state = "VERIFIED";
  res.writeHead(302, { Location: order.merchant_urls.merchant_confirmation_url }).end();
}

// A fault: "fail" answers with the status and applies nothing; "drop" applies
// the request, keeps its answer for a replay, and closes the connection with
// no answer. `then` lists faults armed once this one has fired.
const fault = (f) => ({ method: f.method, path: new RegExp(f.path), mode: f.mode === "drop" ? "drop" : "fail", status: f.status ?? 503, then: f.then || [] });

function control(method, path, body, query, res) {
  if (method === "POST" && path === "/__stub/fault") {
    faults.push(fault(body));
    return send(res, 200, { faults: faults.length });
  }
  if (method === "POST" && path === "/__stub/checkout") {
    checkoutOutcome = body.outcome === "cancel" ? "cancel" : "verify";
    return send(res, 200, { checkout: checkoutOutcome });
  }
  if (method === "GET" && path === "/__stub/requests") {
    const prefix = query.get("path") || "";
    return send(res, 200, requests.filter((r) => r.path.startsWith(prefix)));
  }
  const m = path.match(/^\/__stub\/orders\/([0-9a-f-]{36})$/);
  if (method === "GET" && m) {
    return orders.has(m[1]) ? send(res, 200, orders.get(m[1])) : send(res, 404, {});
  }
  // Sets the order's state as Two would on its own: a buyer verifying at Two, or Two cancelling an order.
  if (method === "POST" && m) {
    if (!orders.has(m[1])) return send(res, 404, {});
    orders.get(m[1]).state = String(body.state || "");
    return send(res, 200, orders.get(m[1]));
  }
  return send(res, 404, { error: `no control route ${method} ${path}` });
}

function send(res, status, body, headers) {
  res.writeHead(status, { "Content-Type": "application/json", ...headers }).end(JSON.stringify(body));
}

// One API request, logged with its answer: `status` is the HTTP status sent,
// or "dropped"; `replayed` marks an answer repeated for its Idempotency-Key.
function answer(req, res, path, body) {
  const key = req.headers["idempotency-key"] || null;
  const entry = { seq: requests.length, method: req.method, path, key, body, at: new Date().toISOString() };
  requests.push(entry);
  const found = faults.findIndex((f) => f.method === req.method && f.path.test(path));
  const injected = found === -1 ? null : faults.splice(found, 1)[0];
  if (injected) faults.push(...injected.then.map(fault));
  if (injected?.mode === "fail") {
    entry.status = injected.status;
    return send(res, injected.status, { error_code: "STUB_FAULT", error_message: "Injected by the E2E suite." });
  }
  let reply;
  if (key && req.method !== "GET" && replies.has(key)) {
    reply = replies.get(key);
    entry.replayed = true;
  } else {
    reply = api(req.method, path, body, req.headers["x-api-key"]);
    if (key && req.method !== "GET" && reply[0] < 500) replies.set(key, [reply[0], structuredClone(reply[1]), reply[2]]);
  }
  if (reply[0] >= 400) console.log(`two-api stub: ${req.method} ${path} answered ${reply[0]} ${JSON.stringify(reply[1])}`);
  if (injected?.mode === "drop") {
    entry.status = "dropped";
    req.socket.destroy();
    return;
  }
  entry.status = reply[0];
  return send(res, reply[0], reply[1], reply[2]);
}

function handler(isPublic) {
  return (req, res) => {
    let raw = "";
    req.on("data", (chunk) => (raw += chunk));
    req.on("end", () => {
      // A handler that throws answers 500 and says why, rather than taking the
      // stub down and failing every later spec with a connection error.
      try {
        const url = new URL(req.url, "http://stub");
        const path = url.pathname;
        let body = null;
        try {
          body = raw ? JSON.parse(raw) : null;
        } catch {
          return send(res, 400, { error_code: "SCHEMA_ERROR", error_message: "Error parsing schema", error_details: "The body is not JSON." });
        }
        if (isPublic) {
          const checkout = path.match(/^\/checkout\/([0-9a-f-]{36})$/);
          if (checkout) return hostedCheckout(checkout[1], res);
          if (path.startsWith("/__stub/")) return control(req.method, path, body || {}, url.searchParams, res);
          return send(res, 404, {});
        }
        return answer(req, res, path, body);
      } catch (thrown) {
        console.error(`two-api stub: ${req.method} ${req.url} threw`, thrown);
        if (!res.headersSent && !res.destroyed) send(res, 500, { error_code: "STUB_ERROR", error_message: String(thrown?.stack || thrown) });
      }
    });
  };
}

const tls = { key: fs.readFileSync(process.env.TLS_KEY), cert: fs.readFileSync(process.env.TLS_CERT) };
https.createServer(tls, handler(false)).listen(443, () => console.log("two-api stub: API on 443"));
https.createServer(tls, handler(true)).listen(Number(PORT), () => console.log(`two-api stub: hosted checkout and control on ${PORT}`));
