import { expect, test } from "@playwright/test";

import { addHistory, addOneMore, openOrder, refundLine, retryRefund } from "../pages/admin";
import { refundRows, twoOrderId } from "../pages/shop";
import { placeOrder } from "../pages/storefront";
import { cents, dropNext, expectState, failNext, refundedCents, requests, stubOrder } from "../pages/stub";

// The status maps dev/configure.php installs: fulfil on Shipped, cancel on
// Canceled, refund the rest on Refunded.

test("an order edit before fulfilment is sent to Two, then Shipped fulfils it", async ({ page }) => {
  const orderId = await placeOrder(page, 1);
  const twoId = twoOrderId(orderId);
  const before = await stubOrder(twoId);

  await openOrder(page, orderId);
  await addOneMore(page, "iPhone", 2);
  await expect.poll(async () => (await stubOrder(twoId)).line_items[0].quantity, { message: "Two holds the edited quantity" }).toBe(2);
  expect(cents((await stubOrder(twoId)).gross_amount)).toBeGreaterThan(cents(before.gross_amount));
  const puts = (await requests(`/v1/order/${twoId}`)).filter((r) => r.method === "PUT");
  expect(puts).toHaveLength(1);
  expect("invoice_type" in puts[0].body, "the edit body carries no invoice type").toBe(false);

  await addHistory(page, "Shipped");
  await expectState(twoId, "FULFILLED");
});

test("a partial refund from the Two tab, then Refunded refunds the rest", async ({ page }) => {
  const orderId = await placeOrder(page, 2);
  const twoId = twoOrderId(orderId);
  await openOrder(page, orderId);
  await addHistory(page, "Shipped");
  await expectState(twoId, "FULFILLED");

  const line = (await stubOrder(twoId)).line_items[0];
  await refundLine(page, 1);
  expect(refundRows(orderId), "the partial refund is settled as done").toEqual([{ state: "done", note: "note_refunded" }]);
  const partly = await stubOrder(twoId);
  expect(refundedCents(partly), "one of two units is refunded").toBe(cents(line.gross_amount) / 2);
  expect(partly.state).toBe("FULFILLED");

  await addHistory(page, "Refunded");
  await expectState(twoId, "REFUNDED");
  const full = await stubOrder(twoId);
  expect(refundedCents(full), "the whole order is refunded").toBe(cents(full.gross_amount));
  expect(full.refunds).toHaveLength(2);
});

test("a refund with no answer from Two is retried from the Two tab and applied once", async ({ page }) => {
  const orderId = await placeOrder(page, 2);
  const twoId = twoOrderId(orderId);
  await openOrder(page, orderId);
  await addHistory(page, "Shipped");
  await expectState(twoId, "FULFILLED");

  await failNext("POST", `^/v1/order/${twoId}/refund$`);
  await refundLine(page, 1);
  expect(refundRows(orderId), "the unanswered refund waits as unknown").toEqual([{ state: "unknown", note: "note_refund_unknown" }]);
  expect((await stubOrder(twoId)).refunds).toHaveLength(0);

  await retryRefund(page);
  expect(refundRows(orderId), "the retry is settled as done").toEqual([{ state: "done", note: "note_refunded" }]);
  const order = await stubOrder(twoId);
  expect(order.refunds, "the retry is applied once").toHaveLength(1);
  const sent = (await requests(`/v1/order/${twoId}/refund`)).map((r) => r.key);
  expect(sent, "the retry resends under the first attempt's key").toHaveLength(2);
  expect(sent[1]).toBe(sent[0]);
});

test("a refund Two applied but whose answer was lost is retried from the Two tab and not refunded twice", async ({ page }) => {
  const orderId = await placeOrder(page, 2);
  const twoId = twoOrderId(orderId);
  await openOrder(page, orderId);
  await addHistory(page, "Shipped");
  await expectState(twoId, "FULFILLED");

  // Two applies the refund and the connection drops; the read-back after it fails too, so the shop cannot tell.
  await dropNext("POST", `^/v1/order/${twoId}/refund$`, [{ method: "GET", path: `^/v1/order/${twoId}$` }]);
  await refundLine(page, 1);
  expect(refundRows(orderId), "the unanswered refund waits as unknown").toEqual([{ state: "unknown", note: "note_refund_unknown" }]);
  expect((await stubOrder(twoId)).refunds, "Two applied the first attempt").toHaveLength(1);

  await retryRefund(page);
  expect(refundRows(orderId), "the retry finds Two applied the first attempt").toEqual([{ state: "done", note: "note_refunded_earlier" }]);
  expect((await stubOrder(twoId)).refunds, "the retry refunds nothing more").toHaveLength(1);
  const sent = await requests(`/v1/order/${twoId}/refund`);
  expect(sent.map((r) => r.status), "the first attempt went unanswered, the retry was answered").toEqual(["dropped", 201]);
  expect(sent[1].key, "the retry resends under the first attempt's key").toBe(sent[0].key);
  expect(sent[1].replayed, "Two answered the retry with the first attempt's refund").toBe(true);
});

test("Canceled cancels the Two order", async ({ page }) => {
  const orderId = await placeOrder(page, 1);
  const twoId = twoOrderId(orderId);
  await openOrder(page, orderId);
  await addHistory(page, "Canceled");
  await expectState(twoId, "CANCELLED");
});
