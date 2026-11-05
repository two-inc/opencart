import { expect, test } from "@playwright/test";

import { PRODUCT_ID } from "../config";
import { sql } from "../pages/shop";
import { confirmAndReturn, guestDetails } from "../pages/storefront";

// The product page message and the "Buy with Two" button (Build Guide
// section 20, TWO-25799 and TWO-25800). The button adds the item through
// OpenCart's own add to cart and lands on checkout, where the payment method
// list opens with Two already checked; the buyer still confirms as usual.

function setSetting(key: string, value: string): void {
  const full = `payment_two_${key}`;
  sql(`DELETE FROM oc_setting WHERE store_id = 0 AND code = 'payment_two' AND \`key\` = '${full}'; INSERT INTO oc_setting SET store_id = 0, code = 'payment_two', \`key\` = '${full}', value = '${value}', serialized = 0`);
}

const PRODUCT = `/index.php?route=product/product&product_id=${PRODUCT_ID}`;

test.afterEach(() => {
  setSetting("product_message_enabled", "0");
  setSetting("product_button_enabled", "0");
});

test("both switched off, the product page carries neither", async ({ page }) => {
  await page.goto(PRODUCT);
  await expect(page.locator("#button-cart")).toBeVisible();
  await expect(page.locator(".two-product")).toHaveCount(0);
});

test("the message names the brand and uses the checkout tile's sentence", async ({ page }) => {
  setSetting("product_message_enabled", "1");
  await page.goto(PRODUCT);
  const promo = page.locator("#form-product .two-product-promo");
  await expect(promo).toContainText("Two");
  await expect(promo).toContainText("Buy now, receive your goods, pay your invoice later.");
  await expect(page.locator("[data-two-buy-now]"), "the button has its own switch").toHaveCount(0);
});

test("Buy with Two adds the item and opens checkout with Two preselected", async ({ page }) => {
  setSetting("product_button_enabled", "1");
  await page.goto(PRODUCT);
  await expect(page.locator(".two-product-promo"), "the message has its own switch").toHaveCount(0);
  await page.fill("#input-quantity", "2");
  const button = page.getByRole("button", { name: "Buy with Two" });
  await button.click();
  await page.waitForURL(/route=checkout\/checkout/);

  const company = await guestDetails(page, "E2E Trad");
  await page.click("#button-shipping-methods");
  await page.locator(".modal.show #button-shipping-method").click();
  await expect(page.locator(".modal.show")).toHaveCount(0);
  await page.click("#button-payment-methods");
  await expect(page.locator('.modal.show input[name="payment_method"][value="two.two"]'), "Two is preselected").toBeChecked();
  await page.locator(".modal.show #button-payment-method").click();
  await expect(page.locator("#checkout-confirm")).toContainText(`Buying for ${company}`);

  const orderId = await confirmAndReturn(page);
  expect(sql(`SELECT quantity FROM oc_order_product WHERE order_id = ${orderId} AND product_id = ${PRODUCT_ID}`), "the quantity the buyer chose").toEqual([["2"]]);
});
