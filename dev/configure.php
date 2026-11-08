<?php

declare(strict_types=1);

/**
 * Installs and enables the Two extension in an OpenCart 4 shop. Idempotent:
 * every step checks before it writes, so running it again changes nothing
 * unless the inputs changed.
 *
 * Callers:
 * - the staging shops, which deploy this repo to extension/two and then run
 *   `php <checkout>/dev/configure.php` after every new commit;
 * - `make install` and `make configure` in the local docker shop.
 *
 * It does what Extensions > Installer and Extensions > Payments > Install do,
 * straight against the database, because neither step has a CLI entry point:
 * the extension_install and extension rows, the admin permissions for the
 * extension's route, the payment_two settings (status forced on), the
 * record refresh cron, the order lifecycle event, the payment terms fee
 * order total (installed and enabled) and the two_order table.
 *
 * Environment:
 *   OPENCART_DIR     shop root holding config.php (default /var/www/html)
 *   TWO_ENVIRONMENT  production, sandbox or staging; stored when set, and
 *                    any other value fails the run
 *   TWO_API_KEY      merchant API key; PLUGIN_DEMO_STORE_API_KEY is the
 *                    fallback name the staging shops provide. Stored when set,
 *                    never printed.
 */

use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Opencart\DbOrderRecords;
use Opencart\System\Library\Extension\Two\Opencart\DbRateLimits;
use Opencart\System\Library\Extension\Two\Opencart\DbRefundRecords;
use Opencart\System\Library\Extension\Two\Opencart\Services;

// The staging shops deploy the whole repo under the web root, so refuse to
// run from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/autoload.php';

const TWO_CODE = 'two';
const TWO_ROUTE = 'extension/two/payment/two';
const TWO_FEE_ROUTE = 'extension/two/total/two_fee';
const TWO_SETTING_CODE = 'payment_two';

function say(string $message): void
{
    echo '[configure] ' . $message . "\n";
}

function fail(string $message): never
{
    fwrite(STDERR, '[configure] ' . $message . "\n");
    exit(1);
}

/**
 * One prepared statement, every parameter bound as a string.
 *
 * @param list<string> $params
 * @return list<array<string, mixed>> the rows of a SELECT, empty otherwise
 */
