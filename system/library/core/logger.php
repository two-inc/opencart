<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: the shop's log. The core never passes an API key or other credential
 * in a message or context (Build Guide section 13.3).
 */
interface Logger
{
    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void;

    /**
     * Written whatever the debug setting: something the merchant should look
     * at that is not a failure.
     *
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Written whatever the debug setting: an operational fact the merchant
     * should be able to find, such as delegated shop-match checks.
     *
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * Written only when the merchant has debug logging on.
     *
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;
}
