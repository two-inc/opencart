<?php

declare(strict_types=1);

/**
 * Sets the E2E shop up for the specs, after dev/configure.php: run inside
 * the shop container by tests/e2e/bin/prepare.sh. Idempotent.
 *
 * - Drops the demo data's fixed-amount tax (Eco Tax) from its tax classes.
 *   A Two line carries one percentage rate, so the extension refuses an
 *   order with a fixed-amount rate; the specs check out with UK VAT alone.
 * - Makes pounds the store's currency (the demo data's is US dollars), so a
 *   UK buyer's order is in GBP, as in the sibling plugins' E2E stores: Two's
 *   sandbox approves a GB company's order in its own currency. OpenCart holds
 *   the default currency at 1 and every other relative to it.
 * - Turns on the demo data's 10% coupon (2222), which has expired, with no
 *   use limit, so a spec can send an order with a discount line.
 */

// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = rtrim((string) getenv('OPENCART_DIR'), '/');
if ($dir === '' || !is_file($dir . '/config.php')) {
    fwrite(STDERR, "[seed] OPENCART_DIR does not hold an installed OpenCart\n");
    exit(1);
}
require $dir . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
$prefix = DB_PREFIX;

$db->query("DELETE tr FROM `{$prefix}tax_rule` tr JOIN `{$prefix}tax_rate` r ON r.tax_rate_id = tr.tax_rate_id WHERE r.type = 'F'");
echo '[seed] removed ' . $db->affected_rows . " fixed-amount tax rule(s)\n";

$current = $db->query("SELECT value FROM `{$prefix}setting` WHERE store_id = 0 AND `key` = 'config_currency'")->fetch_row()[0] ?? '';
if ($current !== 'GBP') {
    $rate = (float) ($db->query("SELECT value FROM `{$prefix}currency` WHERE code = 'GBP'")->fetch_row()[0] ?? 0);
    if ($rate <= 0) {
        fwrite(STDERR, "[seed] the shop has no GBP currency to make its default\n");
        exit(1);
    }
    $db->query("UPDATE `{$prefix}currency` SET value = value / {$rate}");
    $db->query("UPDATE `{$prefix}currency` SET value = 1, status = 1 WHERE code = 'GBP'");
    $db->query("UPDATE `{$prefix}setting` SET value = 'GBP' WHERE store_id = 0 AND `key` = 'config_currency'");
    foreach (glob(DIR_STORAGE . 'cache/cache.currency*') ?: [] as $cached) {
        unlink($cached);
    }
}
echo "[seed] the store's currency is GBP\n";

$db->query("UPDATE `{$prefix}coupon` SET status = 1, date_start = '2020-01-01', date_end = '2099-12-31', uses_total = 0, uses_customer = '0' WHERE code = '2222'");
echo "[seed] coupon 2222 (10% off) is on\n";
