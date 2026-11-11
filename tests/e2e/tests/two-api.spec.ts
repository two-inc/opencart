import { expect, test } from "@playwright/test";

import { STUB_API_KEY } from "../config";
import { addHistory, openOrder } from "../pages/admin";
import { callTwo, twoOrderId } from "../pages/shop";
import { placeOrder } from "../pages/storefront";
import { expectState, requests, stubOrder } from "../pages/stub";

// The stub refuses what Two refuses, so a shop that sends the wrong API key or
// amounts that do not add up fails the suite as it would fail against Two.
// Every refusal has an accepted twin, so it is the change that is refused.

const add = (amount: string, cents: number): string => ((Math.round(Number(amount) * 100) + cents) / 100).toFixed(2);

type Line = { name: string; quantity: number; unit_price: string; net_amount: string; tax_amount: string; gross_amount: string; tax_rate: string; discount_amount: string };
const newLine = (name: string, quantity: number, unit: string, net: string, tax: string): Line => ({
  name, quantity, unit_price: unit, net_amount: net, tax_amount: tax, gross_amount: add(net, Math.round(Number(tax) * 100)), tax_rate: "0.2", discount_amount: "0.00",
});

/** The order with one more line, its totals and tax subtotals moved by the line's amounts as given in `counted`. */
function withLine(order: any, added: Line, counted: Line = added): any {
  const c = (v: string) => Math.round(Number(v) * 100);
  return {
    ...order,
    net_amount: add(order.net_amount, c(counted.net_amount)),
    tax_amount: add(order.tax_amount, c(counted.tax_amount)),
    gross_amount: add(order.gross_amount, c(counted.gross_amount)),
    line_items: [...order.line_items, added],
    tax_subtotals: [...(order.tax_subtotals || []), { tax_rate: "0.2", taxable_amount: counted.net_amount, tax_amount: counted.tax_amount }],
  };
}

