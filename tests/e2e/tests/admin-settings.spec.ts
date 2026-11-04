import { expect, test } from "@playwright/test";

import { openSettings } from "../pages/admin";
import { statusIdNamed } from "../pages/shop";
import { requests } from "../pages/stub";

// The settings page (TWO-27051): the fee Two charges the merchant beside each
// payment term, asked from Two once the page is up, and the Order management
// tab's confirmed status.

test("the Payment terms tab shows the merchant fee Two quotes for each term", async ({ page }) => {
  const seen = (await requests("/pricing/v1/merchant/rates")).length;
  await openSettings(page);
  await page.click('a[href="#tab-payment-terms"]');
  // The stub charges 1% plus 0.10 GBP per 30 days.
  await expect(page.locator('[data-two-term-fee="30"]')).toHaveText("(1.00% + 0.10 GBP)");
  await expect(page.locator('[data-two-term-fee="60"]')).toHaveText("(2.00% + 0.20 GBP)");
  await expect(page.locator('[data-two-term-fee="90"]')).toHaveText("(3.00% + 0.30 GBP)");
  await expect(page.locator("[data-two-fee-notice]"), "a fresh set needs no notice").toHaveText("");

  const asked = (await requests("/pricing/v1/merchant/rates")).slice(seen);
  expect(asked.length, "one ask per page view").toBe(1);
  expect(asked[0].body.net_terms, "every term the record offers is asked").toEqual([30, 60, 90]);
  expect(asked[0].key, "a write carries an Idempotency-Key").toMatch(/^merchant_rates_/);
});

test("the Order management tab shows the confirmed status, Processing on a fresh install", async ({ page }) => {
  await openSettings(page);
  await page.click('a[href="#tab-order-management"]');
  await expect(page.locator("#input-confirmed-status-id")).toHaveValue(String(statusIdNamed("Processing")));
  await expect(page.locator("#input-enable-tax-subtotals"), "Validate tax subtotals is on by default").toBeChecked();
});
