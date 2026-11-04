<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * What the buyer is told when Two refuses an order (Build Guide section 6.4),
 * as language keys the adapter turns into copy: one message per field Two
 * names that the buyer can fix, else the same-company refusal, else the
 * generic sentence. Two's own text never reaches the storefront; reason()
 * gives the full answer for the log and the merchant's order history.
 */
final class ErrorMapper
{
    public const GENERIC = 'error_two_generic';
    public const SAME_COMPANY = 'error_two_same_company';
    public const COMPANY_REQUIRED = 'error_two_company_required';

    /** Two's field paths the buyer can correct, and the key of each field's sentence. */
    public const FIELDS = [
        'buyer.representative.phone_number' => 'error_two_field_phone_number',
        'buyer.company.organization_number' => 'error_two_field_organization_number',
        'buyer.company.company_name' => 'error_two_field_company_name',
        'buyer.representative.first_name' => 'error_two_field_first_name',
        'buyer.representative.last_name' => 'error_two_field_last_name',
        'buyer.representative.email' => 'error_two_field_email',
        'billing_address.street_address' => 'error_two_field_address',
        'billing_address.city' => 'error_two_field_city',
        'billing_address.country' => 'error_two_field_country',
        'billing_address.postal_code' => 'error_two_field_postal_code',
    ];
    public const INVOICE_EMAILS = 'error_two_field_invoice_email';

    /** How much of Two's own message the order history keeps. */
    public const REASON_LIMIT = 500;

    /**
     * @param array<string, mixed>|null $errorBody the decoded response, null when it was not JSON
     * @return non-empty-list<string> language keys, each a whole sentence, in Two's order with repeats dropped
     */
    public function buyerMessageKeys(int $status, ?array $errorBody): array
    {
        $keys = [];
        foreach (is_array($errorBody['error_json'] ?? null) ? $errorBody['error_json'] : [] as $error) {
            $key = is_array($error) ? self::fieldKey($error) : null;
            if ($key !== null && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if ($keys !== []) {
            return $keys;
        }
        if (($errorBody['error_code'] ?? null) === 'SAME_BUYER_SELLER_ERROR') {
            return [self::SAME_COMPANY];
        }

        return [self::GENERIC];
    }

    /**
     * The full reason, for the log: the status, Two's error code and Two's
     * own message, cut to REASON_LIMIT characters.
     */
    public function reason(HttpResponse $response): string
    {
        return $response->reachedTwo() ? self::twoText($response) : self::unreached($response)->english();
    }

    /**
     * The reason for a merchant's note, a sentence ending in a full stop like
     * every Message reason, as the notes follow it with the next sentence:
     * Two's text as Two sent it (see reason), or, when Two was not reached,
     * the plugin's own words as a Message, so the note reads in one language.
     */
    public function noteReason(HttpResponse $response): Message|string
    {
        if (!$response->reachedTwo()) {
            return self::unreached($response);
        }
        $text = self::twoText($response);

        return preg_match('~[.!?]\z~u', $text) === 1 ? $text : $text . '.';
    }

    private static function unreached(HttpResponse $response): Message
    {
        return $response->transportError === HttpResponse::NO_RESPONSE ? Message::of('reason_no_response') : Message::of('reason_unreachable', $response->transportError);
    }

    private static function twoText(HttpResponse $response): string
    {
        $body = $response->json() ?? [];
        $parts = ['HTTP ' . $response->status];
        if (is_scalar($body['error_code'] ?? null)) {
            $parts[] = (string) $body['error_code'];
        }
        $own = self::ownMessage($response, PHP_INT_MAX);
        if ($own !== '') {
            $parts[] = $own;
        }
        $reason = implode(': ', $parts);

        return mb_strlen($reason) > self::REASON_LIMIT ? mb_substr($reason, 0, self::REASON_LIMIT) . '...' : $reason;
    }

    /**
     * Two's own message in an error body (the first of the fields Two uses
     * for it), trimmed and cut to $limit characters, or empty when it sent
     * none. For merchant-facing admin copy; never shown to a buyer.
     */
    public static function ownMessage(HttpResponse $response, int $limit = 500): string
    {
        $body = $response->json() ?? [];
        foreach (['error_message', 'detail', 'message', 'error_details', 'error'] as $key) {
            if (is_scalar($body[$key] ?? null) && trim((string) $body[$key]) !== '') {
                $message = trim((string) $body[$key]);

                return mb_strlen($message) > $limit ? mb_substr($message, 0, $limit) . '...' : $message;
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $error one error_json entry: loc, the path to the field, and msg
     */
    private static function fieldKey(array $error): ?string
    {
        if (!is_array($error['loc'] ?? null) || !is_string($error['msg'] ?? null)) {
            return null;
        }
        foreach ($error['loc'] as $segment) {
            if (!is_scalar($segment)) {
                return null;
            }
        }
        $loc = implode('.', array_map('strval', $error['loc']));
        if ($loc === 'invoice_details.invoice_emails' || str_starts_with($loc, 'invoice_details.invoice_emails.')) {
            return self::INVOICE_EMAILS;
        }
        if ($loc === 'buyer' && str_contains($error['msg'], 'Invalid phone number')) {
            $loc = 'buyer.representative.phone_number';
        }

        return self::FIELDS[$loc] ?? null;
    }
}
