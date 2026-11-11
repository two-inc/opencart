import { APIRequestContext, expect, request } from "@playwright/test";

import { STUB_URL } from "../config";

// The stub's control routes (tests/e2e/stub/two-api-stub.mjs).
async function api(): Promise<APIRequestContext> {
  return request.newContext({ baseURL: STUB_URL, ignoreHTTPSErrors: true });
}

export type StubOrder = {
  id: string;
  state: string;
  currency: string;
  gross_amount: string;
  line_items: { id: string; name: string; quantity: number; net_amount: string; tax_amount: string; gross_amount: string }[];
  refunds: { id: string; amount: string }[];
};

export async function stubOrder(id: string): Promise<StubOrder> {
  const res = await (await api()).get(`/__stub/orders/${id}`);
  expect(res.ok(), `the stub holds Two order ${id}`).toBeTruthy();
  return res.json();
}

/** Sets the Two order's state as Two would on its own (the buyer verifying, Two cancelling). */
export async function setState(id: string, state: string): Promise<void> {
  expect((await (await api()).post(`/__stub/orders/${id}`, { data: { state } })).ok(), `the stub sets Two order ${id} to ${state}`).toBeTruthy();
}

/**
 * The buyer passes Two's hosted checkout (the order turns VERIFIED) but never
 * follows its redirect back to the shop. Returns the confirmation URL Two
 * would have sent the buyer to.
 */
export async function verifyAtTwo(paymentUrl: string): Promise<string> {
  await setCheckoutOutcome("verify");
  const res = await (await api()).get(paymentUrl, { maxRedirects: 0 });
  expect(res.status(), "Two's hosted checkout redirects back to the shop").toBe(302);
  return res.headers()["location"] ?? "";
}

/** What the hosted checkout does with the next buyer: verify, or leave (cancel). */
export async function setCheckoutOutcome(outcome: "verify" | "cancel"): Promise<void> {
  expect((await (await api()).post("/__stub/checkout", { data: { outcome } })).ok()).toBeTruthy();
}

export async function expectState(id: string, state: string): Promise<void> {
  await expect.poll(async () => (await stubOrder(id)).state, { message: `Two order ${id} reaches ${state}` }).toBe(state);
}

export type StubRequest = {
  seq: number;
  method: string;
  path: string;
  key: string | null;
  body: any;
  /** The HTTP status the stub answered with, or "dropped" when it closed the connection instead. */
  status: number | "dropped";
  /** Set when the answer was the one kept for the request's Idempotency-Key. */
  replayed?: boolean;
};

/** The shop's requests to the stub's API, in order, whose path starts with the prefix ("" for all). */
export async function requests(prefix: string): Promise<StubRequest[]> {
  return (await (await api()).get(`/__stub/requests?path=${encodeURIComponent(prefix)}`)).json();
}

type Fault = { method: string; path: string; status?: number };

/** Makes the next matching request to the stub fail with the status, without applying it. */
export async function failNext(method: string, path: string, status = 503): Promise<void> {
  expect((await (await api()).post("/__stub/fault", { data: { method, path, status } })).ok()).toBeTruthy();
}

/**
 * Makes the stub apply the next matching request, then close the connection
 * with no answer at all: Two applied it, and the answer was lost. `then` arms
 * failNext faults once it has fired.
 */
export async function dropNext(method: string, path: string, then: Fault[] = []): Promise<void> {
  expect((await (await api()).post("/__stub/fault", { data: { method, path, mode: "drop", then } })).ok()).toBeTruthy();
}

export const cents = (amount: string | number): number => Math.round(Math.abs(Number(amount)) * 100);
export const refundedCents = (order: StubOrder): number => order.refunds.reduce((sum, r) => sum + cents(r.amount), 0);
