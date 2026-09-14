<?php

namespace Henzeb\Saga\Contracts;

use DateTimeInterface;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\DTO\SagaStepRecords;
use Henzeb\Saga\DTO\SagaTrail;
use Henzeb\Saga\Enums\SagaStepStatus;

interface Driver
{
    public function store(SagaStepRecord $record): void;

    /** @return SagaTrail<int, SagaStepRecord> */
    public function get(string $sagaId): SagaTrail;

    public function latest(string $sagaId): ?SagaStepRecord;

    public function latestFor(string $sagaId, int $step, int $branch = 0): ?SagaStepRecord;

    public function delete(string $sagaId): void;

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function active(?string $workflow = null): SagaStepRecords;

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function retryable(?string $workflow = null): SagaStepRecords;

    public function prune(SagaStepStatus $status, ?DateTimeInterface $before = null, bool $dryRun = false, ?string $workflow = null): int;

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueSignals(?DateTimeInterface $before = null): SagaStepRecords;

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueRunning(?DateTimeInterface $before = null): SagaStepRecords;
}
