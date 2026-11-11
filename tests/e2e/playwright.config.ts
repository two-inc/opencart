import { defineConfig } from "@playwright/test";

import { SANDBOX_API_KEY, STORE_URL } from "./config";

// One shop, one stub, orders that build on each other's settings: run serially.
export default defineConfig({
  testDir: "./tests",
  testMatch: SANDBOX_API_KEY ? /sandbox\.spec\.ts$/ : /\.spec\.ts$/,
  testIgnore: SANDBOX_API_KEY ? [] : /sandbox\.spec\.ts$/,
  timeout: 120_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: process.env.CI ? [["list"], ["html", { open: "never" }]] : "list",
  use: {
    baseURL: STORE_URL,
    // The stub's hosted checkout is served with a throwaway CA the browser does not know.
    ignoreHTTPSErrors: true,
    actionTimeout: 15_000,
    trace: "retain-on-failure",
    video: "retain-on-failure"
  },
  projects: [{ name: "chromium", use: { browserName: "chromium" } }]
});
