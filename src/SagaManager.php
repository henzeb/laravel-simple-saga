<?php

namespace Henzeb\Saga;

use DateTimeInterface;
use Henzeb\Saga\Contracts\Driver;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\DTO\SagaStepRecords;
use Henzeb\Saga\Drivers\CacheDriver;
use Henzeb\Saga\Drivers\DatabaseDriver;
use Henzeb\Saga\Enums\SagaStepStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Manager;
use LogicException;

class SagaManager extends Manager
{
    public function getDefaultDriver(): string
    {
        /** @var string $default */
        $default = $this->config->get('saga.default', 'database');

        return $default;
    }

    protected function createDatabaseDriver(): DatabaseDriver
    {
        /** @var string|null $connection */
        $connection = $this->config->get('saga.drivers.database.connection');
        /** @var string $table */
        $table = $this->config->get('saga.drivers.database.table', 'saga_steps');

        return new DatabaseDriver(DB::connection($connection), $table);
    }

    protected function createCacheDriver(): CacheDriver
    {
        /** @var string|null $store */
        $store = $this->config->get('saga.drivers.cache.store');
        /** @var int|null $ttl */
        $ttl = $this->config->get('saga.drivers.cache.ttl');

        return new CacheDriver(Cache::store($store), $ttl);
    }

    /**
     * @param Workflow|class-string<Workflow> $workflow
     */
    public function workflow(Workflow|string $workflow, ?string $sagaId = null): SagaWorkflow
    {
        return new SagaWorkflow(
            $this->coordinator(),
            is_string($workflow) ? app(WorkflowResolver::class)->resolve($workflow) : $workflow,
            $sagaId,
        );
    }

    public function coordinator(): SagaCoordinator
    {
        $driver = $this->driver();

        if (! $driver instanceof Driver) {
            throw new LogicException(get_debug_type($driver).' must implement '.Driver::class.'.');
        }

        return new SagaCoordinator($driver);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function active(?string $workflow = null): SagaStepRecords
    {
        return $this->coordinator()->active($workflow);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function retryable(?string $workflow = null): SagaStepRecords
    {
        return $this->coordinator()->retryable($workflow);
    }

    public function prune(SagaStepStatus $status, ?DateTimeInterface $before = null, bool $dryRun = false, ?string $workflow = null): int
    {
        return $this->coordinator()->prune($status, $before, $dryRun, $workflow);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function due(?DateTimeInterface $before = null): SagaStepRecords
    {
        return $this->coordinator()->due($before);
    }

    public function sweepSignals(?DateTimeInterface $before = null, bool $sync = false): int
    {
        return $this->coordinator()->sweepSignals($before, $sync);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueRunning(?DateTimeInterface $before = null): SagaStepRecords
    {
        return $this->coordinator()->dueRunning($before);
    }

    public function sweepStale(?DateTimeInterface $before = null, bool $sync = false): int
    {
        return $this->coordinator()->sweepStale($before, $sync);
    }
}
