<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\CurlTransport;

/**
 * The cURL transport against a local scripted server: a full answer, a body
 * cut off by the timeout, and a refused connection. The fee quotes' parallel
 * path (sendAll) must fail closed on the last two, as the single path does,
 * and name cURL's own reason.
 */
final class CurlTransportSpec
{
    public static function runAll(): void
    {
        $port = self::freePort();
        // Workers, so a stalled /truncated never holds up the other calls.
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/http-router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '8'] + getenv());
        TinyAssert::true(is_resource($server), 'curl transport: the scripted server starts');
        try {
            self::waitFor($port);
            $base = 'http://127.0.0.1:' . $port;
            $refused = 'http://127.0.0.1:' . self::freePort() . '/ok';
            $cases = [
                [$base . '/ok', 200, '{"buyer_fee_share":"1.00"}', false, 'a full answer is the answer'],
                [$base . '/truncated', 0, '', true, 'a body cut off by the timeout is no answer, never a short 201'],
                [$refused, 0, '', true, 'a refused connection is no answer, with cURL\'s reason'],
            ];
            $transport = new CurlTransport();
            $requests = array_map(static fn (array $case): array => ['method' => 'POST', 'url' => $case[0], 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'], $cases);
            $parallel = $transport->sendAll($requests, false, 2);
            foreach ($cases as $index => [$url, $status, $body, $failed, $description]) {
                $single = $transport->send('POST', $url, ['Content-Type' => 'application/json'], '{}', false, 2);
                foreach (['sendAll' => $parallel[$index], 'send' => $single] as $path => $response) {
                    TinyAssert::same([$status, $body], [$response->status, $response->body], 'curl transport ' . $path . ': ' . $description);
                    TinyAssert::same($failed, !$response->reachedTwo(), 'curl transport ' . $path . ' failed: ' . $description);
                    if ($failed) {
                        TinyAssert::true(!in_array($response->transportError, ['', 'no response'], true), 'curl transport ' . $path . ' names the reason: ' . $description . ' (' . $response->transportError . ')');
                    }
                }
            }
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        TinyAssert::true($socket !== false, 'curl transport: a free port');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function waitFor(int $port): void
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('the scripted server did not start');
    }
}
