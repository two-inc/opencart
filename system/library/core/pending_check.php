<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Recovering an order the buyer verified with Two but never returned from
 * (TWO-26300). The return to the shop is the only route by which an order is
 * placed, so a scheduled check and the admin's "Check status with Two"
 * button ask Two about orders still waiting, through OrderFlow::resolve, the
 * resolver the return uses.
 *
 * - Selection (the adapter's query, from these numbers): unplaced Two orders
 *   with a Two order id, last touched at checkout over MIN_AGE ago and
 *   created under MAX_AGE ago, not checked in the last RECHECK_AFTER, not
 *   cancelled at Two and not closed, oldest check first, BATCH per run.
 * - Each check stamps checked_at. A check that changes nothing writes only
 *   that stamp (and clears a pending 404).
 * - A 404 from Two counts only when it repeats on two checks at least
 *   MISSING_REPEAT apart: the order is then closed and noted. Never on the
 *   buyer's return, which does not come through here.
 * - A shop order that no longer matches the Two order is closed and noted,
 *   with nothing sent to Two: the merchant settles it.
 *
 * The adapter holds the order's lock and has found the shop order still
 * unplaced before calling check(); it then places the order or writes the
 * note as answered.
 */
final class PendingCheck
{
    public const MIN_AGE = 1_800;
    public const MAX_AGE = 604_800;
    public const RECHECK_AFTER = 900;
    public const BATCH = 50;
    public const MISSING_REPEAT = 900;

    /** Why an order is no longer selected, kept in its record's check_closed. */
    public const CLOSED_CHANGED = 'changed';
    public const CLOSED_MISSING = 'missing';

    /** When the token URL last ran the check, and how long without a run the Diagnostics tab warns. */
    public const LAST_RUN_KEY = 'pending_check_last_run';
    public const WARN_AFTER = 3_600;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly OrderFlow $flow,
        private readonly OrderRecords $records,
        private readonly Logger $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Checks one waiting order with Two. `place`: place the order at the
     * confirmed status with the note; `note`: leave it unplaced and write the
     * note. The button always notes, so the merchant sees what Two said.
     *
     * @param \Closure(array<string, string>): (array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>}|null) $rebuild
     * @param array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>, currency?: string}|null $rows
     * @param Message|null $by who checked, for the note of an order placed from the admin
     * @return array{place: bool, note: bool, message: Message, outcome: string, state: string}
     */
    public function check(int $orderId, string $trigger, \Closure $rebuild, ?array $rows = null, ?Message $by = null): array
    {
        $record = $this->records->get($orderId) ?? [];
        $now = ($this->clock)();
        $result = $this->flow->resolve($orderId, $record, $trigger, $rebuild, $rows, $by);
        $outcome = $result['outcome'];
        $message = $result['message'];

        $stamp = ['checked_at' => (string) $now];
        $missingAt = (int) ($record['missing_at'] ?? 0);
        if ($outcome === OrderFlow::MISSING) {
            if ($missingAt > 0 && $now - $missingAt >= self::MISSING_REPEAT) {
                $stamp['check_closed'] = self::CLOSED_MISSING;
                $message = Message::of('note_check_missing', $result['two_order_id']);
            } elseif ($missingAt <= 0) {
                $stamp['missing_at'] = (string) $now;
            }
        } elseif ($missingAt > 0) {
            $stamp['missing_at'] = '0';
        }
        if ($outcome === OrderFlow::CHANGED) {
            $stamp['check_closed'] = self::CLOSED_CHANGED;
        }
        $this->records->put($orderId, $stamp);

        $place = $outcome === OrderFlow::CONFIRMED;
        $changes = $place || $outcome === OrderFlow::CANCELLED || isset($stamp['check_closed']);
        $note = $place || $changes || $trigger === OrderFlow::TRIGGER_ADMIN;
        $action = $place ? 'place' : ($changes ? 'note, left unplaced' : 'none');
        $this->logger->info('Two pending check: order ' . $orderId . ', Two order ' . ($result['two_order_id'] !== '' ? $result['two_order_id'] : '-') . ', trigger ' . $trigger
            . ', Two state ' . ($result['state'] !== '' ? $result['state'] : '-') . ', action ' . $action . '.');

        return ['place' => $place, 'note' => $note, 'message' => $message, 'outcome' => $outcome, 'state' => $result['state']];
    }

    /**
     * The run summary, at debug level.
     *
     * @param array<string, int> $counts outcome => how many
     */
    public function summary(string $trigger, array $counts, int $skipped): void
    {
        $parts = [];
        foreach ($counts as $outcome => $count) {
            $parts[] = $outcome . ' ' . $count;
        }
        $this->logger->debug('Two pending check run (' . $trigger . '): checked ' . array_sum($counts) . ($parts === [] ? '' : ' (' . implode(', ', $parts) . ')') . ', skipped ' . $skipped . ' held by another request.');
    }

    /** Whether the Diagnostics tab warns: orders are waiting and the token URL has not run within WARN_AFTER. */
    public static function scheduleStale(int $waiting, int $lastRun, int $now): bool
    {
        return $waiting > 0 && ($lastRun <= 0 || $now - $lastRun > self::WARN_AFTER);
    }
}
