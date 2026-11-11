<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/** What came back from Two, as received. */
final class HttpResponse
{
    /** The transport error when nothing at all came back, which the merchant's note words itself. */
    public const NO_RESPONSE = 'no response';

    /**
     * @param int                   $status         0 when nothing came back (DNS, TLS, routing, timeout)
     * @param array<string, string> $headers        lower-cased names
     * @param string                $transportError why nothing came back, empty otherwise
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly string $transportError = '',
    ) {
    }

    /** A transport failure that never reached Two, as opposed to any HTTP answer. */
    public static function unreachable(string $error): self
    {
        return new self(0, '', [], $error === '' ? self::NO_RESPONSE : $error);
    }

    public function reachedTwo(): bool
    {
        return $this->status > 0 && $this->transportError === '';
    }

    /**
     * The body as a JSON object, or null when it is not one.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
