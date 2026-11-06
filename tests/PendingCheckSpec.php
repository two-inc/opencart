<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\PendingCheck;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The pending check (TWO-26300): one row per Two state and trigger. Each row
 * is Two's answers, what the adapter is told to do, the message key, the
 * requests that reached Two in order, and what the check stored.
 */
final class PendingCheckSpec
{
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';
    private const NOW = 1_800_000_000;

    public static function runAll(): void
    {
        self::testCheck();
        self::testScheduleStale();
    }

    private static function testCheck(): void
    {
        $get = static fn (string $state): HttpResponse => self::json(200, ['id' => self::ID, 'state' => $state, 'status' => 'APPROVED']);
        $read = 'GET /v1/order/' . self::ID;
        $confirm = 'POST /v1/order/' . self::ID . '/confirm';
        $s = OrderFlow::TRIGGER_SCHEDULED;
        $a = OrderFlow::TRIGGER_ADMIN;
        $b = OrderFlow::TRIGGER_BUYER;
        $ok = self::json(200, ['state' => 'CONFIRMED']);
        $grown = [3, ['total' => 375, 'tax_total' => 75]];
        $cases = [
            // trigger, record over the base, Two's answers, rebuild, outcome, action, message key, requests, stored [state, checked_at, missing_at, check_closed], description
            [$s, [], [$get('VERIFIED'), $ok], null, OrderFlow::CONFIRMED, 'place', 'note_check_confirmed', [$read, $confirm], ['CONFIRMED', self::NOW, null, null], 'scheduled, verified: confirmed with Two, then placed'],
            [$a, [], [$get('VERIFIED'), $ok], null, OrderFlow::CONFIRMED, 'place', 'note_check_confirmed', [$read, $confirm], ['CONFIRMED', self::NOW, null, null], 'button, verified: confirmed with Two, then placed'],
            [$s, [], [$get('VERIFIED'), self::json(500, [])], null, OrderFlow::WAITING, 'none', 'note_check_confirm_failed', [$read, $confirm], ['VERIFIED', self::NOW, null, null], 'scheduled, the confirm fails: nothing placed or noted, retried next run'],
            [$a, [], [$get('VERIFIED'), HttpResponse::unreachable('timeout')], null, OrderFlow::WAITING, 'note', 'note_check_confirm_failed', [$read, $confirm], ['VERIFIED', self::NOW, null, null], 'button, no answer to the confirm: the reason noted, never a confirmation'],
            [$s, [], [$get('CONFIRMED')], null, OrderFlow::CONFIRMED, 'place', 'note_check_already', [$read], ['CONFIRMED', self::NOW, null, null], 'scheduled, already confirmed: placed with no confirm sent'],
            [$s, [], [$get('FULFILLED')], null, OrderFlow::CONFIRMED, 'place', 'note_check_already', [$read], ['FULFILLED', self::NOW, null, null], 'scheduled, already fulfilled: placed with no confirm sent'],
            [$s, [], [$get('CANCELLED')], null, OrderFlow::CANCELLED, 'note', 'note_check_cancelled', [$read], ['CANCELLED', self::NOW, null, null], 'scheduled, cancelled at Two: left unplaced with a note, no cancel sent'],
            [$a, [], [$get('CANCELLED')], null, OrderFlow::CANCELLED, 'note', 'note_check_cancelled', [$read], ['CANCELLED', self::NOW, null, null], 'button, cancelled at Two: noted, no cancel sent'],
            [$s, [], [$get('UNVERIFIED')], null, OrderFlow::WAITING, 'none', 'note_check_unverified', [$read], ['UNVERIFIED', self::NOW, null, null], 'scheduled, unverified: only the stamp'],
            [$a, [], [$get('UNVERIFIED')], null, OrderFlow::WAITING, 'note', 'note_check_unverified', [$read], ['UNVERIFIED', self::NOW, null, null], 'button, unverified: the merchant is told'],
            [$s, [], [$get('REFUNDED')], null, OrderFlow::WAITING, 'none', 'note_check_state', [$read], ['REFUNDED', self::NOW, null, null], 'scheduled, refunded: nothing changed'],
            [$a, [], [$get('SOMETHING_NEW')], null, OrderFlow::WAITING, 'note', 'note_check_state', [$read], ['SOMETHING_NEW', self::NOW, null, null], 'button, a state not listed: nothing changed, the merchant told'],
            [$s, [], [self::json(500, [])], null, OrderFlow::WAITING, 'none', 'note_check_unread', [$read], ['', self::NOW, null, null], 'scheduled, the read fails: only the stamp'],
            [$s, [], [self::json(404, [])], null, OrderFlow::MISSING, 'none', 'note_check_unread', [$read], ['', self::NOW, self::NOW, null], 'scheduled, a first 404: remembered, nothing else'],
            [$s, ['missing_at' => (string) (self::NOW - 600)], [self::json(404, [])], null, OrderFlow::MISSING, 'none', 'note_check_unread', [$read], ['', self::NOW, self::NOW - 600, null], 'scheduled, a second 404 under 15 minutes later: not yet counted'],
            [$s, ['missing_at' => (string) (self::NOW - 900)], [self::json(404, [])], null, OrderFlow::MISSING, 'note', 'note_check_missing', [$read], ['', self::NOW, self::NOW - 900, PendingCheck::CLOSED_MISSING], 'scheduled, a 404 again 15 minutes on: closed with a note'],
            [$s, ['missing_at' => (string) (self::NOW - 900)], [$get('UNVERIFIED')], null, OrderFlow::WAITING, 'none', 'note_check_unverified', [$read], ['UNVERIFIED', self::NOW, 0, null], 'scheduled, found again after a 404: the 404 is forgotten'],
            [$s, [], [$get('VERIFIED')], $grown, OrderFlow::CHANGED, 'note', 'note_check_unmatched', [$read], ['VERIFIED', self::NOW, null, PendingCheck::CLOSED_CHANGED], 'scheduled, the cart changed: nothing sent to Two, closed for the merchant'],
            [$a, [], [$get('CONFIRMED')], $grown, OrderFlow::CHANGED, 'note', 'note_check_unmatched', [$read], ['CONFIRMED', self::NOW, null, PendingCheck::CLOSED_CHANGED], 'button, confirmed but the cart changed: not placed, nothing sent'],
            [$s, [], [$get('CANCELLED')], $grown, OrderFlow::CANCELLED, 'note', 'note_check_cancelled', [$read], ['CANCELLED', self::NOW, null, null], 'scheduled, cancelled and changed: the cancel decides, nothing sent'],
            [$b, [], [self::json(404, [])], null, OrderFlow::REFUSED, 'none', 'note_confirm_unread', [$read], ['', self::NOW, null, null], 'the buyer\'s return: a 404 never counts as missing'],
        ];
        foreach ($cases as [$trigger, $over, $responses, $rebuildSpec, $outcome, $action, $key, $calls, $stored, $description]) {
            $records = new MemoryRecords([42 => $over + ['payload_hash' => OrderFlow::payloadHash((new OrderBuilder())->build(self::order(), self::lines(2), [])), 'two_order_id' => self::ID, 'two_order_state' => '', 'company_name' => 'Acme AS']]);
            $transport = new ScriptedTransport($responses);
            $logger = new RecordingLogger();
            $subscribers = new ScriptedSubscribers();
            $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
            $flow = new OrderFlow($client, new OrderBuilder(), new Postprocessing($subscribers, $logger), new ErrorMapper(), $records, $logger);
            $check = new PendingCheck($flow, $records, $logger, static fn (): int => self::NOW);
            $spec = $rebuildSpec ?? [2, []];
            $rebuild = static fn (array $record): array => ['order' => $spec[1] + self::order(), 'lines' => self::lines($spec[0]), 'totals' => []];

            $result = $check->check(42, $trigger, $rebuild, ['products' => [], 'totals' => [], 'addresses' => []], Message::of('note_by_admin', 'admin'));
            $d = 'pending check: ' . $description;
            $did = $result['place'] ? 'place' : ($result['note'] ? 'note' : 'none');
            TinyAssert::same([$outcome, $action, $key], [$result['outcome'], $did, $result['message']->key], $d . ': outcome, action and message');
            TinyAssert::same($calls, array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls), $d . ': requests');
            $row = $records->rows[42];
            TinyAssert::same($stored, [
                $row['two_order_state'],
                isset($row['checked_at']) ? (int) $row['checked_at'] : null,
                isset($row['missing_at']) ? (int) $row['missing_at'] : null,
                $row['check_closed'] ?? null,
            ], $d . ': stored state, checked_at, missing_at and check_closed');
            foreach ($transport->calls as $call) {
                if ($call['method'] === 'POST') {
                    TinyAssert::same('confirm_' . self::ID, $call['headers']['Idempotency-Key'] ?? null, $d . ': the confirm carries its idempotency key');
                }
            }
            if (in_array($confirm, $calls, true)) {
                TinyAssert::same($trigger, $subscribers->contexts[count($subscribers->contexts) - 1]['trigger'] ?? null, $d . ': the hook sees the trigger');
            }
            TinyAssert::same(1, count(array_filter($logger->at('info'), static fn (string $line): bool => str_starts_with($line, 'Two pending check: order 42, Two order ' . self::ID . ', trigger ' . $trigger . ','))), $d . ': one log line for the check');
        }
    }

    private static function testScheduleStale(): void
    {
        $cases = [
            [0, 0, false, 'nothing waiting: no warning'],
            [2, 0, true, 'orders waiting and the URL never ran'],
            [2, self::NOW - 3_600, false, 'ran an hour ago: no warning yet'],
            [2, self::NOW - 3_601, true, 'not run for over an hour'],
            [1, self::NOW - 60, false, 'running every few minutes'],
        ];
        foreach ($cases as [$waiting, $lastRun, $expected, $description]) {
            TinyAssert::same($expected, PendingCheck::scheduleStale($waiting, $lastRun, self::NOW), 'schedule stale: ' . $description);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function lines(int $quantity): array
    {
        return [['name' => 'Widget', 'quantity' => $quantity, 'price' => 100, 'total' => 100 * $quantity, 'tax' => 25, 'rate' => 0.25, 'type' => 'PHYSICAL']];
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private static function order(): array
    {
        return [
            'order_id' => 42, 'currency' => 'NOK', 'currency_value' => 1.0, 'total' => 250, 'tax_total' => 50, 'date_added' => '2026-10-24 10:00:00',
            'company' => ['company_name' => 'Acme AS', 'organization_number' => '123456789', 'country_code' => 'NO'],
            'buyer' => ['email' => 'buyer@example.com', 'first_name' => 'Kari', 'last_name' => 'Nordmann', 'phone_number' => '+4799999999'],
            'billing_address' => ['street_address' => 'Gate 1', 'postal_code' => '0150', 'city' => 'Oslo', 'region' => '', 'country' => 'NO'],
            'merchant_urls' => ['merchant_confirmation_url' => 'https://shop.example/confirm'],
        ];
    }
}
