<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * A read-only SettingsStore that answers some keys from a fixed set and the
 * rest from the shop's store. It lets the admin ask Two about credentials the
 * merchant has typed but not saved, through the same Client, without a write
 * ever landing on the shop's settings: a candidate's verdict or record must
 * never be cached as if it were the stored key's.
 */
final class SettingsOverlay implements SettingsStore
{
    /** @param array<string, string> $overrides */
    public function __construct(private readonly SettingsStore $base, private readonly array $overrides)
    {
    }

    public function get(string $key): ?string
    {
        return $this->overrides[$key] ?? $this->base->get($key);
    }

    public function setMany(array $values): void
    {
        throw new \LogicException('A settings overlay is read-only.');
    }
}
