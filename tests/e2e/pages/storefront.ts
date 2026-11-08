import { Page, expect } from "@playwright/test";

import { PRODUCT_ID, STUB_PORT } from "../config";
import { latestOrderId } from "./shop";

// The OpenCart 4.1 guest checkout, as a buyer drives it: one page with the
// account and address form, then shipping and payment chosen in their modals.

export async function addToCart(page: Page, quantity: number): Promise<void> {
  await page.goto(`/index.php?route=product/product&product_id=${PRODUCT_ID}`);
  await page.fill("#input-quantity", String(quantity));
  await page.click("#button-cart");
  await expect(page.locator("#alert")).toContainText("Success");
}

/**
 * Fills the guest form (or registers an account with it), finding the company with Two's shared company search.
 * With `open` false the page is already on checkout and stays there (the buy button's hop is not replaced).
 */
export async function guestDetails(page: Page, search: string, stubbed = true, register = false, open = true): Promise<string> {
  if (open) await page.goto("/index.php?route=checkout/checkout");
  await page.check(register ? "#input-register" : "#input-guest");
  if (register) {
    await page.fill("#input-password", "E2e-pass-12345");
    const agree = page.locator("#input-register-agree");
    if (await agree.isVisible()) await agree.check();
  }
  await page.fill("#input-firstname", "Ada");
  await page.fill("#input-lastname", "Buyer");
  await page.fill("#input-email", `e2e-${Date.now()}@two.inc`);
  await page.selectOption("#input-shipping-country", { label: "United Kingdom" });
  await expect(page.locator("#input-shipping-zone option").nth(1)).toBeAttached();
  await page.selectOption("#input-shipping-zone", { index: 1 });
  // The company is searched in the country chosen, so it comes after the country.
  // The field opens the shared popover; the buyer types into its own query input.
  const field = page.locator("#input-shipping-company");
  await field.click();
  const panel = page.locator(".two-company-field-wrap:has(#input-shipping-company) .two-company-dropdown");
  await expect(panel).toBeVisible();
  await expect(panel.locator(".two-company-mode-chip:not(.two-hidden)").first()).toHaveText("Registered company");
  await panel.locator(".two-company-dropdown__query").pressSequentially(search, { delay: 40 });
  const result = panel.locator(".two-company-dropdown__row").first();
  await expect(result).toBeVisible();
  await result.click();
  await expect(panel).toBeHidden();
  const company = (await field.inputValue()).trim();
  expect(company, "the pick fills the company field").not.toBe("");
  if (stubbed) {
    await expect(page.locator(".two-company-id-text"), "the number shows under the field, as text").toHaveText("12345678");
    await expect(page.locator("#input-shipping-address-1"), "the registered address is filled in").toHaveValue("1 Registry Road");
  }
  await page.fill("#input-shipping-address-1", "1 Test Street");
  await page.fill("#input-shipping-city", "London");
  await page.fill("#input-shipping-postcode", "EC1A 1BB");
  await page.click("#button-register");
  await expect(page.locator("#alert")).toContainText("Success");
  return company;
}

/** Chooses flat-rate shipping and Two, then waits for the tile's intent notice. */
export async function chooseTwo(page: Page, company: string): Promise<void> {
  await page.click("#button-shipping-methods");
  await page.locator(".modal.show #button-shipping-method").click();
  await expect(page.locator(".modal.show")).toHaveCount(0);
  await page.click("#button-payment-methods");
  await page.locator('.modal.show input[value="two.two"]').check();
  await page.locator(".modal.show #button-payment-method").click();
  await expect(page.locator("#checkout-confirm")).toContainText(`Buying for ${company}`);
}

/** Confirms, passes Two's hosted checkout and lands on the success page. Returns the shop's order id. */
export async function confirmAndReturn(page: Page): Promise<number> {
  await page.click("#button-two-confirm");
  await page.waitForURL(/route=checkout\/success/, { timeout: 60_000 });
  await expect(page.locator("#content h1")).toContainText("Your order has been placed");
  return latestOrderId();
}

/** Applies a coupon code on the cart page. */
export async function applyCoupon(page: Page, code: string): Promise<void> {
  await page.goto("/index.php?route=checkout/cart");
  await page.click('button[data-bs-target="#collapse-coupon"]');
  await page.fill("#input-coupon", code);
  await page.locator("#collapse-coupon button.btn-primary").click();
  await expect(page.locator("#alert")).toContainText("Success");
}

export async function placeOrder(page: Page, quantity = 1, search = "E2E Trad", coupon = ""): Promise<number> {
  await addToCart(page, quantity);
  if (coupon) await applyCoupon(page, coupon);
  const company = await guestDetails(page, search);
  await chooseTwo(page, company);
  return confirmAndReturn(page);
}

/**
 * Places an order and leaves at Two's hosted checkout: the buyer never comes
 * back to the shop. Returns the shop's order id and Two's payment URL.
 */
export async function placeWithoutReturn(page: Page, quantity = 1): Promise<{ orderId: number; paymentUrl: string }> {
  await addToCart(page, quantity);
  const company = await guestDetails(page, "E2E Trad");
  await chooseTwo(page, company);
  const payment = new RegExp(`^https://localhost:${STUB_PORT}/checkout/`);
  // Resolved once the navigation is aborted: unrouting on the request event alone can let it through.
  let left: (url: string) => void = () => {};
  const leaving = new Promise<string>((resolve) => (left = resolve));
  await page.route(payment, async (route) => {
    await route.abort();
    left(route.request().url());
  });
  await page.click("#button-two-confirm");
  const paymentUrl = await leaving;
  await page.unroute(payment);
  return { orderId: latestOrderId(), paymentUrl };
}
