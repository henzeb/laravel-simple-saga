<?php

namespace Henzeb\Saga\Drivers;

use DateTimeInterface;
use Henzeb\Saga\Contracts\Driver;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\DTO\SagaStepRecords;
use Henzeb\Saga\DTO\SagaTrail;
use Henzeb\Saga\Enums\SagaStepStatus;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CacheDriver implements Driver
{
    public function __construct(
        protected Repository $cache,
        protected ?int $ttl = null,
    ) {}

    public function store(SagaStepRecord $record): void
    {
        Cache::lock("saga-store:{$record->sagaId}", 10)->block(5, function () use ($record) {
            $trail = $this->trailFor($record->sagaId);
            $previousStatus = $trail === [] ? null : SagaStepStatus::from(end($trail)['status']);

            $trail[] = $this->serialize($record);

            $this->ttl === null
                ? $this->cache->forever($this->key($record->sagaId), $trail)
                : $this->cache->put($this->key($record->sagaId), $trail, $this->ttl);

            if ($previousStatus !== $record->status) {
                if ($previousStatus !== null) {
                    $this->removeFromIndex($previousStatus, $record->sagaId);
                }
                $this->addToIndex($record->status, $record->sagaId);
            }
        });
    }

    /** @return SagaTrail<int, SagaStepRecord> */
    public function get(string $sagaId): SagaTrail
    {
        return new SagaTrail(array_map(fn (array $row) => $this->deserialize($row, $sagaId), $this->trailFor($sagaId)));
    }

    public function latest(string $sagaId): ?SagaStepRecord
    {
        $trail = $this->trailFor($sagaId);
        $last = end($trail);

        return $last === false ? null : $this->deserialize($last, $sagaId);
    }

    public function latestFor(string $sagaId, int $step, int $branch = 0): ?SagaStepRecord
    {
        foreach (array_reverse($this->trailFor($sagaId)) as $row) {
            if ($row['step'] === $step && ($row['branch'] ?? 0) === $branch) {
                return $this->deserialize($row, $sagaId);
            }
        }

        return null;
    }

    public function delete(string $sagaId): void
    {
        $last = $this->latest($sagaId);

        $this->cache->forget($this->key($sagaId));

        if ($last !== null) {
            $this->removeFromIndex($last->status, $sagaId);

            return;
        }

        // The trail itself may already be gone (TTL-expired) while the sagaId
        // is still listed in whichever status index it last belonged to — we
        // no longer know which one, so clear it out of all of them.
        foreach (SagaStepStatus::cases() as $status) {
            $this->removeFromIndex($status, $sagaId);
        }
    }

    public function prune(SagaStepStatus $status, ?DateTimeInterface $before = null, bool $dryRun = false, ?string $workflow = null): int
    {
        $pruned = 0;

        /** @var string[] $sagaIds */
        $sagaIds = $this->cache->get($this->indexKey($status), []);

        foreach ($sagaIds as $sagaId) {
            $last = $this->latest($sagaId);

            if ($before !== null && ($last?->recordedAt === null || $last->recordedAt > $before)) {
                continue;
            }

            if ($workflow !== null && $last?->workflow !== $workflow) {
                continue;
            }

            if (! $dryRun) {
                $this->delete($sagaId);
            }

            $pruned++;
        }

        return $pruned;
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueSignals(?DateTimeInterface $before = null): SagaStepRecords
    {
        $before ??= now();
        $due = [];

        foreach ([SagaStepStatus::Waiting, SagaStepStatus::CompensationWaiting] as $status) {
            /** @var string[] $sagaIds */
            $sagaIds = $this->cache->get($this->indexKey($status), []);

            foreach ($sagaIds as $sagaId) {
                $latest = $this->latest($sagaId);

                if ($latest?->signalExpiresAt !== null && $latest->signalExpiresAt <= $before) {
                    $due[] = $latest;
                }
            }
        }

        return new SagaStepRecords($due);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueRunning(?DateTimeInterface $before = null): SagaStepRecords
    {
        $before ??= now();
        $due = [];

        foreach ([SagaStepStatus::Running, SagaStepStatus::Compensating] as $status) {
            /** @var string[] $sagaIds */
            $sagaIds = $this->cache->get($this->indexKey($status), []);

            foreach ($sagaIds as $sagaId) {
                $latest = $this->latest($sagaId);

                if ($latest?->runningExpiresAt !== null && $latest->runningExpiresAt <= $before) {
                    $due[] = $latest;
                }
            }
        }

        return new SagaStepRecords($due);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function active(?string $workflow = null): SagaStepRecords
    {
        $terminal = [
            SagaStepStatus::Completed,
            SagaStepStatus::RolledBack,
        ];

        $statuses = array_filter(
            SagaStepStatus::cases(),
            fn (SagaStepStatus $status) => ! in_array($status, $terminal, true)
        );

        return new SagaStepRecords($this->latestForStatuses($statuses, $workflow));
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function retryable(?string $workflow = null): SagaStepRecords
    {
        return new SagaStepRecords($this->latestForStatuses(
            [SagaStepStatus::RolledBack, SagaStepStatus::CompensationFailed],
            $workflow
        ));
    }

    /**
     * @param SagaStepStatus[] $statuses
     * @return SagaStepRecord[]
     */
    protected function latestForStatuses(array $statuses, ?string $workflow = null): array
    {
        $records = [];

        foreach ($statuses as $status) {
            /** @var string[] $sagaIds */
            $sagaIds = $this->cache->get($this->indexKey($status), []);

            foreach ($sagaIds as $sagaId) {
                $latest = $this->latest($sagaId);

                if ($latest !== null && ($workflow === null || $latest->workflow === $workflow)) {
                    $records[] = $latest;
                }
            }
        }

        return $records;
    }

    protected function addToIndex(SagaStepStatus $status, string $sagaId): void
    {
        Cache::lock("saga-index-lock:{$status->value}", 10)->block(5, function () use ($status, $sagaId) {
            /** @var string[] $ids */
            $ids = $this->cache->get($this->indexKey($status), []);

            if (! in_array($sagaId, $ids, true)) {
                $ids[] = $sagaId;
                $this->cache->forever($this->indexKey($status), $ids);
            }
        });
    }

    protected function removeFromIndex(SagaStepStatus $status, string $sagaId): void
    {
        Cache::lock("saga-index-lock:{$status->value}", 10)->block(5, function () use ($status, $sagaId) {
            /** @var string[] $ids */
            $ids = $this->cache->get($this->indexKey($status), []);
            $ids = array_values(array_diff($ids, [$sagaId]));
            $this->cache->forever($this->indexKey($status), $ids);
        });
    }

    /**
     * @return array{step: int, branch: int, status: string, workflow: string|null, payload: mixed, reason: string|null, recorded_at: string, encrypted: bool, signal: string|null, signal_expires_at: string|null, running_expires_at: string|null}
     */
    protected function serialize(SagaStepRecord $record): array
    {
        return [
            'step' => $record->step,
            'branch' => $record->branch,
            'status' => $record->status->value,
            'workflow' => $record->workflow,
            'payload' => $record->payload,
            'reason' => $record->reason,
            'recorded_at' => ($record->recordedAt ?? now())->format(DateTimeInterface::ATOM),
            'encrypted' => $record->encrypted,
            'signal' => $record->signal,
            'signal_expires_at' => $record->signalExpiresAt?->format(DateTimeInterface::ATOM),
            'running_expires_at' => $record->runningExpiresAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array{step: int, branch?: int, status: string, workflow?: string|null, payload: mixed, reason: string|null, recorded_at: string, encrypted?: bool, signal?: string|null, signal_expires_at?: string|null, running_expires_at?: string|null} $row
     */
    protected function deserialize(array $row, string $sagaId): SagaStepRecord
    {
        return new SagaStepRecord(
            sagaId: $sagaId,
            step: $row['step'],
            status: SagaStepStatus::from($row['status']),
            branch: $row['branch'] ?? 0,
            workflow: $row['workflow'] ?? null,
            payload: $row['payload'],
            reason: $row['reason'],
            recordedAt: Carbon::parse($row['recorded_at'])->toImmutable(),
            encrypted: $row['encrypted'] ?? false,
            signal: $row['signal'] ?? null,
            signalExpiresAt: isset($row['signal_expires_at']) ? Carbon::parse($row['signal_expires_at'])->toImmutable() : null,
            runningExpiresAt: isset($row['running_expires_at']) ? Carbon::parse($row['running_expires_at'])->toImmutable() : null,
        );
    }

    /**
     * @return array<int, array{step: int, branch?: int, status: string, workflow: string|null, payload: mixed, reason: string|null, recorded_at: string, encrypted: bool, signal: string|null, signal_expires_at: string|null, running_expires_at: string|null}>
     */
    protected function trailFor(string $sagaId): array
    {
        /** @var array<int, array{step: int, branch?: int, status: string, workflow: string|null, payload: mixed, reason: string|null, recorded_at: string, encrypted: bool, signal: string|null, signal_expires_at: string|null, running_expires_at: string|null}> $trail */
        $trail = $this->cache->get($this->key($sagaId), []);

        return $trail;
    }

    protected function indexKey(SagaStepStatus $status): string
    {
        return "saga-index:{$status->value}";
    }

    protected function key(string $sagaId): string
    {
        return "saga:{$sagaId}";
    }
}
