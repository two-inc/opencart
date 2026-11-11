<?php

declare(strict_types=1);

/**
 * Loads the extension's library classes outside OpenCart (tests, dev scripts)
 * with the same file-name rule OpenCart 4's autoloader applies inside a shop:
 * the namespace below Opencart\System\Library\Extension\Two maps to
 * system/library/, lower-cased, with CamelCase split by underscores. A class
 * that loads here therefore loads in the shop too.
 */
// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Opencart\\System\\Library\\Extension\\Two\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = (string) preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/system/library/' . trim(str_replace('\\', '/', strtolower($relative)), '/') . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
