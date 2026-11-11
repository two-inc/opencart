import { expect, test } from "@playwright/test";

import { SANDBOX_API_KEY, SANDBOX_API_URL } from "../config";
import { latestOrderId, orderStatusId, twoOrderId } from "../pages/shop";
import { addToCart, chooseTwo, guestDetails } from "../pages/storefront";

// One whole checkout against Two's sandbox, run only on the sandbox leg
// (staging pushes and nightly, with the TWO_SANDBOX_API_KEY secret). The
// buyer's verification at Two's hosted checkout is done through Two's test
// endpoint POST /test/verify_order/{id}, which answers with the shop's
// confirmation URL; a failure there fails the leg with its own message.
const SEARCH = process.env.E2E_SANDBOX_COMPANY ?? "RESTAURANT 53";
const headers = { "X-API-Key": SANDBOX_API_KEY, "Content-Type": "application/json" };

test("a whole checkout against Two's sandbox", async ({ page }) => {
  test.skip(!SANDBOX_API_KEY, "no sandbox API key: the sandbox leg runs with TWO_SANDBOX_API_KEY only");

  await addToCart(page, 1);
  const company = await guestDetails(page, SEARCH, false);
  await chooseTwo(page, company);
  await page.click("#button-two-confirm");
  await page.waitForURL((url) => url.hostname.endsWith("two.inc"), { timeout: 60_000 });

  const orderId = latestOrderId();
  const twoId = twoOrderId(orderId);
  expect(twoId, "the shop created a Two order before sending the buyer to Two").not.toBe("");

  const verify = await fetch(`${SANDBOX_API_URL}/test/verify_order/${twoId}`, { method: "POST", headers });
  const verified = verify.ok ? ((await verify.json()) as { merchant_confirmation_url?: string }) : {};
  expect(verify.ok, `Two's sandbox verifies the order: POST /test/verify_order answered HTTP ${verify.status}`).toBeTruthy();
  expect(verified.merchant_confirmation_url, "POST /test/verify_order answers with the confirmation URL").toBeTruthy();

  await page.goto(verified.merchant_confirmation_url as string);
  await page.waitForURL(/route=checkout\/success/, { timeout: 60_000 });
  await expect(page.locator("#content h1")).toContainText("Your order has been placed");
  expect(orderStatusId(orderId), "the order is placed").toBeGreaterThan(0);

  const two = await fetch(`${SANDBOX_API_URL}/v1/order/${twoId}`, { headers });
  expect(((await two.json()) as { state: string }).state, "Two holds the order as confirmed").toBe("CONFIRMED");
});
