<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\Logger;
use Opencart\System\Library\Extension\Two\Core\SettingsStore;
use Opencart\System\Library\Extension\Two\Core\Transport;

// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** The SettingsStore port over an array, for the core specs. */
final class MemoryStore implements SettingsStore
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function setMany(array $values): void
    {
        $this->values = $values + $this->values;
    }
}

/** The Transport port answering from a script, one response per call, recording every call. */
class ScriptedTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, verifyTls: bool, timeout: int}> */
    public array $calls = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls, int $timeoutSeconds): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'verifyTls' => $verifyTls, 'timeout' => $timeoutSeconds];

        return array_shift($this->responses) ?? HttpResponse::unreachable('script exhausted');
    }

    public function sendAll(array $requests, bool $verifyTls, int $timeoutSeconds): array
    {
        return array_map(fn (array $request): HttpResponse => $this->send($request['method'], $request['url'], $request['headers'], $request['body'], $verifyTls, $timeoutSeconds), $requests);
    }
}

/** The Logger port recording every line, debug included. */
final class RecordingLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $lines = [];

    public function error(string $message, array $context = []): void
    {
        $this->lines[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->lines[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }

    public function info(string $message, array $context = []): void
    {
        $this->lines[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function debug(string $message, array $context = []): void
    {
        $this->lines[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
    }

    /** @return list<string> the messages logged at one level */
    public function at(string $level): array
    {
        return array_values(array_map(static fn (array $line): string => $line['message'], array_filter($this->lines, static fn (array $line): bool => $line['level'] === $level)));
    }

    /** Everything logged, as one string, for "never logged" checks. */
    public function dump(): string
    {
        return (string) json_encode($this->lines);
    }
}

/** The OrderRecords port over an array, presence-conditional like the table. */
final class MemoryRecords implements \Opencart\System\Library\Extension\Two\Core\OrderRecords
{
    /** @param array<int, array<string, string>> $rows */
    public function __construct(public array $rows = [])
    {
    }

    public function get(int $orderId): ?array
    {
        return $this->rows[$orderId] ?? null;
    }

    public function put(int $orderId, array $fields): void
    {
        $this->rows[$orderId] = $fields + ($this->rows[$orderId] ?? []);
    }
}

/** The PostprocessingSubscribers port running closures, recording the context each saw. */
final class ScriptedSubscribers implements \Opencart\System\Library\Extension\Two\Core\PostprocessingSubscribers
{
    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    /** @var list<array<string, mixed>> the payload each dispatch was handed */
    public array $payloads = [];

    /** @param array<string, \Closure(array<string, mixed>, array<string, mixed>): mixed> $handlers by name */
    public function __construct(private readonly array $handlers = [])
    {
    }

    public function names(): array
    {
        return array_keys($this->handlers);
    }

    public function dispatch(array $payload, array $context): mixed
    {
        $this->contexts[] = $context;
        $this->payloads[] = $payload;
        $result = $payload;
        foreach ($this->handlers as $handler) {
            $result = $handler($result, $context);
        }

        return $result;
    }
}

/** The RefundRecords port over an array, ids from 1. */
final class MemoryRefunds implements \Opencart\System\Library\Extension\Two\Core\RefundRecords
{
    /** @var array<int, array<string, string>> */
    public array $rows = [];

    /** @var list<string> lock and unlock calls, in order */
    public array $locks = [];

    public function __construct(private readonly bool $fails = false, private readonly bool $held = false)
    {
    }

    public function lock(int $orderId): bool
    {
        $this->locks[] = 'lock ' . $orderId;

        return !$this->held;
    }

    public function unlock(int $orderId): void
    {
        $this->locks[] = 'unlock ' . $orderId;
    }

    public function create(int $orderId, array $fields): int
    {
        if ($this->fails) {
            return 0;
        }
        $id = count($this->rows) + 1;
        $this->rows[$id] = ['refund_id' => (string) $id, 'order_id' => (string) $orderId, 'date_added' => '2026-10-25 12:00:00'] + $fields;

        return $id;
    }

    public function update(int $refundId, array $fields): void
    {
        $this->rows[$refundId] = $fields + $this->rows[$refundId];
    }

    public function forOrder(int $orderId): array
    {
        return array_values(array_filter($this->rows, static fn (array $row): bool => $row['order_id'] === (string) $orderId));
    }
}