function query(mysqli $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    if ($params !== []) {
        $statement->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $statement->execute();
    $result = $statement->get_result();

    return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
}

$shop = rtrim(getenv('OPENCART_DIR') ?: '/var/www/html', '/');
if (!is_file($shop . '/config.php')) {
    fail('no OpenCart config.php in ' . $shop . ' (set OPENCART_DIR)');
}
require $shop . '/config.php';
if (!defined('DB_DATABASE') || !defined('DIR_EXTENSION')) {
    fail('OpenCart is not installed yet in ' . $shop);
}

$manifestFile = DIR_EXTENSION . TWO_CODE . '/install.json';
$manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
if (!is_array($manifest)) {
    fail('the extension is not deployed: no readable ' . $manifestFile);
}
/** @var array<string, string> $manifest */
$manifest += ['name' => 'Two', 'description' => '', 'version' => '', 'author' => '', 'link' => ''];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
$db->set_charset('utf8mb4');
$p = DB_PREFIX;

// 1. The installed-extension record (Extensions > Installer).
$install = query($db, "SELECT `extension_install_id` FROM `{$p}extension_install` WHERE `code` = ?", [TWO_CODE]);
$details = [$manifest['name'], $manifest['description'], $manifest['version'], $manifest['author'], $manifest['link']];
if ($install === []) {
    query($db, "INSERT INTO `{$p}extension_install` SET `extension_id` = 0, `extension_download_id` = 0, `name` = ?, `description` = ?, `version` = ?, `author` = ?, `link` = ?, `code` = ?, `status` = 1, `date_added` = NOW()", [...$details, TWO_CODE]);
    say('registered extension ' . TWO_CODE . ' ' . $manifest['version']);
} else {
    query($db, "UPDATE `{$p}extension_install` SET `name` = ?, `description` = ?, `version` = ?, `author` = ?, `link` = ?, `status` = 1 WHERE `code` = ?", [...$details, TWO_CODE]);
}

// 2. The payment method (Extensions > Payments > Install).
if (query($db, "SELECT `extension_id` FROM `{$p}extension` WHERE `type` = 'payment' AND `code` = ?", [TWO_CODE]) === []) {
    query($db, "INSERT INTO `{$p}extension` SET `extension` = ?, `type` = 'payment', `code` = ?", [TWO_CODE, TWO_CODE]);
    say('installed payment method ' . TWO_CODE);
}

// 3. Admin permissions, for every user group allowed to manage payment extensions.
foreach (query($db, "SELECT `user_group_id`, `permission` FROM `{$p}user_group`") as $group) {
    $permission = json_decode((string) $group['permission'], true);
    $permission = is_array($permission) ? $permission : [];
    if (!in_array('extension/payment', $permission['modify'] ?? [], true)) {
        continue;
    }
    $changed = false;
    foreach ([TWO_ROUTE, TWO_FEE_ROUTE] as $route) {
        foreach (['access', 'modify'] as $type) {
            if (!in_array($route, $permission[$type] ?? [], true)) {
                $permission[$type][] = $route;
                $changed = true;
            }
        }
    }
    if ($changed) {
        query($db, "UPDATE `{$p}user_group` SET `permission` = ? WHERE `user_group_id` = ?", [(string) json_encode($permission), (string) $group['user_group_id']]);
        say('granted ' . TWO_ROUTE . ' and ' . TWO_FEE_ROUTE . ' to user group ' . $group['user_group_id']);
    }
}

// 3a. The payment terms fee order total, installed and enabled as install() does; its sort order only where none is stored.
if (query($db, "SELECT `extension_id` FROM `{$p}extension` WHERE `type` = 'total' AND `code` = ?", [Services::FEE_TOTAL_CODE]) === []) {
    query($db, "INSERT INTO `{$p}extension` SET `extension` = ?, `type` = 'total', `code` = ?", [TWO_CODE, Services::FEE_TOTAL_CODE]);
    say('registered total ' . Services::FEE_TOTAL_CODE);
}
foreach (['status' => '1', 'sort_order' => Services::FEE_TOTAL_SORT_ORDER] as $key => $value) {
    $settingKey = 'total_' . Services::FEE_TOTAL_CODE . '_' . $key;
    if (query($db, "SELECT `value` FROM `{$p}setting` WHERE `store_id` = 0 AND `key` = ?", [$settingKey]) === []) {
        query($db, "INSERT INTO `{$p}setting` SET `store_id` = 0, `code` = ?, `key` = ?, `value` = ?, `serialized` = 0", ['total_' . Services::FEE_TOTAL_CODE, $settingKey, $value]);
        say('set ' . $settingKey . ' = ' . $value);
    }
}

// 4. Settings: defaults only where nothing is stored, then the given inputs.
// The status maps start on the shop's own statuses, found by name in the default language. An existing
// install (any setting of the extension already stored) gets no confirmed status: it keeps placing orders at
// its default order status until the merchant saves one, as a package upgrade does.
$statusDefaults = [];
$language = query($db, "SELECT `value` FROM `{$p}setting` WHERE `store_id` = 0 AND `key` = 'config_language_id'");
$existingInstall = query($db, "SELECT 1 FROM `{$p}setting` WHERE `store_id` = 0 AND `code` = ? LIMIT 1", [TWO_SETTING_CODE]) !== [];
foreach (SettingsProvider::statusNamesToSeed($existingInstall) as $key => $name) {
    $found = query($db, "SELECT `order_status_id` FROM `{$p}order_status` WHERE `name` = ? AND `language_id` = ? ORDER BY `order_status_id` LIMIT 1", [$name, (string) ($language[0]['value'] ?? '1')]);
    if ($found !== []) {
        $id = (int) $found[0]['order_status_id'];
        $statusDefaults[$key] = $key === 'fulfil_status_ids' ? (string) json_encode([$id]) : (string) $id;
    }
}
$defaults = ['sort_order' => '0'] + $statusDefaults + SettingsProvider::DEFAULTS;
$given = ['status' => '1'];
$environment = (string) getenv('TWO_ENVIRONMENT');
if ($environment !== '') {
    $known = Environment::ALL;
    if (!in_array($environment, $known, true)) {
        fail('unknown TWO_ENVIRONMENT "' . $environment . '" (expected one of: ' . implode(', ', $known) . ')');
    }
    $given['environment'] = $environment;
}
$apiKey = (string) (getenv('TWO_API_KEY') ?: getenv('PLUGIN_DEMO_STORE_API_KEY'));
if ($apiKey !== '') {
    $given['api_key'] = $apiKey;
}

foreach ($given + $defaults as $key => $value) {
    $settingKey = TWO_SETTING_CODE . '_' . $key;
    $stored = query($db, "SELECT `value` FROM `{$p}setting` WHERE `store_id` = 0 AND `code` = ? AND `key` = ?", [TWO_SETTING_CODE, $settingKey]);
    if ($stored === []) {
        query($db, "INSERT INTO `{$p}setting` SET `store_id` = 0, `code` = ?, `key` = ?, `value` = ?, `serialized` = 0", [TWO_SETTING_CODE, $settingKey, $value]);
    } elseif (isset($given[$key]) && $stored[0]['value'] !== $value) {
        query($db, "UPDATE `{$p}setting` SET `value` = ? WHERE `store_id` = 0 AND `code` = ? AND `key` = ?", [$value, TWO_SETTING_CODE, $settingKey]);
    } else {
        continue;
    }
    say('set ' . $settingKey . ($key === 'api_key' ? '' : ' = ' . $value));
}

// 5. The daily merchant-record refresh in OpenCart's cron table, as install() registers it.
if (query($db, "SELECT `cron_id` FROM `{$p}cron` WHERE `code` = ?", [Services::CRON_CODE]) === []) {
    query($db, "INSERT INTO `{$p}cron` SET `code` = ?, `description` = ?, `cycle` = ?, `action` = ?, `status` = 1, `date_added` = NOW(), `date_modified` = NOW()", [Services::CRON_CODE, Services::CRON_DESCRIPTION, Services::CRON_CYCLE, Services::CRON_ACTION]);
    say('registered cron ' . Services::CRON_CODE);
}
// The hourly pending check (TWO-26300), as install() registers it.
if (query($db, "SELECT `cron_id` FROM `{$p}cron` WHERE `code` = ?", [Services::PENDING_CRON_CODE]) === []) {
    query($db, "INSERT INTO `{$p}cron` SET `code` = ?, `description` = ?, `cycle` = ?, `action` = ?, `status` = 1, `date_added` = NOW(), `date_modified` = NOW()", [Services::PENDING_CRON_CODE, Services::PENDING_CRON_DESCRIPTION, Services::PENDING_CRON_CYCLE, Services::CRON_ACTION]);
    say('registered cron ' . Services::PENDING_CRON_CODE);
}

// 6. The order lifecycle on OpenCart's order history event, as install() registers it.
if (query($db, "SELECT `event_id` FROM `{$p}event` WHERE `code` = ?", [Services::EVENT_CODE]) === []) {
    query($db, "INSERT INTO `{$p}event` SET `code` = ?, `description` = ?, `trigger` = ?, `action` = ?, `status` = 1, `sort_order` = 0", [Services::EVENT_CODE, Services::EVENT_DESCRIPTION, Services::EVENT_TRIGGER, Services::EVENT_ACTION]);
    say('registered event ' . Services::EVENT_CODE);
}

// 6a. The checkout capture script on the checkout page, as install() registers it.
if (query($db, "SELECT `event_id` FROM `{$p}event` WHERE `code` = ?", [Services::CHECKOUT_EVENT_CODE]) === []) {
    query($db, "INSERT INTO `{$p}event` SET `code` = ?, `description` = ?, `trigger` = ?, `action` = ?, `status` = 1, `sort_order` = 0", [Services::CHECKOUT_EVENT_CODE, Services::CHECKOUT_EVENT_DESCRIPTION, Services::CHECKOUT_EVENT_TRIGGER, Services::CHECKOUT_EVENT_ACTION]);
    say('registered event ' . Services::CHECKOUT_EVENT_CODE);
}

// 6b. The saved-address capture and product page events, as install() registers them.
foreach (Services::ADDRESS_EVENTS + Services::PRODUCT_EVENTS as $code => [$trigger, $action, $description]) {
    if (query($db, "SELECT `event_id` FROM `{$p}event` WHERE `code` = ?", [$code]) === []) {
        query($db, "INSERT INTO `{$p}event` SET `code` = ?, `description` = ?, `trigger` = ?, `action` = ?, `status` = 1, `sort_order` = 0", [$code, $description, $trigger, $action]);
        say('registered event ' . $code);
    }
}

// 7. The order-scoped table, as install() creates it.
$renamed = DbOrderRecords::install(static fn (string $sql): array => query($db, $sql), $p);
if ($renamed !== null) {
    say('renamed the old ' . $p . DbOrderRecords::TABLE . ' table to ' . $renamed);
}
DbRefundRecords::install(static fn (string $sql): array => query($db, $sql), $p);

// 8. The checkout relays' rate-limit counts, as install() creates them.
query($db, DbRateLimits::createTableSql($p));

say('extension ' . TWO_CODE . ' ' . $manifest['version'] . ' installed and enabled');
