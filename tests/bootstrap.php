<?php

declare(strict_types=1);

// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/dev/autoload.php';

// What a shop's config.php defines, at the values the specs assume.
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}
if (!class_exists('Opencart\\System\\Library\\DB', false)) {
    require __DIR__ . '/fixtures/opencart-db-double.php';
}
if (!class_exists('Opencart\\System\\Library\\Language', false)) {
    require __DIR__ . '/fixtures/opencart-language-doubles.php';
}
if (!class_exists('Opencart\\Admin\\Model\\Setting\\Cron', false)) {
    require __DIR__ . '/fixtures/opencart-cron-double.php';
}
require_once __DIR__ . '/fixtures/core-doubles.php';

/** The harness's assertions. Every message names the case that failed. */
final class TinyAssert
{
    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true(bool $value, string $message): void
    {
        self::same(true, $value, $message);
    }

    public static function throws(callable $callback, string $expectedClass, string $message): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (!$e instanceof $expectedClass) {
                throw new RuntimeException($message . ': expected ' . $expectedClass . ', got ' . get_class($e) . ' (' . $e->getMessage() . ')');
            }

            return;
        }

        throw new RuntimeException($message . ': expected ' . $expectedClass . ', nothing was thrown');
    }
}
