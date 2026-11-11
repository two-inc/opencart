<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\Transport;

/**
 * The core's Transport port over cURL. TLS verification is on (peer and host)
 * unless the Client passes the merchant's bypass. A call with no HTTP answer
 * returns HttpResponse::unreachable() with cURL's reason.
 *
 * send() carries every request. sendAll() exists for the fee quotes alone
 * (the chips' preview asks every term at once): each handle's own result is
 * read, and any cURL error, a timeout mid-body included, or a body shorter
 * than its Content-Length is a call that got no answer, so it fails closed.
 */
final class CurlTransport implements Transport
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls, int $timeoutSeconds): HttpResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        // No curl_close(): the handle is freed with $ch. The call has done
        // nothing since PHP 8.0 and its deprecation notice on PHP 8.5 lands
        // in the response (TWO-27230).

        if (!is_string($response) || $error !== '' || $status === 0) {
            return HttpResponse::unreachable($error);
        }

        return new HttpResponse($status, $response, $received);
    }

    /** The fee quotes' parallel path: every call on one cURL multi handle. */
    public function sendAll(array $requests, bool $verifyTls, int $timeoutSeconds): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $results = [];
        $received = [];
        foreach ($requests as $index => $request) {
            $lines = [];
            foreach ($request['headers'] as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            $received[$index] = [];
            $ch = curl_init($request['url']);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $request['method'],
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received, $index): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $received[$index][strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return strlen($line);
                },
            ]);
            if ($request['body'] !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
            }
            $handles[$index] = $ch;
            if (curl_multi_add_handle($multi, $ch) !== CURLM_OK) {
                // Never added, so never run: a failed call, and nothing to wait for.
                $results[$index] = CURLE_FAILED_INIT;
            }
        }

        // Each handle's own result: curl_error() is empty for a multi handle.
        $read = static function () use ($multi, $handles, &$results): void {
            while (($done = curl_multi_info_read($multi)) !== false) {
                $index = array_search($done['handle'], $handles, true);
                if ($index !== false) {
                    $results[$index] = (int) $done['result'];
                }
            }
        };
        do {
            $status = curl_multi_exec($multi, $running);
            $read();
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        // A handle with no result read is a failed call (CURLE_RECV_ERROR below), never a wait.
        $read();

        $responses = [];
        foreach ($handles as $index => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $result = $results[$index] ?? CURLE_RECV_ERROR;
            curl_multi_remove_handle($multi, $ch);
            $length = $received[$index]['content-length'] ?? null;
            if ($result !== CURLE_OK) {
                $responses[] = HttpResponse::unreachable(curl_strerror($result));
            } elseif (!is_string($body) || $code === 0) {
                $responses[] = HttpResponse::unreachable(HttpResponse::NO_RESPONSE);
            } elseif ($length !== null && ctype_digit($length) && strlen($body) !== (int) $length) {
                $responses[] = HttpResponse::unreachable('truncated body: ' . strlen($body) . ' of ' . $length . ' bytes');
            } else {
                $responses[] = new HttpResponse($code, $body, $received[$index]);
            }
        }
        curl_multi_close($multi);

        return $responses;
    }
}
