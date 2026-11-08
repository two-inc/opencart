import { expect, test } from "@playwright/test";

import { PRODUCT_ID } from "../config";
import { addOneMore, openOrder } from "../pages/admin";
import { sql, twoOrderId } from "../pages/shop";
import { addToCart, chooseTwo, confirmAndReturn, guestDetails } from "../pages/storefront";
import { requests } from "../pages/stub";

// TWO-27058, Build Guide section 22: a 0% line carries the code the merchant
// mapped for the row the shop's own tax setup puts it on, and nothing else
// does. The shop gets a zero-rated tax class (one 0% rate, based on the store
// address, so it applies whichever addresses the guest gives), the test
// product moves onto it, and the merchant's mapping codes that rate's row.
// The order built from the shop's rows is read back from the POST /v1/order
// body the stub captured.

const CODE = "ES_IVA_EXPORT";
let classId = 0;
let rateId = 0;
let productClass = "";

function setMap(value: string | null): void {
  const key = "payment_two_tax_code_map";
  sql(`DELETE FROM oc_setting WHERE store_id = 0 AND code = 'payment_two' AND \`key\` = '${key}'`);
  if (value !== null) {
    sql(`INSERT INTO oc_setting SET store_id = 0, code = 'payment_two', \`key\` = '${key}', value = '${value}', serialized = 0`);
  }
}

test.beforeAll(() => {
  productClass = sql(`SELECT tax_class_id FROM oc_product WHERE product_id = ${PRODUCT_ID}`)[0][0];
  const zone = sql("SELECT geo_zone_id FROM oc_zone_to_geo_zone WHERE country_id = (SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'config_country_id') ORDER BY geo_zone_id LIMIT 1")[0][0];
  sql(`INSERT INTO oc_tax_rate SET geo_zone_id = ${zone}, name = 'E2E zero rate', rate = 0, type = 'P'`);
  rateId = Number(sql("SELECT MAX(tax_rate_id) FROM oc_tax_rate WHERE name = 'E2E zero rate'")[0][0]);
  sql(`INSERT INTO oc_tax_rate_to_customer_group SELECT ${rateId}, customer_group_id FROM oc_customer_group`);
  sql("INSERT INTO oc_tax_class SET title = 'E2E zero rated', description = 'E2E zero rated'");
  classId = Number(sql("SELECT MAX(tax_class_id) FROM oc_tax_class WHERE title = 'E2E zero rated'")[0][0]);
  sql(`INSERT INTO oc_tax_rule SET tax_class_id = ${classId}, tax_rate_id = ${rateId}, based = 'store', priority = 1`);
  sql(`UPDATE oc_product SET tax_class_id = ${classId} WHERE product_id = ${PRODUCT_ID}`);
});

test.afterAll(() => {
  setMap(null);
  sql(`UPDATE oc_product SET tax_class_id = ${productClass} WHERE product_id = ${PRODUCT_ID}`);
  sql(`DELETE FROM oc_tax_rule WHERE tax_class_id = ${classId}`);
  sql(`DELETE FROM oc_tax_class WHERE tax_class_id = ${classId}`);
  sql(`DELETE FROM oc_tax_rate_to_customer_group WHERE tax_rate_id = ${rateId}`);
  sql(`DELETE FROM oc_tax_rate WHERE tax_rate_id = ${rateId}`);
});

type Line = { name: string; tax_rate: string; tax_code?: string };

async function placeAndRead(page: import("@playwright/test").Page): Promise<Line[]> {
  const seen = (await requests("")).length;
  await addToCart(page, 1);
  await chooseTwo(page, await guestDetails(page, "E2E Tax"));
  await confirmAndReturn(page);
  const created = (await requests("")).slice(seen).filter((r) => r.method === "POST" && r.path === "/v1/order");
  expect(created, "exactly one order was created").toHaveLength(1);
  return (created[0].body as { line_items: Line[] }).line_items;
}

const cases: { mapped: boolean; expected: string | undefined; description: string }[] = [
  { mapped: false, expected: undefined, description: "control: no mapping, so the 0% line carries no code" },
  { mapped: true, expected: CODE, description: "the merchant's row for the 0% rate the shop applied" }
];

for (const { mapped, expected, description } of cases) {
  test(`a 0% line carries only the merchant's code: ${description}`, async ({ page }) => {
    setMap(mapped ? JSON.stringify({ [`${classId}|rate:${rateId}`]: CODE }) : null);
    const name = sql(`SELECT name FROM oc_product_description WHERE product_id = ${PRODUCT_ID} AND language_id = 1`)[0][0];
    const lines = await placeAndRead(page);
    const product = lines.find((l) => l.name === name);
    expect(product?.tax_rate, `${description}: the product line went at 0%`).toBe("0");
    expect(product?.tax_code, `${description}: the product line's tax_code`).toBe(expected);
    expect(lines.filter((l) => Number(l.tax_rate) !== 0).every((l) => l.tax_code === undefined), `${description}: a taxed line never carries a code`).toBe(true);
  });
}

// TWO-27110: placement records the code each 0% line was sent with, and an
// edit sends the recorded code, so a mapping changed after placement never
// moves a placed order.
test("an edit after the mapping changed sends the code the order was placed with", async ({ page }) => {
  const row = `${classId}|rate:${rateId}`;
  setMap(JSON.stringify({ [row]: CODE }));
  const name = sql(`SELECT name FROM oc_product_description WHERE product_id = ${PRODUCT_ID} AND language_id = 1`)[0][0];
  await addToCart(page, 1);
  await chooseTwo(page, await guestDetails(page, "E2E Tax"));
  const orderId = await confirmAndReturn(page);
  const twoId = twoOrderId(orderId);

  setMap(JSON.stringify({ [row]: "ES_IVA_CHANGED" }));
  await openOrder(page, orderId);
  await addOneMore(page, name, 2);
  await expect.poll(async () => (await requests(`/v1/order/${twoId}`)).filter((r) => r.method === "PUT").length, { message: "the edit is sent to Two" }).toBe(1);
  const put = (await requests(`/v1/order/${twoId}`)).find((r) => r.method === "PUT")!;
  const product = (put.body as { line_items: Line[] }).line_items.find((l) => l.name === name);
  expect(product?.tax_rate, "the edited product line is still 0%").toBe("0");
  expect(product?.tax_code, "the edit sends the code recorded at placement, not the changed mapping's").toBe(CODE);
});
