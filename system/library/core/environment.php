<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Two environment selection (Build Guide section 3.2): production, sandbox,
 * and staging only for internal Two shops. Explicit host maps, no hostname
 * logic. The adapter reads the TWO_INTERNAL_ENVIRONMENTS flag from the process
 * and passes it through flagEnabled(), so this class never touches the
 * environment itself.
 *
 * Every rule here is pinned by the shared tests/fixtures/environment-cases.json
 * table, byte-identical in the sibling plugins. A stored value of null means
 * nothing is stored yet (a fresh install), which reads as the default.
 */
final class Environment
{
    public const PRODUCTION = 'production';
    public const SANDBOX = 'sandbox';
    public const STAGING = 'staging';
    public const DEFAULT = self::SANDBOX;

    /** Every environment, in the order the admin offers them. */
    public const ALL = [self::PRODUCTION, self::SANDBOX, self::STAGING];

    private const API_HOSTS = [
        self::PRODUCTION => 'https://api.two.inc',
        self::SANDBOX => 'https://api.sandbox.two.inc',
        self::STAGING => 'https://api.staging.two.inc',
    ];

    private const CHECKOUT_HOSTS = [
        self::PRODUCTION => 'https://checkout.two.inc',
        self::SANDBOX => 'https://checkout.sandbox.two.inc',
        self::STAGING => 'https://checkout.staging.two.inc',
    ];

    public function __construct(private readonly bool $internalEnvironmentsEnabled)
    {
    }

    /**
     * The TWO_INTERNAL_ENVIRONMENTS rule: only exactly "1" enables. Not a
     * security gate, since a staging key is needed to use staging anyway.
     */
    public static function flagEnabled(?string $raw): bool
    {
        return $raw === '1';
    }

    /**
     * The canonical environment for a stored value, or null when it is not
     * one: trimmed, lower-cased, "prod" read as production.
     */
    public static function recognise(?string $stored): ?string
    {
        $value = strtolower(trim((string) ($stored ?? self::DEFAULT)));
        $value = $value === 'prod' ? self::PRODUCTION : $value;

        return in_array($value, self::ALL, true) ? $value : null;
    }

    /**
     * The environments the admin offers, in display order: staging only on an
     * internal shop or when it is already stored, so it is never hidden.
     *
     * @return list<string>
     */
    public function options(?string $stored): array
    {
        return self::offered($stored, $this->internalEnvironmentsEnabled);
    }

    /**
     * The options() rule as a pure function of the stored value and the flag,
     * so dev/environment-parity.php can run it without building a shop.
     *
     * @return list<string>
     */
    public static function offered(?string $stored, bool $internalEnvironmentsEnabled): array
    {
        if ($internalEnvironmentsEnabled || self::recognise($stored) === self::STAGING) {
            return self::ALL;
        }

        return [self::PRODUCTION, self::SANDBOX];
    }

    /** Whether a submitted value may replace the stored one. */
    public function acceptsSave(string $submitted, ?string $stored): bool
    {
        return in_array($submitted, $this->options($stored), true);
    }

    /** The environment actually used: anything unrecognised is sandbox, never production. */
    public function resolve(?string $stored): string
    {
        return self::recognise($stored) ?? self::SANDBOX;
    }

    /** API base URL, without a trailing slash. Any value resolves first. */
    public function apiBaseUrl(?string $environment): string
    {
        return self::API_HOSTS[$this->resolve($environment)];
    }

    /** Hosted checkout base URL, without a trailing slash. Any value resolves first. */
    public function checkoutBaseUrl(?string $environment): string
    {
        return self::CHECKOUT_HOSTS[$this->resolve($environment)];
    }

    /**
     * False when the stored value cannot be honoured as stored, for the health
     * row: unrecognised, or staging on a shop without the flag.
     */
    public function isHealthy(?string $stored): bool
    {
        $environment = self::recognise($stored);

        return $environment !== null && ($environment !== self::STAGING || $this->internalEnvironmentsEnabled);
    }

    public function internalEnvironmentsEnabled(): bool
    {
        return $this->internalEnvironmentsEnabled;
    }
}
