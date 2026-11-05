<?php

declare(strict_types=1);

// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Constants a shop's config.php defines at runtime, declared here so PHPStan
// can type the code that reads them (dev/configure.php). Analysis only.
define('DIR_EXTENSION', '/var/www/html/extension/');
define('DIR_SYSTEM', '/var/www/html/system/');
define('DIR_LOGS', '/var/www/storage/logs/');
define('HTTP_CATALOG', 'http://localhost/');
define('DB_DRIVER', 'mysqli');
define('DB_HOSTNAME', 'localhost');
define('DB_USERNAME', 'opencart');
define('DB_PASSWORD', '');
define('DB_DATABASE', 'opencart');
define('DB_PORT', '3306');
define('DB_PREFIX', 'oc_');
