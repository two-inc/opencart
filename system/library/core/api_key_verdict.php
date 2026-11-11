<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The API-key verdict heartbeat (Build Guide section 18, first link of the
 * withhold chain): whether the stored key works against the stored
 * environment, as a category, cached so checkout never pays for a live call
 * on every render.
 *
 * - A verdict is bound to the credential fingerprint (key and environment),
 *   so a new key or environment, by any route, misses the cache at once.
 * - A good verdict lives TTL_OK seconds, a failed one TTL_FAILURE, so an
 *   outage or a rotated key stops hiding Two within a minute.
 * - Before a live call the slot is claimed, carrying the previous verdict for
 *   this key when it is recent (serve-stale), so concurrent renders on a cold
 *   cache stand down instead of each calling Two. With no previous verdict the
 *   claim says "verifying", which withholds Two: never guess "ok".
 * - The categories only change what the merchant is told. Every status other
 *   than ok withholds Two from a render; only a rejected key or no key at all
 *   ("definitive") may refuse an order already being submitted.
 */
final class ApiKeyVerdict
{
    public const OK = 'ok';
    /** 401 or 403: Two rejected the key. */
    public const INVALID = 'invalid_key';
    /** 5xx: Two answered, badly. */
    public const SERVICE_ERROR = 'service_error';
    /** No HTTP answer at all: DNS, TLS, routing, timeout. */
    public const UNREACHABLE = 'unreachable';
    /** Any other answer, or a 200 that is not a merchant. */
    public const ERROR = 'error';
    public const NOT_CONFIGURED = 'not_configured';
    /** Not a verdict: a request is still asking and there is nothing to carry. */
    public const VERIFYING = 'verifying';

    public const CACHE_KEY = 'api_key_verdict';
    /** The last definitive answer (ok or rejected), kept apart from the heartbeat, which an outage overwrites. */
    public const DEFINITIVE_KEY = 'api_key_definitive';
    public const VERIFY_PATH = '/v1/merchant/verify_api_key';

    public const TTL_OK = 300;
    public const TTL_FAILURE = 60;
    public const CLAIM_WINDOW = 15;
    public const CARRY_MAX_AGE = 900;
    /** A cache miss can run inside a buyer's page render, so the live check is tight. */
    public const LIVE_TIMEOUT_SECONDS = 10;

    /** @var array{status: string, code: int|null}|null this request's answer, once reached */
    private ?array $memo = null;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly Client $client,
        private readonly SettingsProvider $settings,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * A live check of the stored key, categorised and recorded as the cached
     * verdict. The admin save calls this directly, so a fixed key shows at
     * checkout without waiting out a TTL.
     *
     * @return array{status: string, code: int|null, merchant: array<string, mixed>|null}
     */
    public function verify(int $timeoutSeconds = Client::TIMEOUT_SECONDS): array
    {
        if ($this->settings->apiKey() === '') {
            return ['status' => self::NOT_CONFIGURED, 'code' => null, 'merchant' => null];
        }
        $fingerprint = $this->settings->credentialFingerprint();
        $result = self::ask($this->client, $timeoutSeconds);

        $this->write($fingerprint, $result['status'], $result['code'], false, null);
        if ($result['status'] === self::OK || $result['status'] === self::INVALID) {
            $this->settings->store()->setMany([self::DEFINITIVE_KEY => (string) json_encode([
                'fingerprint' => $fingerprint,
                'status' => $result['status'],
                'verified_on' => ($this->clock)(),
            ])]);
        }
        if ($fingerprint === $this->settings->credentialFingerprint()) {
            $this->memo = ['status' => $result['status'], 'code' => $result['code']];
        }

        return $result;
    }

    /**
     * A live check of credentials the merchant has typed but not saved: the
     * admin's live check and the save-time gate. Nothing is cached, since the
     * verdict describes a key this shop does not hold (yet).
     *
     * @return array{status: string, code: int|null, merchant: array<string, mixed>|null}
     */
    public function check(string $apiKey, string $environment, int $timeoutSeconds = self::LIVE_TIMEOUT_SECONDS): array
    {
        if (trim($apiKey) === '') {
            return ['status' => self::NOT_CONFIGURED, 'code' => null, 'merchant' => null];
        }

        return self::ask($this->client->withCredentials(trim($apiKey), $environment), $timeoutSeconds);
    }

    /**
     * The last definitive answer Two gave about the stored credentials (ok
     * or rejected), however old, or null when it never gave one. Outages,
     * 5xx answers and claims never change it, so it says whether the stored
     * key is known good even while Two is down: a save must not overwrite a
     * known-good key with one Two could not judge.
     */
    public function lastDefinitiveStatus(): ?string
    {
        $slot = json_decode((string) $this->settings->store()->get(self::DEFINITIVE_KEY), true);
        if (!is_array($slot) || ($slot['fingerprint'] ?? null) !== $this->settings->credentialFingerprint()) {
            return null;
        }

        return in_array($slot['status'] ?? null, [self::OK, self::INVALID], true) ? $slot['status'] : null;
    }