test("Two refuses a wrong API key, an order whose amounts do not add up and a malformed refund", async ({ page }) => {
  const orderId = await placeOrder(page, 2);
  const twoId = twoOrderId(orderId);
  await openOrder(page, orderId);
  await addHistory(page, "Shipped");
  await expectState(twoId, "FULFILLED");

  expect(callTwo("GET", `/v1/order/${twoId}`, null, STUB_API_KEY).status, "the shop's key reads the order").toBe(200);
  const wrongKey = callTwo("GET", `/v1/order/${twoId}`, null, "not-the-shops-key");
  expect([wrongKey.status, wrongKey.json.error_code], "a wrong key is refused").toEqual([401, "AUTHENTICATION_INVALID"]);

  const created = (await requests("/v1/order")).find((r) => r.path === "/v1/order" && r.method === "POST" && r.body.merchant_order_id === String(orderId))!.body;
  expect(callTwo("POST", "/v1/order", created, STUB_API_KEY).status, "the shop's own order body is accepted").toBe(201);
  const line = created.line_items[0];
  const orders: [string, object, string][] = [
    ["the order's gross is a penny over its net and tax", { gross_amount: add(created.gross_amount, 1) }, "Order invoice total is not equal to tax + net amount"],
    ["the lines' net is a pound short of the order's", { net_amount: add(created.net_amount, 100), gross_amount: add(created.gross_amount, 100) }, "Order net amount is not equal to total line item net amounts"],
    ["a line's gross is a penny over its net and tax", { line_items: [{ ...line, gross_amount: add(line.gross_amount, 1) }, ...created.line_items.slice(1)] }, "Line item gross amount is not equal to net + tax amount"],
  ];
  const coupon = newLine("Coupon", 1, "-10.00", "-10.00", "-2.00");
  const pens = newLine("Pens", 100, "0.33", "33.33", "6.67");
  const accepted: [string, object][] = [
    ["a coupon's negative discount line", withLine(created, coupon)],
    ["100 at a rounded unit price of 0.33, net 33.33", withLine(created, pens)],
  ];
  for (const [what, body] of accepted) {
    expect(callTwo("POST", "/v1/order", body, STUB_API_KEY).status, what).toBe(201);
  }
  orders.push(
    ["a coupon line given as positive", withLine(created, newLine("Coupon", 1, "10.00", "10.00", "2.00"), coupon), "Order net amount is not equal to total line item net amounts"],
    ["100 at 0.25, net 33.33", withLine(created, { ...pens, unit_price: "0.25" }), "Line item net amount is not equal to (quantity * unit price) - discount amount"],
  );
  for (const [what, change, detail] of orders) {
    const refused = callTwo("POST", "/v1/order", { ...created, ...change }, STUB_API_KEY);
    expect([refused.status, refused.json.error_code], what).toEqual([400, "INVALID_ORDER_AMOUNT_DATA"]);
    expect(refused.json.error_details, what).toContain(detail);
  }

  const order = await stubOrder(twoId);
  const item = order.line_items[0] as any;
  const refund = {
    amount: item.gross_amount,
    currency: order.currency,
    line_items: [item],
    tax_subtotals: [{ tax_rate: item.tax_rate, taxable_amount: item.net_amount, tax_amount: item.tax_amount }],
  };
  // Part prepaid, less a 1.00 surcharge at 20%: the subtotals add up to the amount plus the prepaid amount.
  const surcharged = {
    ...refund,
    amount: add(refund.amount, -220),
    prepaid_amount: "1.00",
    buyer_refund_surcharges: [{ surcharge_type: "processing_fee", amount: "1.00", tax_rate: "0.2" }],
    tax_subtotals: [{ tax_rate: item.tax_rate, taxable_amount: add(item.net_amount, -100), tax_amount: add(item.tax_amount, -20) }],
    note: "a field Two ignores",
  };
  const refunds: [string, object, string][] = [
    ["the amount is a penny over its lines", { ...refund, amount: add(refund.amount, 1) }, "REFUND_AMOUNT_INCONSISTENT"],
    ["the currency is not the order's", { ...refund, currency: order.currency === "EUR" ? "GBP" : "EUR" }, "REFUND_CURRENCY_ERROR"],
    // Two allows a refund line two pence either way.
    ["a line's tax is a pound over its rate, its net a pound under", { ...refund, line_items: [{ ...item, net_amount: add(item.net_amount, -100), tax_amount: add(item.tax_amount, 100) }] }, "REFUND_LINE_ITEMS_INVALID"],
    ["a full refund names a currency", { currency: order.currency }, "SCHEMA_ERROR"],
    ["the amount and prepaid amount leave out a surcharge", { ...surcharged, amount: add(surcharged.amount, 120) }, "REFUND_AMOUNT_INCONSISTENT"],
    ["the subtotals add up to the lines, not to the amount less the surcharge", { ...surcharged, tax_subtotals: refund.tax_subtotals }, "REFUND_AMOUNT_INCONSISTENT"],
    // Two takes a rounded unit price as exact on a refund only when it makes the subtotals itself.
    ["100 at a rounded 0.33, with subtotals", { amount: "40.00", currency: order.currency, line_items: [pens], tax_subtotals: [{ tax_rate: "0.2", taxable_amount: "33.33", tax_amount: "6.67" }] }, "REFUND_LINE_ITEMS_INVALID"],
  ];
  for (const [what, body, code] of refunds) {
    const refused = callTwo("POST", `/v1/order/${twoId}/refund`, body, STUB_API_KEY);
    expect([refused.status, refused.json.error_code], what).toEqual([400, code]);
  }
  expect((await stubOrder(twoId)).refunds, "no refused refund is applied").toHaveLength(0);
  expect(callTwo("POST", `/v1/order/${twoId}/refund`, surcharged, STUB_API_KEY).status, "the lines less a surcharge with its tax, part prepaid, are accepted").toBe(201);
  const full = { order_revision: "6f1c2e44-1d5a-4c1e-9a43-0c6f1f1b6a10", note: "a field Two ignores" };
  expect(callTwo("POST", `/v1/order/${twoId}/refund`, full, STUB_API_KEY).status, "a full refund with a revision and an unknown field is accepted").toBe(201);
  await expectState(twoId, "REFUNDED");
});
