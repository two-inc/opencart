<?php

declare(strict_types=1);

/**
 * Smoke probe: the rate OpenCart's own tax engine gives a tax class for a
 * stored order, set up the way the plugin sets it up (OrderTax), with no
 * session at all, as on a confirm callback that arrives without the cookie.
 *
 *   php dev/ci/order-tax-probe.php '<order row as JSON>' <tax class id>
 *
 * Prints {"rate": <fraction or null>, "fixed_tax": <bool>}.
 */

use Opencart\System\Library\Extension\Two\Opencart\OrderTax;

// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$shop = rtrim(getenv('OPENCART_DIR') ?: '/var/www/html', '/');
require $shop . '/config.php';
require $shop . '/system/startup.php';
$autoloader = new \Opencart\System\Engine\Autoloader();
$autoloader->register('Opencart\System', DIR_SYSTEM);
require dirname(__DIR__) . '/autoload.php';

$order = json_decode($argv[1] ?? '', true);
if (!is_array($order)) {
    fwrite(STDERR, "order-tax-probe: the first argument must be an order row as JSON\n");
    exit(1);
}
$db = new \Opencart\System\Library\DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (string) DB_PORT);
$scope = OrderTax::scope($order, OrderTax::storeSettings($db, (int) ($order['store_id'] ?? 0)));
echo json_encode(OrderTax::rateOf(OrderTax::engine($db, $scope), 100.0, (int) ($argv[2] ?? 0))), "\n";
