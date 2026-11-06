import { Page, expect } from "@playwright/test";

import { ADMIN_PASSWORD, ADMIN_USER } from "../config";

// The OpenCart admin's order page: the history form, the order editor and the
// extension's Two tab.

/** Logs in to the admin and answers the session's user_token. */
async function login(page: Page): Promise<string> {
  await page.goto("/admin/index.php?route=common/login");
  await page.fill("#input-username", ADMIN_USER);
  await page.fill("#input-password", ADMIN_PASSWORD);
  await page.click("button[type=submit]");
  await page.waitForURL(/user_token=/);
  return new URL(page.url()).searchParams.get("user_token") ?? "";
}

export async function openOrder(page: Page, orderId: number): Promise<void> {
  const token = await login(page);
  await page.goto(`/admin/index.php?route=sale/order.info&order_id=${orderId}&user_token=${token}`);
  await expect(page.locator("#input-order-status")).toBeVisible();
}

/** The extension's settings page (Extensions > Payments > Two). */
export async function openSettings(page: Page): Promise<void> {
  const token = await login(page);
  await page.goto(`/admin/index.php?route=extension/two/payment/two&user_token=${token}`);
  await expect(page.locator("#form-payment")).toBeVisible();
}

/** Adds an order history line at a status, as a merchant moving the order on does. */
export async function addHistory(page: Page, status: string): Promise<void> {
  await page.locator('a[href="#tab-history"]').click();
  await page.selectOption("#input-order-status", { label: status });
  await page.click("#button-history");
  await expect(page.locator("#alert .alert-success")).toBeVisible();
  await page.locator("#alert").evaluate((alert) => (alert.innerHTML = ""));
}

/** Adds one more of the order's only product in the order editor and saves the order. */
export async function addOneMore(page: Page, search: string, quantity: number): Promise<void> {
  await page.locator('button[data-bs-target="#modal-product"]').click();
  await page.fill("#input-product", search);
  await page.locator("#autocomplete-product a", { hasText: search }).first().click();
  await page.click("#button-product-add");
  await expect(page.locator("#form-cart tbody tr").first().locator("td").nth(1)).toHaveText(String(quantity));
  await page.locator(".modal.show .modal-header .btn-close").click();
  await expect(page.locator(".modal.show")).toHaveCount(0);
  await page.click("#button-confirm");
  await expect(page.locator("#alert .alert-success")).toBeVisible();
  await page.locator("#alert").evaluate((alert) => (alert.innerHTML = ""));
}

/** Opens the Two tab as it stands now: the page is reloaded, since the tab is rendered with it. */
export async function openTwoTab(page: Page): Promise<void> {
  await page.reload();
  await page.locator('a[href="#tab-two"]').click();
  await expect(page.locator("#tab-two")).toBeVisible();
}

/** Refunds a quantity of the Two tab's first line and returns the outcome shown. */
export async function refundLine(page: Page, quantity: number): Promise<string> {
  await openTwoTab(page);
  const form = page.locator("#form-two-refund");
  await form.locator('input[name^="two_refund_line"]').first().fill(String(quantity));
  await form.locator("button[type=submit]").click();
  return outcome(page);
}

export async function retryRefund(page: Page): Promise<string> {
  await openTwoTab(page);
  await page.locator("#tab-two form.two-action:has(input[name=two_refund_id]) button").click();
  return outcome(page);
}

async function outcome(page: Page): Promise<string> {
  const alert = page.locator("#alert .alert").first();
  await expect(alert).toBeVisible();
  const text = await alert.innerText();
  await page.locator("#alert").evaluate((el) => (el.innerHTML = ""));
  return text;
}

/** Presses Check status with Two on the Two tab of an unplaced order and returns the outcome shown. */
export async function checkStatusWithTwo(page: Page): Promise<string> {
  await openTwoTab(page);
  await page.locator("#form-two-check button[type=submit]").click();
  return outcome(page);
}
