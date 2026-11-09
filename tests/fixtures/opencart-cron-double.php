<?php

declare(strict_types=1);

namespace Opencart\Admin\Model\Setting;

/**
 * Offline stand-in for OpenCart 4.1's admin `setting/cron` model over an
 * in-memory cron table, with the real model's public signatures (rows keyed
 * by cron_id, getCronByCode() answering the row or []). Loaded by
 * tests/bootstrap.php only.
 */
final class Cron
{
    /** @var array<int, array{cron_id: int, code: string, description: string, cycle: string, action: string, status: bool}> */
    public array $rows = [];

    private int $nextId = 1;

    public function addCron(string $code, string $description, string $cycle, string $action, bool $status): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = ['cron_id' => $id, 'code' => $code, 'description' => $description, 'cycle' => $cycle, 'action' => $action, 'status' => $status];

        return $id;
    }

    public function deleteCron(int $cron_id): void
    {
        unset($this->rows[$cron_id]);
    }

    public function deleteCronByCode(string $code): void
    {
        $this->rows = array_filter($this->rows, static fn (array $row): bool => $row['code'] !== $code);
    }

    public function editCron(int $cron_id): void
    {
    }

    public function editStatus(int $cron_id, bool $status): void
    {
        if (isset($this->rows[$cron_id])) {
            $this->rows[$cron_id]['status'] = $status;
        }
    }

    /** @return array<string, mixed> */
    public function getCron(int $cron_id): array
    {
        return $this->rows[$cron_id] ?? [];
    }

    /** @return array<string, mixed> */
    public function getCronByCode(string $code): array
    {
        foreach ($this->rows as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public function getCrons(array $data = []): array
    {
        return array_values($this->rows);
    }

    public function getTotalCrons(): int
    {
        return count($this->rows);
    }
}