    /**
     * The cached verdict for the stored key. With $allowLiveCheck false (a
     * payment submit, which must not stall on Two) a cache miss reads as
     * "verifying" instead of calling.
     */
    public function status(bool $allowLiveCheck = true): string
    {
        if ($this->memo !== null) {
            return $this->memo['status'];
        }
        if ($this->settings->apiKey() === '') {
            return self::NOT_CONFIGURED;
        }
        $fingerprint = $this->settings->credentialFingerprint();
        $stored = $this->read();
        $now = ($this->clock)();
        if ($stored !== null && $stored['fingerprint'] === $fingerprint && $stored['checked_on'] + self::ttl($stored) > $now) {
            $this->memo = ['status' => $stored['status'], 'code' => $stored['code']];

            return $stored['status'];
        }
        if (!$allowLiveCheck) {
            return self::VERIFYING;
        }

        $carry = $stored !== null
            && $stored['fingerprint'] === $fingerprint
            && $stored['status'] !== self::VERIFYING
            && $stored['verified_on'] + self::CARRY_MAX_AGE > $now;
        $this->write(
            $fingerprint,
            $carry ? $stored['status'] : self::VERIFYING,
            $carry ? $stored['code'] : null,
            true,
            $carry ? $stored['verified_on'] : 0,
        );

        return $this->verify(self::LIVE_TIMEOUT_SECONDS)['status'];
    }

    /**
     * Whether the stored key is known to be unusable, as opposed to merely
     * unconfirmed: the question an order submit asks, cache-only. Reads past
     * the TTL for the definitive categories (a rejection does not go stale in
     * a way that makes accepting the order the better guess), but never trusts
     * an expired claim or a verdict for other credentials.
     */
    public function isDefinitelyUnusable(): bool
    {
        if (self::isDefinitive($this->status(false))) {
            return true;
        }
        $stored = $this->read();
        if ($stored === null || $stored['claim'] || $stored['fingerprint'] !== $this->settings->credentialFingerprint()) {
            return false;
        }

        return self::isDefinitive($stored['status']);
    }

    /** The categories that mean "this integration cannot take an order", not "ask again". */
    public static function isDefinitive(string $status): bool
    {
        return $status === self::INVALID || $status === self::NOT_CONFIGURED;
    }

    /** @return array{status: string, code: int|null, merchant: array<string, mixed>|null} */
    private static function ask(Client $client, int $timeoutSeconds): array
    {
        $response = $client->request('GET', self::VERIFY_PATH, null, $timeoutSeconds);
        $merchant = $response->json();
        $status = self::categorise($response, $merchant);

        return ['status' => $status, 'code' => $response->reachedTwo() ? $response->status : null, 'merchant' => $status === self::OK ? $merchant : null];
    }

    /** @param array<string, mixed>|null $body */
    private static function categorise(HttpResponse $response, ?array $body): string
    {
        return match (true) {
            !$response->reachedTwo() => self::UNREACHABLE,
            $response->status === 401, $response->status === 403 => self::INVALID,
            $response->status >= 500 => self::SERVICE_ERROR,
            // A 200 that is not the merchant (a captive portal, a proxy page) judged no key.
            $response->status !== 200, !isset($body['id']) => self::ERROR,
            default => self::OK,
        };
    }

    /** @param array{status: string, claim: bool} $slot */
    private static function ttl(array $slot): int
    {
        if ($slot['claim']) {
            return self::CLAIM_WINDOW;
        }

        return $slot['status'] === self::OK ? self::TTL_OK : self::TTL_FAILURE;
    }

    /**
     * @param int|null $verifiedOn when the carried verdict was reached; null means now
     */
    private function write(string $fingerprint, string $status, ?int $code, bool $claim, ?int $verifiedOn): void
    {
        $now = ($this->clock)();
        $this->settings->store()->setMany([self::CACHE_KEY => (string) json_encode([
            'status' => $status,
            'code' => $code,
            'fingerprint' => $fingerprint,
            'claim' => $claim,
            'verified_on' => $verifiedOn ?? $now,
            'checked_on' => $now,
        ])]);
    }

    /** @return array{status: string, code: int|null, fingerprint: string, claim: bool, verified_on: int, checked_on: int}|null */
    private function read(): ?array
    {
        $slot = json_decode((string) $this->settings->store()->get(self::CACHE_KEY), true);
        if (!is_array($slot) || !is_string($slot['status'] ?? null) || !is_string($slot['fingerprint'] ?? null)) {
            return null;
        }

        return [
            'status' => $slot['status'],
            'code' => is_int($slot['code'] ?? null) ? $slot['code'] : null,
            'fingerprint' => $slot['fingerprint'],
            'claim' => ($slot['claim'] ?? false) === true,
            'verified_on' => is_int($slot['verified_on'] ?? null) ? $slot['verified_on'] : 0,
            'checked_on' => is_int($slot['checked_on'] ?? null) ? $slot['checked_on'] : 0,
        ];
    }
}
