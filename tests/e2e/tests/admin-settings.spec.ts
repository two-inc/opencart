import { expect, test } from "@playwright/test";

import { openSettings } from "../pages/admin";
import { statusIdNamed } from "../pages/shop";
import { requests } from "../pages/stub";
import { STUB_API_KEY } from "../config";

// The settings page (TWO-27051): the fee Two charges the merchant beside each
// payment term, asked from Two once the page is up, and the Order management
// tab's confirmed status, and the Diagnostics tab.

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

test("the Diagnostics tab: SSL verification on by default, a tokenised refresh URL, and an invalid header row saves nothing on the tab", async ({ page }) => {
  await openSettings(page);
  await page.click('a[href="#tab-diagnostics"]');
  await expect(page.locator("#input-disable-ssl-verify"), "the SSL bypass is never on by default").not.toBeChecked();

  const cron = await page.locator("#input-cron-url").inputValue();
  expect(cron, "the refresh URL carries a random token").toMatch(/index\.php\?route=extension\/two\/cron\/two&token=[0-9a-f]{40}$/);
  expect(cron, "never the API key").not.toContain(STUB_API_KEY);
  expect((await page.request.get(cron.replace(/token=.*$/, "token=wrong"))).status(), "a wrong token is refused").toBe(403);

  await page.click("[data-two-header-add]");
  const row = page.locator("[data-two-header-row]").last();
  await row.locator('input[name$="[name]"]').fill("Host");
  await row.locator('input[name$="[value]"]').fill("shop.example");
  await page.locator("#input-debug-mode").check();
  await page.click('button[form="form-payment"]');
  await expect(page.locator("#error-custom-headers")).toContainText('"Host" is reserved');

  await page.reload();
  await page.click('a[href="#tab-diagnostics"]');
  await expect(page.locator("#input-debug-mode"), "nothing on the tab was saved").not.toBeChecked();
  await expect(page.locator("[data-two-header-row]"), "the refused row was not stored").toHaveCount(0);

  const log = await page.request.get((await page.locator("#two-error-log-link").getAttribute("href")) ?? "");
  expect([log.status(), (await log.text()).includes('id="two-error-log')], "the error log view opens behind the admin login").toEqual([200, true]);
});
