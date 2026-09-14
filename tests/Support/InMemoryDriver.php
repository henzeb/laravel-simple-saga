<?php

namespace Tests\Support;

use DateTimeInterface;
use Henzeb\Saga\Contracts\Driver;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\DTO\SagaStepRecords;
use Henzeb\Saga\DTO\SagaTrail;
use Henzeb\Saga\Enums\SagaStepStatus;

class InMemoryDriver implements Driver
{
    /** @var SagaStepRecord[] */
    public array $records = [];

    public function store(SagaStepRecord $record): void
    {
        $this->records[] = $record;
    }

    /** @return SagaTrail<int, SagaStepRecord> */
    public function get(string $sagaId): SagaTrail
    {
        return new SagaTrail(array_values(array_filter(
            $this->records,
            fn (SagaStepRecord $record) => $record->sagaId === $sagaId,
        )));
    }

    public function latest(string $sagaId): ?SagaStepRecord
    {
        $records = $this->get($sagaId);

        return $records->isEmpty() ? null : $records->last();
    }

    public function latestFor(string $sagaId, int $step, int $branch = 0): ?SagaStepRecord
    {
        $records = $this->get($sagaId)->filter(
            fn (SagaStepRecord $record) => $record->step === $step && $record->branch === $branch,
        );

        return $records->isEmpty() ? null : $records->last();
    }

    public function delete(string $sagaId): void
    {
        $this->records = array_values(array_filter(
            $this->records,
            fn (SagaStepRecord $record) => $record->sagaId !== $sagaId,
        ));
    }

    protected function latestPerSaga(): array
    {
        $latest = [];

        foreach ($this->records as $record) {
            $latest[$record->sagaId] = $record;
        }

        return array_values($latest);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function active(?string $workflow = null): SagaStepRecords
    {
        return new SagaStepRecords(array_values(array_filter(
            $this->latestPerSaga(),
            fn (SagaStepRecord $record) => ! in_array($record->status, [SagaStepStatus::Completed, SagaStepStatus::RolledBack], true)
                && ($workflow === null || $record->workflow === $workflow),
        )));
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function retryable(?string $workflow = null): SagaStepRecords
    {
        return new SagaStepRecords(array_values(array_filter(
            $this->latestPerSaga(),
            fn (SagaStepRecord $record) => in_array($record->status, [SagaStepStatus::RolledBack, SagaStepStatus::CompensationFailed], true)
                && ($workflow === null || $record->workflow === $workflow),
        )));
    }

    public function prune(SagaStepStatus $status, ?DateTimeInterface $before = null, bool $dryRun = false, ?string $workflow = null): int
    {
        $sagaIds = array_map(
            fn (SagaStepRecord $record) => $record->sagaId,
            array_filter(
                $this->latestPerSaga(),
                fn (SagaStepRecord $record) => $record->status === $status
                    && ($before === null || ($record->recordedAt !== null && $record->recordedAt < $before))
                    && ($workflow === null || $record->workflow === $workflow),
            )
        );

        if (! $dryRun) {
            foreach ($sagaIds as $sagaId) {
                $this->delete($sagaId);
            }
        }

        return count($sagaIds);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueSignals(?DateTimeInterface $before = null): SagaStepRecords
    {
        $before ??= now();

        return new SagaStepRecords(array_values(array_filter(
            $this->latestPerSaga(),
            fn (SagaStepRecord $record) => in_array($record->status, [SagaStepStatus::Waiting, SagaStepStatus::CompensationWaiting], true)
                && $record->signalExpiresAt !== null && $record->signalExpiresAt <= $before,
        )));
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueRunning(?DateTimeInterface $before = null): SagaStepRecords
    {
        $before ??= now();

        return new SagaStepRecords(array_values(array_filter(
            $this->latestPerSaga(),
            fn (SagaStepRecord $record) => in_array($record->status, [SagaStepStatus::Running, SagaStepStatus::Compensating], true)
                && $record->runningExpiresAt !== null && $record->runningExpiresAt <= $before,
        )));
    }
}
