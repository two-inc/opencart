import { expect, test } from "@playwright/test";

import { orderStatusId, shopDefaultStatusId, sql, twoOrderId, twoSetting } from "../pages/shop";
import { addToCart, chooseTwo, confirmAndReturn, guestDetails, placeOrder } from "../pages/storefront";
import { expectState, requests, stubOrder } from "../pages/stub";

test("a buyer finds their company, places the order with Two and sees the confirmation", async ({ page }) => {
  const seen = (await requests("")).length;
  await addToCart(page, 1);
  await chooseTwo(page, await guestDetails(page, "E2E Trad"));
  // The explainer is the extension's own "?" icon, named for assistive technology (TWO-27110).
  const about = page.locator("#checkout-confirm [data-two-about]");
  await expect(about, "the explainer link is named for screen readers").toHaveAccessibleName("What is Two?");
  await expect(about, "it shows an icon, not the words").toHaveText("");
  await expect.poll(() => about.locator("img").evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0), { message: "the shipped question icon loads" }).toBe(true);
  const orderId = await confirmAndReturn(page);

  const twoId = twoOrderId(orderId);
  expect(twoId, "the shop order is linked to a Two order").not.toBe("");
  await expectState(twoId, "CONFIRMED");
  // A fresh install starts on Processing (admin-settings.spec.ts); an upgraded one that never saved a confirmed status keeps its default.
  const confirmed = Number(twoSetting("confirmed_status_id") ?? "0");
  expect(orderStatusId(orderId), "the order is placed at the confirmed status, else the shop's default").toBe(confirmed > 0 ? confirmed : shopDefaultStatusId());

  const created = await stubOrder(twoId);
  expect((created as any).buyer.company.organization_number, "the searched company's number is sent").toBe("12345678");
  const sent = (await requests("")).slice(seen);
  const writes = sent.filter((r) => r.path.startsWith("/v1/order") && r.method !== "GET");
  expect(writes.map((r) => r.path), "the order is created and confirmed").toEqual(expect.arrayContaining(["/v1/order", `/v1/order/${twoId}/confirm`]));
  expect(writes.filter((r) => !r.key), "every write carries an Idempotency-Key").toEqual([]);
  // The stub refuses a wrong API key and amounts that do not add up as Two does.
  expect(sent.filter((r) => r.status !== 200 && r.status !== 201), "Two accepts every call the shop makes").toEqual([]);
  // Two decides the invoice type from the merchant account, so the shop sends none: not on the intent, not on the order.
  const bodies = sent.filter((r) => r.method === "POST" && (r.path === "/v1/order" || r.path === "/v1/order_intent"));
  expect(bodies.map((r) => r.path), "the order intent and the order were both captured").toEqual(expect.arrayContaining(["/v1/order_intent", "/v1/order"]));
  expect(bodies.filter((r) => "invoice_type" in r.body), "no intent or create body carries an invoice type").toEqual([]);
  // Two's API does not define an order-level discount_amount or billing_address on the intent, so only the create carries them (a line keeps its own discount_amount).
  const intent = bodies.find((r) => r.path === "/v1/order_intent")!.body;
  const create = bodies.find((r) => r.path === "/v1/order")!.body;
  expect(["discount_amount", "billing_address"].filter((k) => k in intent), "the intent carries no order-level discount_amount or billing_address").toEqual([]);
  expect(["discount_amount", "billing_address"].filter((k) => !(k in create)), "the create still carries both").toEqual([]);
  expect(intent.line_items.every((l: any) => "discount_amount" in l), "intent lines keep their own discount_amount").toBe(true);
});

test("company autofill writes the registry's phone into the buyer's telephone field", async ({ page }) => {
  // OpenCart shows the telephone field only when the shop asks for it (TWO-27110).
  const display = "DELETE FROM oc_setting WHERE store_id = 0 AND `key` = 'config_telephone_display'";
  sql(`${display}; INSERT INTO oc_setting SET store_id = 0, code = 'config', \`key\` = 'config_telephone_display', value = '1', serialized = 0`);
  try {
    await addToCart(page, 1);
    await guestDetails(page, "E2E Trad");
    await expect(page.locator("#input-telephone"), "the picked company's registry phone").toHaveValue("+44 20 7946 0000");
  } finally {
    sql(display);
  }
});

test("a coupon reaches Two as a negative discount line, and Two accepts the order", async ({ page }) => {
  const seen = (await requests("")).length;
  const orderId = await placeOrder(page, 1, "E2E Trad", "2222");
  const twoId = twoOrderId(orderId);
  await expectState(twoId, "CONFIRMED");

  const lines = (await stubOrder(twoId)).line_items;
  expect(lines.filter((l) => Number(l.net_amount) < 0 && Number(l.tax_amount) < 0), "one discount line, net and tax below zero").toHaveLength(1);
  const sent = (await requests("")).slice(seen);
  expect(sent.filter((r) => r.status !== 200 && r.status !== 201), "Two accepts every call the shop makes").toEqual([]);
});

test("a buyer who registers an account at checkout pays with Two, the company kept with the new saved address", async ({ page }) => {
  await addToCart(page, 1);
  const company = await guestDetails(page, "E2E Trad", true, true);
  // The tile reads the company from the address OpenCart just stored for the new account.
  await chooseTwo(page, company);
  const orderId = await confirmAndReturn(page);

  const twoId = twoOrderId(orderId);
  expect(twoId, "the shop order is linked to a Two order").not.toBe("");
  await expectState(twoId, "CONFIRMED");
  expect((await stubOrder(twoId) as any).buyer.company.organization_number, "the searched company's number is sent").toBe("12345678");
});
