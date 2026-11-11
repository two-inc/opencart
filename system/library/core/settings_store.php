<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: the shop's own settings storage, implemented by each platform adapter.
 * Keys are the plugin's unprefixed keys; the adapter adds its platform prefix
 * and store scope.
 */
interface SettingsStore
{
    /** The stored value, or null when the key has never been saved. */
    public function get(string $key): ?string;

    /**
     * Writes only the given keys and leaves every other key as it is. A key
     * with nothing stored yet is created, never dropped.
     *
     * @param array<string, string> $values
     */
    public function setMany(array $values): void;
}
