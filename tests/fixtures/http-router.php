<?php

declare(strict_types=1);

// A scripted HTTP server for CurlTransportSpec, run with `php -S`. Nothing else may serve it.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($path === '/ok') {
    header('Content-Type: application/json');
    echo '{"buyer_fee_share":"1.00"}';
} elseif ($path === '/truncated') {
    // Promises 100 bytes, sends 11, then stalls past the client's timeout.
    http_response_code(201);
    header('Content-Length: 100');
    echo '{"id":"abc"';
    flush();
    sleep(4);
} else {
    http_response_code(404);
}
