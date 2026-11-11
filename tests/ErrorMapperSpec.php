<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;

/**
 * Build Guide section 6.4: what the buyer is told when Two refuses an order
 * create (language keys, never Two's text), and the reason the merchant gets.
 */
final class ErrorMapperSpec
{
    public static function runAll(): void
    {
        $field = static fn (array $loc, string $msg = 'value is not valid'): array => ['loc' => $loc, 'msg' => $msg];
        $cases = [
            ['status' => 400, 'body' => ['error_json' => [$field(['buyer', 'representative', 'phone_number'])]], 'keys' => ['error_two_field_phone_number'], 'description' => 'a phone number Two names'],
            ['status' => 400, 'body' => ['error_json' => [$field(['buyer'], 'Invalid phone number: +47')]], 'keys' => ['error_two_field_phone_number'], 'description' => 'a phone error reported on the buyer'],
            ['status' => 400, 'body' => ['error_json' => [$field(['buyer', 'company', 'organization_number']), $field(['billing_address', 'postal_code'])]], 'keys' => ['error_two_field_organization_number', 'error_two_field_postal_code'], 'description' => 'one sentence per field, in Two\'s order'],
            ['status' => 400, 'body' => ['error_json' => [$field(['billing_address', 'city']), $field(['billing_address', 'city'])]], 'keys' => ['error_two_field_city'], 'description' => 'a field named twice is said once'],
            ['status' => 400, 'body' => ['error_json' => [$field(['invoice_details', 'invoice_emails', 0])]], 'keys' => ['error_two_field_invoice_email'], 'description' => 'an invoice email at any index'],
            ['status' => 400, 'body' => ['error_json' => [$field(['line_items', 0, 'tax_rate'])]], 'keys' => ['error_two_generic'], 'description' => 'a field the buyer cannot fix gives the generic sentence'],
            ['status' => 400, 'body' => ['error_json' => [['loc' => [['nested']], 'msg' => 'x']]], 'keys' => ['error_two_generic'], 'description' => 'a malformed location is ignored'],
            ['status' => 400, 'body' => ['error_code' => 'SAME_BUYER_SELLER_ERROR', 'error_message' => 'raw text'], 'keys' => ['error_two_same_company'], 'description' => 'buyer and merchant the same company'],
            ['status' => 400, 'body' => ['error_code' => 'SCHEMA_ERROR', 'error_message' => 'raw text'], 'keys' => ['error_two_generic'], 'description' => 'any other 4xx'],
            ['status' => 500, 'body' => null, 'keys' => ['error_two_generic'], 'description' => 'a server error'],
            ['status' => 0, 'body' => null, 'keys' => ['error_two_generic'], 'description' => 'Two not reached'],
        ];
        $mapper = new ErrorMapper();
        foreach ($cases as $case) {
            TinyAssert::same($case['keys'], $mapper->buyerMessageKeys($case['status'], $case['body']), 'buyer keys: ' . $case['description']);
        }

        $reasons = [
            ['response' => new HttpResponse(400, '{"error_code":"SCHEMA_ERROR","error_message":"Field required"}'), 'reason' => 'HTTP 400: SCHEMA_ERROR: Field required', 'note' => 'HTTP 400: SCHEMA_ERROR: Field required.', 'ours' => false, 'description' => 'status, code and Two\'s message'],
            ['response' => new HttpResponse(502, '<html>bad gateway</html>'), 'reason' => 'HTTP 502', 'note' => 'HTTP 502.', 'ours' => false, 'description' => 'a body that is not JSON'],
            ['response' => new HttpResponse(400, '{"error_message":"Field required."}'), 'reason' => 'HTTP 400: Field required.', 'note' => 'HTTP 400: Field required.', 'ours' => false, 'description' => 'Two\'s message with its own full stop gets no second one'],
            ['response' => HttpResponse::unreachable('Could not resolve host'), 'reason' => 'Two could not be reached (Could not resolve host).', 'note' => 'Two could not be reached (Could not resolve host).', 'ours' => true, 'description' => 'a transport failure'],
            ['response' => HttpResponse::unreachable(''), 'reason' => 'Two could not be reached (no response).', 'note' => 'Two could not be reached (no response).', 'ours' => true, 'description' => 'no response at all'],
            ['response' => new HttpResponse(400, (string) json_encode(['detail' => str_repeat('x', 600)])), 'reason' => mb_substr('HTTP 400: ' . str_repeat('x', 600), 0, ErrorMapper::REASON_LIMIT) . '...', 'note' => mb_substr('HTTP 400: ' . str_repeat('x', 600), 0, ErrorMapper::REASON_LIMIT) . '...', 'ours' => false, 'description' => 'a long message is cut'],
        ];
        foreach ($reasons as $case) {
            TinyAssert::same($case['reason'], $mapper->reason($case['response']), 'merchant reason: ' . $case['description']);
            $note = $mapper->noteReason($case['response']);
            // The plugin's own words are a Message, translated in the note; Two's text goes as Two sent it, ending in a full stop.
            TinyAssert::same([$case['ours'], $case['note']], [$note instanceof Message, $note instanceof Message ? $note->english() : $note], 'note reason: ' . $case['description']);
        }


        // Every note slot that takes a reason reads with one full stop, whatever the reason: Two's text or the plugin's own.
        $bad = new HttpResponse(502, '<html>bad gateway</html>');
        $slots = [
            ['key' => 'note_edit_unknown', 'before' => ['abc'], 'response' => $bad, 'english' => 'The edit to order abc may not have reached Two: HTTP 502. It is sent again on the next status save.', 'description' => 'an edit with an unknown outcome'],
            ['key' => 'note_refund_unknown', 'before' => ['10.00 NOK', 'abc'], 'response' => $bad, 'english' => 'The outcome of the refund of 10.00 NOK on order abc is unknown: HTTP 502. Retry it on the Two tab of the order page; no other refund is sent until then.', 'description' => 'a refund with an unknown outcome'],
            ['key' => 'note_create_earlier_open', 'before' => ['abc'], 'response' => $bad, 'english' => 'Two order not created. The earlier Two order abc for this order could not be cancelled: HTTP 502.', 'description' => 'an earlier Two order left open'],
            ['key' => 'note_cancel_refused_why', 'before' => ['abc', 'CONFIRMED'], 'response' => $bad, 'english' => 'Two order abc not cancelled: HTTP 502. Two holds it as CONFIRMED. Save the status again to retry.', 'description' => 'a refused cancel'],
            ['key' => 'note_edit_unknown', 'before' => ['abc'], 'response' => HttpResponse::unreachable('Timeout'), 'english' => 'The edit to order abc may not have reached Two: Two could not be reached (Timeout). It is sent again on the next status save.', 'description' => 'the plugin\'s own reason in the same slot'],
        ];
        foreach ($slots as $case) {
            TinyAssert::same($case['english'], Message::of($case['key'], ...[...$case['before'], $mapper->noteReason($case['response'])])->english(), 'reason slot: ' . $case['description']);
        }

        self::testEveryKeyHasCopy();
    }

    /** Every key the mapper can return has a sentence in the storefront language file. */
    private static function testEveryKeyHasCopy(): void
    {
        $_ = [];
        require dirname(__DIR__) . '/catalog/language/en-gb/payment/two.php';
        /** @var array<string, string> $_ the language file fills it */
        $keys = array_merge([ErrorMapper::GENERIC, ErrorMapper::SAME_COMPANY, ErrorMapper::COMPANY_REQUIRED, ErrorMapper::INVOICE_EMAILS], array_values(ErrorMapper::FIELDS));
        foreach ($keys as $key) {
            TinyAssert::true(isset($_[$key]) && $_[$key] !== '', 'en-gb copy for ' . $key);
            TinyAssert::true(!str_contains($_[$key], 'Two'), 'en-gb copy for ' . $key . ' takes the brand from the descriptor');
        }
    }
}
