<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\Logger;

/**
 * The core's Logger port over OpenCart's error log. Debug lines are written
 * only with the merchant's debug logging on. The core never passes a
 * credential in, so this class writes what it is given.
 */
final class LogLogger implements Logger
{
    public function __construct(
        private readonly \Opencart\System\Library\Log $log,
        private readonly bool $debug,
    ) {
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->write('debug', $message, $context);
        }
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $line = 'Two ' . $level . ': ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $this->log->write($line);
    }
}
