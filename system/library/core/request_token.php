<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The per-session token every checkout relay call carries. A write must be a
 * POST, and carries the token in its body; the one write whose body belongs
 * to the shared sole-trader module (a JSON body of its own) carries it on the
 * query string instead. A read carries it on the query string.
 */
final class RequestToken
{
    /**
     * @param array<mixed> $post
     * @param array<mixed> $get
     */
    public static function accepts(bool $write, bool $queryToken, string $method, array $post, array $get, mixed $stored): bool
    {
        if ($write && strtoupper($method) !== 'POST') {
            return false;
        }
        $sent = ($write && !$queryToken ? $post : $get)['two_token'] ?? '';

        return is_string($stored) && $stored !== '' && is_string($sent) && hash_equals($stored, $sent);
    }
}
