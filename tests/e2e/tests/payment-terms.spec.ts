import { expect, test } from "@playwright/test";

import { sql, twoOrderId } from "../pages/shop";
import { addToCart, chooseTwo, confirmAndReturn, guestDetails } from "../pages/storefront";
import { expectState, requests } from "../pages/stub";

// The buyer's chosen payment term must be the term on the order Two is sent.
// The merchant record the stub serves offers 30, 60 and 90 days, due in 30:
// the default is 30, so only a non-default case can tell a term that reached
// the order from one the merchant default filled in. Each case drives the
// whole chain: the chip the buyer clicks, the term saved in the session, the
// order built from the shop's rows, and the POST /v1/order body the stub
// captured, with nothing in between stubbed.

type Terms = { type: string; duration_days: number; duration_days_calculated_from?: string };

const NET = (days: number): Terms => ({ type: "NET_TERMS", duration_days: days });
const EOM = (days: number): Terms => ({ ...NET(days), duration_days_calculated_from: "END_OF_MONTH" });

function setSetting(key: string, value: string): void {
  const full = `payment_two_${key}`;
  sql(`DELETE FROM oc_setting WHERE store_id = 0 AND code = 'payment_two' AND \`key\` = '${full}'; INSERT INTO oc_setting SET store_id = 0, code = 'payment_two', \`key\` = '${full}', value = '${value}', serialized = 0`);
}

/** Puts the shop back on the standard terms, all three ticked, as a fresh install has them. */
test.afterEach(() => {
  setSetting("payment_term_type", "STANDARD");
  setSetting("payment_terms", "[30,60,90]");
});

/** Chooses the chip as a buyer does and waits for the term to be saved in the shop. */
async function chooseTerm(page: import("@playwright/test").Page, days: number): Promise<void> {
  const chip = page.locator(`[data-two-term="${days}"]`);
  await expect(chip, `the ${days}-day chip is offered`).toBeVisible();
  const saved = page.waitForResponse((r) => /two[.|]term/.test(r.url()) && r.request().method() === "POST");
  await chip.click();
  expect((await (await saved).json()).ok, `the shop accepts the ${days}-day term`).toBe(true);
  await expect(chip).toHaveAttribute("aria-checked", "true");
}

async function toPayment(page: import("@playwright/test").Page): Promise<void> {
  await addToCart(page, 1);
  await chooseTwo(page, await guestDetails(page, "E2E Trad"));
}

/** The body of the POST /v1/order the shop sent after the first `seen` requests. */
async function createBody(seen: number): Promise<{ terms?: Terms }[]> {
  return (await requests("")).slice(seen).filter((r) => r.method === "POST" && r.path === "/v1/order").map((r) => r.body);
}

const cases: { type: string; choose: number | null; expected: Terms; description: string }[] = [
  { type: "STANDARD", choose: null, expected: NET(30), description: "control: no term chosen, the merchant default of 30 days" },
  { type: "STANDARD", choose: 30, expected: NET(30), description: "the default term chosen explicitly" },
  { type: "STANDARD", choose: 60, expected: NET(60), description: "a non-default term (60 days)" },
  { type: "STANDARD", choose: 90, expected: NET(90), description: "a non-default term (90 days)" },
  { type: "EOM", choose: 60, expected: EOM(60), description: "an end-of-month term (60 days)" }
];

for (const { type, choose, expected, description } of cases) {
  test(`the order sent to Two carries the chosen term: ${description}`, async ({ page }) => {
    setSetting("payment_term_type", type);
    const seen = (await requests("")).length;
    await toPayment(page);
    if (choose !== null) await chooseTerm(page, choose);
    const orderId = await confirmAndReturn(page);

    const created = await createBody(seen);
    expect(created, `${description}: exactly one order was created`).toHaveLength(1);
    expect(created[0].terms, `${description}: terms in the POST /v1/order body`).toEqual(expected);
    await expectState(twoOrderId(orderId), "CONFIRMED");
  });
}

test("a chosen term the merchant has since withdrawn is refused, never replaced by the default", async ({ page }) => {
  const seen = (await requests("")).length;
  await toPayment(page);
  await chooseTerm(page, 90);
  // The merchant unticks 90 days after the buyer chose it.
  setSetting("payment_terms", "[30,60]");
  await page.click("#button-two-confirm");
  // The placement refusal for a term the record does not offer (error_placement_terms).
  await expect(page.locator("#alert .alert-danger"), "the buyer is told the order was not placed").toContainText("This payment method is not available.");
  expect(await createBody(seen), "withdrawn term: no order reached Two, not even one on the default term").toEqual([]);
});
