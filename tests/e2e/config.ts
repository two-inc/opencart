// One place for what the suite needs from its environment. The Makefile and
// the workflow set these; the defaults match `make e2e-up`.
export const STORE_URL = process.env.STORE_URL ?? `http://localhost:${process.env.E2E_PORT ?? "1240"}`;
export const STUB_PORT = process.env.TWO_API_STUB_PORT ?? "8099";
export const STUB_URL = `https://localhost:${STUB_PORT}`;
// The key the stub accepts and the shop is given: TWO_API_STUB_KEY in
// compose.yml, and bin/prepare.sh.
export const STUB_API_KEY = "e2e-stub-key";
export const ADMIN_USER = "admin";
export const ADMIN_PASSWORD = "examplepassword123";
// Set only on the sandbox leg: the suite then runs tests/sandbox.spec.ts alone.
export const SANDBOX_API_KEY = process.env.E2E_SANDBOX_API_KEY ?? "";
export const SANDBOX_API_URL = "https://api.sandbox.two.inc";
// The demo catalogue's iPhone: taxed at UK VAT once tests/e2e/bin/seed.php has
// dropped the fixed-amount Eco Tax.
export const PRODUCT_ID = 40;
