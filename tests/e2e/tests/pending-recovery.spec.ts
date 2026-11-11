import { APIRequestContext, Page, expect, test } from "@playwright/test";

import { checkStatusWithTwo, openOrder, openSettings } from "../pages/admin";
import { backdate, placings, historyComments, isUnplaced, orderStatusId, shopDefaultStatusId, twoOrderId, twoSetting } from "../pages/shop";
import { placeWithoutReturn } from "../pages/storefront";
import { dropNext, expectState, requests, setState, verifyAtTwo } from "../pages/stub";

// TWO-26300: the buyer verifies at Two and never comes back to the shop. The
// scheduled check (the token URL every 15 minutes) and the Two tab's Check
// status with Two button ask Two and place the order. The specs backdate the
// order by 31 minutes so the real selection runs. Serial: each token call
// checks every order due, so one spec's orders must not wait for another's.
test.describe.configure({ mode: "serial" });

function placedStatus(): number {
  const confirmed = Number(twoSetting("confirmed_status_id") ?? "0");
  return confirmed > 0 ? confirmed : shopDefaultStatusId();
}

/** Calls the Diagnostics tab's scheduled URL with its token, as the merchant's crontab does. */
async function runSchedule(page: Page, request: APIRequestContext): Promise<string> {
  if (!twoSetting("cron_token")) await openSettings(page); // the admin page mints the token
  const res = await request.get(`/index.php?route=extension/two/cron/two&token=${twoSetting("cron_token")}`);
  expect(res.status(), "the scheduled URL accepts the call").toBe(200);
  const body = await res.text();
  expect(body, "every accepted call runs the pending check").toContain("pending: ");
  return body;
}

const confirms = async (twoId: string) => (await requests(`/v1/order/${twoId}/confirm`)).filter((r) => r.method === "POST");

test("the scheduled check places an order the buyer verified but never returned from", async ({ page, request }) => {
  const { orderId, paymentUrl } = await placeWithoutReturn(page);
  const twoId = twoOrderId(orderId);
  const returnUrl = await verifyAtTwo(paymentUrl);
  await expectState(twoId, "VERIFIED");

  await runSchedule(page, request);
  expect(isUnplaced(orderId), "an order under 30 minutes old is left to the buyer").toBe(true);
  expect(await confirms(twoId)).toHaveLength(0);

  backdate(orderId);
  await runSchedule(page, request);
  expect(orderStatusId(orderId), "placed at the confirmed status").toBe(placedStatus());
  await expectState(twoId, "CONFIRMED");
  expect(await confirms(twoId), "confirmed with Two exactly once").toHaveLength(1);
  expect(historyComments(orderId).filter((c) => c.includes("by the scheduled check")), "one note naming the scheduled check").toHaveLength(1);

  // The buyer comes back late: the success page, and nothing sent or written again.
  await page.goto(returnUrl);
  await page.waitForURL(/route=checkout\/success/);
  expect(await confirms(twoId)).toHaveLength(1);
  expect(placings(orderId, placedStatus()), "placed once").toBe(1);
});

test("Check status with Two on the Two tab places a verified order", async ({ page }) => {
  const { orderId, paymentUrl } = await placeWithoutReturn(page);
  const twoId = twoOrderId(orderId);
  await verifyAtTwo(paymentUrl);

  await openOrder(page, orderId);
  const shown = await checkStatusWithTwo(page);
  expect(shown).toContain(twoId);
  expect(orderStatusId(orderId), "placed at the confirmed status").toBe(placedStatus());
  await expectState(twoId, "CONFIRMED");
  expect(await confirms(twoId), "confirmed with Two exactly once").toHaveLength(1);
  expect(historyComments(orderId).filter((c) => c.includes("confirmed by admin")), "the note names the admin user").toHaveLength(1);
});

test("an order Two cancelled is left unplaced with a note, and no cancel is sent to Two", async ({ page, request }) => {
  const { orderId } = await placeWithoutReturn(page);
  const twoId = twoOrderId(orderId);
  await setState(twoId, "CANCELLED");
  backdate(orderId);

  await runSchedule(page, request);
  expect(isUnplaced(orderId), "the order stays unplaced").toBe(true);
  expect((await requests(`/v1/order/${twoId}/cancel`)).filter((r) => r.method === "POST"), "nothing is cancelled at Two").toHaveLength(0);
  expect(historyComments(orderId).filter((c) => c.includes("as cancelled")), "one note").toHaveLength(1);

  // Checked once: a cancelled order is not selected again.
  await runSchedule(page, request);
  expect(historyComments(orderId).filter((c) => c.includes("as cancelled"))).toHaveLength(1);
});

test("a confirm whose answer was lost, then the buyer's late return, places the order once", async ({ page, request }) => {
  const { orderId, paymentUrl } = await placeWithoutReturn(page);
  const twoId = twoOrderId(orderId);
  const returnUrl = await verifyAtTwo(paymentUrl);
  backdate(orderId);

  // Two applies the scheduled check's confirm, and the answer never arrives: the order waits.
  await dropNext("POST", `^/v1/order/${twoId}/confirm$`);
  await runSchedule(page, request);
  expect(isUnplaced(orderId), "an unanswered confirm places nothing").toBe(true);
  expect(historyComments(orderId).filter((c) => c.includes("confirmed")), "and notes no confirmation").toHaveLength(0);
  await expectState(twoId, "CONFIRMED");

  await page.goto(returnUrl);
  await page.waitForURL(/route=checkout\/success/);
  expect(orderStatusId(orderId)).toBe(placedStatus());
  expect(placings(orderId, placedStatus()), "placed once").toBe(1);
  expect(await confirms(twoId), "the one confirm, never repeated").toHaveLength(1);

  await runSchedule(page, request);
  expect(placings(orderId, placedStatus()), "the next run leaves the placed order alone").toBe(1);
});
