<?php

namespace Henzeb\Saga\Drivers;

use DateTimeInterface;
use Henzeb\Saga\Contracts\Driver;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\DTO\SagaStepRecords;
use Henzeb\Saga\DTO\SagaTrail;
use Henzeb\Saga\Enums\SagaStepStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use stdClass;

class DatabaseDriver implements Driver
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table = 'saga_steps',
    ) {}

    public function store(SagaStepRecord $record): void
    {
        $this->connection->table($this->table)->insert([
            'saga_id' => $record->sagaId,
            'step' => $record->step,
            'branch' => $record->branch,
            'status' => $record->status->value,
            'workflow' => $record->workflow,
            'payload' => $record->payload !== null ? json_encode($record->payload) : null,
            'reason' => $record->reason,
            'recorded_at' => $record->recordedAt ?? now(),
            'encrypted' => $record->encrypted,
            'signal' => $record->signal,
            'signal_expires_at' => $record->signalExpiresAt,
            'running_expires_at' => $record->runningExpiresAt,
        ]);
    }

    public function delete(string $sagaId): void
    {
        $this->connection->table($this->table)->where('saga_id', $sagaId)->delete();
    }

    public function prune(SagaStepStatus $status, ?DateTimeInterface $before = null, bool $dryRun = false, ?string $workflow = null): int
    {
        $latestIds = $this->connection->table($this->table)
            ->selectRaw('MAX(id) as id')
            ->groupBy('saga_id');

        $sagaIds = $this->connection->table($this->table)
            ->joinSub($latestIds, 'latest', $this->table.'.id', '=', 'latest.id')
            ->where('status', $status->value)
            ->when($before, fn ($query) => $query->where('recorded_at', '<', $before))
            ->when($workflow, fn ($query) => $query->where('workflow', $workflow))
            ->pluck('saga_id');

        if ($sagaIds->isEmpty() || $dryRun) {
            return $sagaIds->count();
        }

        $this->connection->table($this->table)->whereIn('saga_id', $sagaIds)->delete();

        return $sagaIds->count();
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueSignals(?DateTimeInterface $before = null): SagaStepRecords
    {
        $before ??= now();

        $latestIds = $this->connection->table($this->table)
            ->selectRaw('MAX(id) as id')
            ->groupBy('saga_id');

        return new SagaStepRecords($this->connection->table($this->table)
            ->joinSub($latestIds, 'latest', $this->table.'.id', '=', 'latest.id')
            ->select($this->table.'.*')
            ->whereIn('status', [SagaStepStatus::Waiting->value, SagaStepStatus::CompensationWaiting->value])
            ->whereNotNull('signal_expires_at')
            ->where('signal_expires_at', '<=', $before)
            ->get()
            ->map($this->toRecord(...)));
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueRunning(?DateTimeInterface $before = null): SagaStepRecords
    {
        $before ??= now();

        $latestIds = $this->connection->table($this->table)
            ->selectRaw('MAX(id) as id')
            ->groupBy('saga_id');

        return new SagaStepRecords($this->connection->table($this->table)
            ->joinSub($latestIds, 'latest', $this->table.'.id', '=', 'latest.id')
            ->select($this->table.'.*')
            ->whereIn('status', [SagaStepStatus::Running->value, SagaStepStatus::Compensating->value])
            ->whereNotNull('running_expires_at')
            ->where('running_expires_at', '<=', $before)
            ->get()
            ->map($this->toRecord(...)));
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function active(?string $workflow = null): SagaStepRecords
    {
        return new SagaStepRecords($this->latestRecords()
            ->whereNotIn('status', [
                SagaStepStatus::Completed->value,
                SagaStepStatus::RolledBack->value,
            ])
            ->when($workflow, fn ($query) => $query->where('workflow', $workflow))
            ->get()
            ->map($this->toRecord(...)));
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function retryable(?string $workflow = null): SagaStepRecords
    {
        return new SagaStepRecords($this->latestRecords()
            ->whereIn('status', [
                SagaStepStatus::RolledBack->value,
                SagaStepStatus::CompensationFailed->value,
            ])
            ->when($workflow, fn ($query) => $query->where('workflow', $workflow))
            ->get()
            ->map($this->toRecord(...)));
    }

    protected function latestRecords(): Builder
    {
        $latestIds = $this->connection->table($this->table)
            ->selectRaw('MAX(id) as id')
            ->groupBy('saga_id');

        return $this->connection->table($this->table)
            ->joinSub($latestIds, 'latest', $this->table.'.id', '=', 'latest.id')
            ->select($this->table.'.*');
    }

    /** @return SagaTrail<int, SagaStepRecord> */
    public function get(string $sagaId): SagaTrail
    {
        return new SagaTrail($this->connection->table($this->table)
            ->where('saga_id', $sagaId)
            ->orderBy('id')
            ->get()
            ->map($this->toRecord(...)));
    }

    public function latest(string $sagaId): ?SagaStepRecord
    {
        $row = $this->connection->table($this->table)
            ->where('saga_id', $sagaId)
            ->orderByDesc('id')
            ->first();

        return $row ? $this->toRecord($row) : null;
    }

    public function latestFor(string $sagaId, int $step, int $branch = 0): ?SagaStepRecord
    {
        $row = $this->connection->table($this->table)
            ->where('saga_id', $sagaId)
            ->where('step', $step)
            ->where('branch', $branch)
            ->orderByDesc('id')
            ->first();

        return $row ? $this->toRecord($row) : null;
    }

    protected function toRecord(stdClass $row): SagaStepRecord
    {
        return new SagaStepRecord(
            sagaId: $row->saga_id,
            step: (int) $row->step,
            status: SagaStepStatus::from($row->status),
            branch: (int) ($row->branch ?? 0),
            workflow: $row->workflow ?? null,
            payload: $row->payload !== null ? json_decode($row->payload, true) : null,
            reason: $row->reason,
            recordedAt: Carbon::parse($row->recorded_at)->toImmutable(),
            encrypted: (bool) $row->encrypted,
            signal: $row->signal ?? null,
            signalExpiresAt: isset($row->signal_expires_at) && $row->signal_expires_at !== null
                ? Carbon::parse($row->signal_expires_at)->toImmutable()
                : null,
            runningExpiresAt: isset($row->running_expires_at) && $row->running_expires_at !== null
                ? Carbon::parse($row->running_expires_at)->toImmutable()
                : null,
        );
    }
}
