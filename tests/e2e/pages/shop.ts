import { execFileSync } from "node:child_process";
import path from "node:path";

import { STUB_PORT } from "../config";

// Reads the shop's database through the E2E compose project, so a spec can
// find the order it just placed and the Two order it is linked to.
const COMPOSE = path.resolve(__dirname, "../compose.yml");

export function sql(query: string): string[][] {
  const out = execFileSync(
    "docker",
    ["compose", "-f", COMPOSE, "exec", "-T", "db", "mariadb", "-uroot", "-padmin", "opencart", "-N", "-B", "-e", query],
    { encoding: "utf8", env: { ...process.env, TWO_API_STUB_PORT: STUB_PORT } }
  );
  return out.trim() === "" ? [] : out.trim().split("\n").map((row) => row.split("\t"));
}

export function latestOrderId(): number {
  return Number(sql("SELECT MAX(order_id) FROM oc_order")[0][0]);
}

export function orderStatusId(orderId: number): number {
  return Number(sql(`SELECT order_status_id FROM oc_order WHERE order_id = ${orderId}`)[0][0]);
}

/** The shop's order status of that English name (the demo data's language 1). */
export function statusIdNamed(name: string): number {
  return Number(sql(`SELECT order_status_id FROM oc_order_status WHERE name = '${name}' AND language_id = 1`)[0][0]);
}

/** The shop's default order status (Settings > Option > Order Status). */
export function shopDefaultStatusId(): number {
  return Number(sql("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'config_order_status_id'")[0][0]);
}

/** One of the extension's stored settings (store 0), unprefixed, or null when none is stored. */
export function twoSetting(key: string): string | null {
  const rows = sql(`SELECT value FROM oc_setting WHERE store_id = 0 AND \`key\` = 'payment_two_${key}'`);
  return rows.length ? rows[0][0] : null;
}

/**
 * Calls the Two API from inside the shop container, as the shop does: so a
 * spec reaches the stub's API, which listens only on the compose network.
 */
export function callTwo(method: string, path: string, body: unknown, apiKey: string): { status: number; json: any } {
  const out = execFileSync(
    "docker",
    ["compose", "-f", COMPOSE, "exec", "-T", "opencart", "curl", "-sS", "-X", method, "-H", `X-API-Key: ${apiKey}`,
      "-H", "Content-Type: application/json", "-H", `Idempotency-Key: e2e-${Date.now()}-${Math.random()}`,
      ...(body === null ? [] : ["--data-binary", "@-"]), "-w", "\n%{http_code}", `https://api.sandbox.two.inc${path}`],
    { encoding: "utf8", input: body === null ? "" : JSON.stringify(body), env: { ...process.env, TWO_API_STUB_PORT: STUB_PORT } }
  );
  const at = out.lastIndexOf("\n");
  return { status: Number(out.slice(at + 1)), json: JSON.parse(out.slice(0, at) || "null") };
}

/**
 * The order's refunds as the plugin settled them: the state and the key of
 * the note it wrote, so a spec checks the outcome in any admin language.
 */
export function refundRows(orderId: number): { state: string; note: string }[] {
  return sql(`SELECT state, JSON_VALUE(reason, '$.key') FROM oc_two_refund WHERE order_id = ${orderId} ORDER BY refund_id`)
    .map(([state, note]) => ({ state, note }));
}

export function twoOrderId(orderId: number): string {
  return sql(`SELECT two_order_id FROM oc_two_order WHERE order_id = ${orderId}`)[0]?.[0] ?? "";
}

/** Unplaced: status 0, or the void status OpenCart 4.1.0.0 sets on every checkout edit. */
export function isUnplaced(orderId: number): boolean {
  const voided = Number(sql("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'config_void_status_id'")[0]?.[0] ?? "0");
  return [0, voided].includes(orderStatusId(orderId));
}

/** Makes the order look 31 minutes old, so the pending check selects it. */
export function backdate(orderId: number): void {
  sql(`UPDATE oc_order SET date_added = NOW() - INTERVAL 31 MINUTE, date_modified = NOW() - INTERVAL 31 MINUTE WHERE order_id = ${orderId}`);
}

/** The order's history comments, oldest first. */
export function historyComments(orderId: number): string[] {
  return sql(`SELECT comment FROM oc_order_history WHERE order_id = ${orderId} ORDER BY order_history_id`).map(([comment]) => comment);
}

/** How many times the order was put at a status: history lines with no comment (the extension's notes carry one). */
export function placings(orderId: number, statusId: number): number {
  return Number(sql(`SELECT COUNT(*) FROM oc_order_history WHERE order_id = ${orderId} AND order_status_id = ${statusId} AND comment = ''`)[0][0]);
}
