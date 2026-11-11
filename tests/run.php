<?php

declare(strict_types=1);

/**
 * Offline test runner: no shop, no Composer, no network. Every
 * tests/*Spec.php file defines a class of the same name with a static
 * runAll(); the runner finds them, runs each, and exits non-zero on any
 * failure. Set TESTS_TRACE=1 for stack traces.
 */

// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

$specs = glob(__DIR__ . '/*Spec.php') ?: [];
sort($specs);

if ($specs === []) {
    fwrite(STDERR, "No specs found in tests/.\n");
    exit(1);
}

$failed = 0;
foreach ($specs as $file) {
    require_once $file;
    $class = basename($file, '.php');

    try {
        if (!method_exists($class, 'runAll')) {
            throw new RuntimeException($file . ' must define class ' . $class . ' with a static runAll()');
        }
        $class::runAll();
        echo "PASS {$class}\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAIL {$class}: {$e->getMessage()}\n");
        if (getenv('TESTS_TRACE')) {
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }
    }
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} of " . count($specs) . " specs failed.\n");
    exit(1);
}

echo 'All ' . count($specs) . " specs passed.\n";
