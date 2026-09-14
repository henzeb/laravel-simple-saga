<?php

namespace Henzeb\Saga;

use DateTimeImmutable;
use DateTimeInterface;
use Henzeb\Saga\Attributes\CompensatedBy;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Contracts\Driver;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\DTO\SagaStepRecords;
use Henzeb\Saga\DTO\SagaTrail;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Events\SagaCompensated;
use Henzeb\Saga\Events\SagaCompensating;
use Henzeb\Saga\Events\SagaCompensationFailed;
use Henzeb\Saga\Events\SagaCompleted;
use Henzeb\Saga\Events\SagaRolledBack;
use Henzeb\Saga\Events\SagaStarted;
use Henzeb\Saga\Events\SagaStepAwaitingSignal;
use Henzeb\Saga\Events\SagaStepCompensated;
use Henzeb\Saga\Events\SagaStepCompensating;
use Henzeb\Saga\Events\SagaStepCompleted;
use Henzeb\Saga\Events\SagaStepFailed;
use Henzeb\Saga\Events\SagaStepIterated;
use Henzeb\Saga\Events\SagaStepStarted;
use Henzeb\Saga\Exceptions\SagaInterruptedException;
use Henzeb\Saga\Exceptions\SagaNotAwaitingSignalException;
use Henzeb\Saga\Exceptions\SagaNotFoundException;
use Henzeb\Saga\Exceptions\SagaNotRetryableException;
use Henzeb\Saga\Exceptions\SignalTimeoutException;
use Henzeb\Saga\Exceptions\StaleSagaStepException;
use Henzeb\Saga\Middleware\ProcessesSaga;
use Henzeb\Saga\Parallel;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Uid\Ulid;
use Throwable;

class SagaCoordinator
{
    use SerializesAndRestoresModelIdentifiers;

    // Shared across instances on purpose: SagaManager::coordinator() hands out a
    // fresh SagaCoordinator per call, so the middleware's nested dispatch() calls
    // (running deeper on the very same PHP call stack) never see the instance that
    // installed the handler. Only the outermost dispatch() — the one that finds it
    // not yet installed — ever installs or removes it.
    protected static bool $interruptHandlerInstalled = false;

    protected static ?string $interruptedSagaId = null;

    protected static bool $previousAsyncSignals = false;

    public function __construct(
        protected Driver $driver,
        protected WorkflowValidator $validator = new WorkflowValidator(),
        protected WorkflowResolver $resolver = new WorkflowResolver(),
    ) {}

    /**
     * @param Workflow|class-string<Workflow> $workflow
     */
    public function start(Workflow|string $workflow, mixed $context, bool $sync = false, ?string $sagaId = null): SagaState
    {
        if ($sagaId === null) {
            return $this->beginSaga((string) Str::ulid(), $workflow, $context, $sync);
        }

        $sagaId = $this->sagaIdFor($workflow, $sagaId);

        /** @var SagaState $state */
        $state = Cache::lock("saga:idempotency:{$sagaId}", 10)->block(5, function () use ($sagaId, $workflow, $context, $sync) {
            $existing = $this->driver->latest($sagaId);

            return $existing !== null
                ? $this->stateFromRecord($existing)
                : $this->beginSaga($sagaId, $workflow, $context, $sync);
        });

        return $state;
    }

    /**
     * @param Workflow|class-string<Workflow> $workflow
     */
    public function sagaIdFor(Workflow|string $workflow, string $idempotencyKey): string
    {
        if (Ulid::isValid($idempotencyKey)) {
            return $idempotencyKey;
        }

        $hash = hash('sha256', (is_string($workflow) ? $workflow : $workflow::class).'|'.$idempotencyKey, binary: true);

        return Ulid::fromBinary(substr($hash, 0, 16))->toBase32();
    }

    /**
     * @param Workflow|class-string<Workflow> $workflow
     */
    public function retry(string $sagaId, Workflow|string $workflow, mixed $context = null, bool $sync = false): SagaState
    {
        $latest = $this->driver->latest($sagaId) ?? throw new SagaNotFoundException($sagaId);

        if ($latest->status !== SagaStepStatus::RolledBack) {
            throw new SagaNotRetryableException($sagaId, $latest->status);
        }

        return $this->beginSaga($sagaId, $workflow, $context ?? $latest->context()->raw(), $sync);
    }

    /**
     * @param Workflow|class-string<Workflow> $workflow
     */
    public function retryCompensation(string $sagaId, Workflow|string $workflow, bool $sync = false): SagaState
    {
        $workflow = is_string($workflow) ? $this->resolver->resolve($workflow) : $workflow;

        $this->validator->validate($workflow);

        $record = $this->driver->latest($sagaId) ?? throw new SagaNotFoundException($sagaId);

        if ($record->status !== SagaStepStatus::CompensationFailed) {
            throw new SagaNotRetryableException($sagaId, $record->status);
        }

        $steps = $workflow->steps();

        foreach ($this->latestPerBranch($sagaId) as $stuck) {
            if ($stuck->status !== SagaStepStatus::CompensationFailed) {
                continue;
            }

            $compensator = $this->resolveCompensator($this->stepClassFor($steps, $stuck->step, $stuck->branch));

            if ($compensator === null) {
                $workflowClass = $workflow::class;

                throw new LogicException("Step {$stuck->step} of workflow {$workflowClass} is CompensationFailed but has no compensator to retry.");
            }

            $this->driver->store(new SagaStepRecord(
                $sagaId, $stuck->step, SagaStepStatus::CompensationPending, branch: $stuck->branch, workflow: $workflow::class, payload: $stuck->payload, encrypted: $stuck->encrypted
            ));

            $this->dispatch($this->build($compensator, $sagaId, $workflow::class, $stuck->step, $sync, $stuck->branch), $sync);
        }

        return $this->current($sagaId);
    }

    public function signal(string $sagaId, string $name, mixed $payload = null, bool $sync = false): SagaState
    {
        $this->driver->latest($sagaId) ?? throw new SagaNotFoundException($sagaId);

        $record = null;

        foreach ($this->latestPerBranch($sagaId) as $candidate) {
            if ($candidate->signal === $name
                && in_array($candidate->status, [SagaStepStatus::Waiting, SagaStepStatus::CompensationWaiting], true)) {
                $record = $candidate;
                break;
            }
        }

        if ($record === null) {
            throw new SagaNotAwaitingSignalException($sagaId, $name);
        }

        if ($record->workflow === null) {
            throw new LogicException("Saga {$sagaId} step {$record->step} has no recorded workflow.");
        }

        $compensating = $record->status === SagaStepStatus::CompensationWaiting;
        $steps = $this->resolver->resolve($record->workflow)->steps();
        $class = $this->stepClassFor($steps, $record->step, $record->branch);
        $jobClass = $compensating ? $this->resolveCompensator($class) : $class;

        if ($jobClass === null) {
            throw new LogicException("Step {$record->step} of workflow {$record->workflow} is CompensationWaiting but has no compensator to resume.");
        }

        $status = $compensating ? SagaStepStatus::CompensationPending : SagaStepStatus::Pending;

        // The step's own context is untouched — only its status moves on. The
        // signal's payload never joins the trail; it only ever reaches the job
        // directly, via waitFor().
        $this->driver->store(new SagaStepRecord(
            $sagaId, $record->step, $status, branch: $record->branch, workflow: $record->workflow,
            payload: $record->payload, encrypted: $record->encrypted
        ));

        $job = $this->build($jobClass, $sagaId, $record->workflow, $record->step, $sync, $record->branch);
        $this->interactsWithSaga($job);
        $job->deliveredSignal = $name;
        $job->deliveredSignalPayload = $payload;

        $this->dispatch($job, $sync);

        return $this->current($sagaId);
    }

    /**
     * @return array<string, SagaStepRecord>
     */
    protected function latestPerBranch(string $sagaId): array
    {
        $latest = [];

        foreach ($this->driver->get($sagaId) as $record) {
            $latest["{$record->step}:{$record->branch}"] = $record;
        }

        return $latest;
    }

    /**
     * @param Workflow|class-string<Workflow> $workflow
     */
    protected function beginSaga(string $sagaId, Workflow|string $workflow, mixed $context, bool $sync): SagaState
    {
        $workflow = is_string($workflow) ? $this->resolver->resolve($workflow) : $workflow;

        $this->validator->validate($workflow);

        $encrypt = $workflow->encryptContext();
        $payload = $this->encode($context, $encrypt);
        $steps = $workflow->steps();

        $this->storeStepEntry($sagaId, $workflow::class, $steps, 0, $payload, $encrypt);

        $record = $this->driver->latestFor($sagaId, 0, 0);

        if ($record === null) {
            throw new LogicException("Saga {$sagaId} step 0 was not found immediately after being stored.");
        }

        $this->event(SagaStarted::class, $record);

        $this->dispatchStepEntry($sagaId, $workflow::class, $steps, 0, $sync);

        return $this->current($sagaId);
    }

    public function current(string $sagaId): SagaState
    {
        $last = $this->driver->latest($sagaId) ?? throw new SagaNotFoundException($sagaId);

        return $this->stateFromRecord($last);
    }

    public function compensate(string $sagaId, bool $sync = false): SagaState
    {
        $record = $this->driver->latest($sagaId) ?? throw new SagaNotFoundException($sagaId);

        if ($record->workflow === null) {
            throw new LogicException("Saga {$sagaId} has no recorded workflow.");
        }

        if (in_array($record->status, [
            SagaStepStatus::Pending, SagaStepStatus::Running, SagaStepStatus::Waiting,
        ], true)) {
            $this->stepFailed(
                $sagaId, $record->workflow, $record->step,
                new RuntimeException('Compensation started manually.'),
                sync: $sync
            );
        } elseif ($record->status === SagaStepStatus::Completed) {
            $this->event(SagaCompensating::class, $record);
            $this->beginCompensation($sagaId, $record->workflow, $record->step, $sync);
        }

        return $this->current($sagaId);
    }

    public function delete(string $sagaId): void
    {
        $this->driver->delete($sagaId);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function active(?string $workflow = null): SagaStepRecords
    {
        return $this->driver->active($workflow);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function retryable(?string $workflow = null): SagaStepRecords
    {
        return $this->driver->retryable($workflow);
    }

    public function prune(SagaStepStatus $status, ?DateTimeInterface $before = null, bool $dryRun = false, ?string $workflow = null): int
    {
        return $this->driver->prune($status, $before, $dryRun, $workflow);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function due(?DateTimeInterface $before = null): SagaStepRecords
    {
        return $this->driver->dueSignals($before);
    }

    public function sweepSignals(?DateTimeInterface $before = null, bool $sync = false): int
    {
        return $this->failDueSignals($this->due($before), $sync);
    }

    /** @return SagaStepRecords<int, SagaStepRecord> */
    public function dueRunning(?DateTimeInterface $before = null): SagaStepRecords
    {
        return $this->driver->dueRunning($before);
    }

    public function sweepStale(?DateTimeInterface $before = null, bool $sync = false): int
    {
        return $this->failDueRunning($this->dueRunning($before), $sync);
    }

    protected function event(string $eventClass, SagaStepRecord $record, mixed ...$extra): void
    {
        app(EventDispatcher::class)->dispatch(new $eventClass($this->stateFromRecord($record), ...$extra));
    }

    protected function storeAndDispatch(SagaStepRecord $record, string $eventClass, mixed ...$extra): SagaStepRecord
    {
        $this->driver->store($record);
        $this->event($eventClass, $record, ...$extra);

        return $record;
    }

    protected function stateFromRecord(SagaStepRecord $record, ?int $before = null): SagaState
    {
        return new SagaState(
            sagaId: $record->sagaId,
            workflow: $record->workflow,
            status: $record->status,
            step: $record->step,
            // Deferred to its own driver call, made only if context() is actually called —
            // by step rather than by this exact record, so an older trail entry for a step
            // that later changed status shows that step's current context, not a frozen one.
            contextResolver: fn () => $this->driver->latestFor($record->sagaId, $record->step)?->context() ?? new SagaContext(null),
            reason: $record->reason,
            trailResolver: fn () => $this->trailBefore($record->sagaId, $before),
            signal: $record->signal,
            signalExpiresAt: $record->signalExpiresAt,
            recordedAt: $record->recordedAt,
            runningExpiresAt: $record->runningExpiresAt,
        );
    }

    /**
     * @return SagaTrail<int, SagaState>
     */
    protected function trailBefore(string $sagaId, ?int $before): SagaTrail
    {
        $records = $this->driver->get($sagaId);

        if ($before !== null) {
            $records = $records->take($before);
        }

        return $records->values()->map(
            fn (SagaStepRecord $record, int $position) => $this->stateFromRecord($record, $position)
        );
    }

    /**
     * @phpstan-assert SagaStep $job
     */
    public function interactsWithSaga(object $job): void
    {
        if (! in_array(InteractsWithSaga::class, class_uses_recursive($job), true)) {
            throw new LogicException($job::class.' must use the '.InteractsWithSaga::class.' concern.');
        }
    }

    public function contextFor(string $sagaId, int $step, int $branch = 0): mixed
    {
        $record = $this->driver->latestFor($sagaId, $step, $branch);

        return $record ? $this->decode($record->payload, $record->encrypted) : null;
    }

    public function isCompensating(string $sagaId, int $step, int $branch = 0): bool
    {
        $status = $this->driver->latestFor($sagaId, $step, $branch)?->status;

        return $status === SagaStepStatus::CompensationPending
            || $status === SagaStepStatus::Compensating
            || $status === SagaStepStatus::CompensationWaiting;
    }

    public function isAlreadyCompleted(string $sagaId, int $step, int $branch = 0): bool
    {
        return $this->driver->latestFor($sagaId, $step, $branch)?->status === SagaStepStatus::Completed;
    }

    public function isStale(string $sagaId, int $step, int $branch = 0): bool
    {
        $status = $this->driver->latestFor($sagaId, $step, $branch)?->status;

        return $status === SagaStepStatus::Running || $status === SagaStepStatus::Compensating;
    }

    public function markRunning(string $sagaId, string $workflow, int $step, int $branch = 0): void
    {
        $previous = $this->driver->latestFor($sagaId, $step, $branch);

        $this->storeAndDispatch(new SagaStepRecord(
            $sagaId, $step, SagaStepStatus::Running, branch: $branch, workflow: $workflow,
            payload: $previous?->payload, encrypted: $previous !== null && $previous->encrypted,
            runningExpiresAt: $this->runningExpiresAt($workflow)
        ), SagaStepStarted::class);
    }

    public function markCompensating(string $sagaId, string $workflow, int $step, int $branch = 0): void
    {
        $previous = $this->driver->latestFor($sagaId, $step, $branch);

        $this->storeAndDispatch(new SagaStepRecord(
            $sagaId, $step, SagaStepStatus::Compensating, branch: $branch, workflow: $workflow,
            payload: $previous?->payload, encrypted: $previous !== null && $previous->encrypted,
            runningExpiresAt: $this->runningExpiresAt($workflow)
        ), SagaStepCompensating::class);
    }

    protected function runningExpiresAt(string $workflow): ?DateTimeImmutable
    {
        /** @var int|null $timeout */
        $timeout = $this->resolver->resolve($workflow)->runningTimeout() ?? config('saga.running_timeout');

        return $timeout !== null ? now()->addSeconds($timeout)->toImmutable() : null;
    }

    public function ensureAdvanced(string $sagaId, string $workflow, int $step, int $branch = 0): void
    {
        $steps = $this->resolver->resolve($workflow)->steps();
        $entry = $steps[$step];

        if ($entry instanceof Parallel) {
            $this->checkGroupForward($sagaId, $workflow, $step, $entry, sync: false);

            return;
        }

        $record = $this->driver->latestFor($sagaId, $step);

        $this->advanceForward($sagaId, $workflow, $step, $record?->payload, $record !== null && $record->encrypted, sync: false);
    }

    public function stepCompleted(
        string $sagaId, string $workflow, int $step, mixed $result,
        bool $compensating = false, bool $sync = false, int $branch = 0
    ): void {
        $encrypt = $this->shouldEncrypt($workflow);
        $payload = $this->encode($result, $encrypt);
        $steps = $this->resolver->resolve($workflow)->steps();
        $entry = $steps[$step];

        if ($compensating) {
            $this->storeAndDispatch(new SagaStepRecord($sagaId, $step, SagaStepStatus::Compensated, branch: $branch, workflow: $workflow, payload: $payload, encrypted: $encrypt), SagaStepCompensated::class);

            if ($entry instanceof Parallel) {
                $this->settleGroupCompensation($sagaId, $workflow, $step, $entry, $sync);

                return;
            }

            $this->beginCompensation($sagaId, $workflow, $step - 1, $sync);

            return;
        }

        $this->storeAndDispatch(new SagaStepRecord($sagaId, $step, SagaStepStatus::Completed, branch: $branch, workflow: $workflow, payload: $payload, encrypted: $encrypt), SagaStepCompleted::class);

        if ($entry instanceof Parallel) {
            $statuses = $this->branchStatuses($sagaId, $step, $entry);

            if ($this->anyFailed($statuses)) {
                if ($entry->isFailFast() || $this->allTerminal($statuses)) {
                    $this->beginCompensationForGroup($sagaId, $workflow, $step, $entry, $sync);
                    $this->settleGroupCompensation($sagaId, $workflow, $step, $entry, $sync);
                }

                return;
            }

            $this->checkGroupForward($sagaId, $workflow, $step, $entry, $sync);

            return;
        }

        $this->advanceForward($sagaId, $workflow, $step, $payload, $encrypt, $sync);
    }

    public function stepIterated(
        string $sagaId, string $workflow, int $step, mixed $context,
        bool $compensating = false, bool $sync = false, int $branch = 0
    ): void {
        $encrypt = $this->shouldEncrypt($workflow);
        $payload = $this->encode($context, $encrypt);
        $status = $compensating ? SagaStepStatus::CompensationPending : SagaStepStatus::Pending;

        $this->storeAndDispatch(new SagaStepRecord($sagaId, $step, $status, branch: $branch, workflow: $workflow, payload: $payload, encrypted: $encrypt), SagaStepIterated::class);

        $steps = $this->resolver->resolve($workflow)->steps();
        $class = $this->stepClassFor($steps, $step, $branch);

        if ($compensating) {
            $compensator = $this->resolveCompensator($class);

            if ($compensator === null) {
                throw new LogicException("Step {$step} of workflow {$workflow} is iterating while compensating but has no compensator.");
            }

            $jobClass = $compensator;
        } else {
            $jobClass = $class;
        }

        $this->dispatch($this->build($jobClass, $sagaId, $workflow, $step, $sync, $branch), $sync);
    }

    public function stepAwaitsSignal(
        string $sagaId, string $workflow, int $step, string $signal, mixed $context,
        bool $compensating = false, int $branch = 0
    ): void {
        $encrypt = $this->shouldEncrypt($workflow);
        $payload = $this->encode($context, $encrypt);
        $status = $compensating ? SagaStepStatus::CompensationWaiting : SagaStepStatus::Waiting;

        /** @var int|null $timeout */
        $timeout = $this->resolver->resolve($workflow)->signalTimeout() ?? config('saga.signal_timeout');
        $expiresAt = $timeout !== null ? now()->addSeconds($timeout)->toImmutable() : null;

        $this->storeAndDispatch(new SagaStepRecord(
            $sagaId, $step, $status, branch: $branch, workflow: $workflow, payload: $payload, encrypted: $encrypt,
            signal: $signal, signalExpiresAt: $expiresAt
        ), SagaStepAwaitingSignal::class);
    }

    /**
     * @param SagaStepRecords<int, SagaStepRecord> $due
     */
    public function failDueSignals(SagaStepRecords $due, bool $sync = false): int
    {
        foreach ($due as $record) {
            if ($record->workflow === null) {
                throw new LogicException("Saga {$record->sagaId} step {$record->step} has no recorded workflow.");
            }

            if ($record->signal === null) {
                throw new LogicException("Saga {$record->sagaId} step {$record->step} is due but has no recorded signal name.");
            }

            $this->stepFailed(
                $record->sagaId, $record->workflow, $record->step,
                new SignalTimeoutException($record->sagaId, $record->step, $record->signal),
                sync: $sync, branch: $record->branch
            );
        }

        return $due->count();
    }

    /**
     * @param SagaStepRecords<int, SagaStepRecord> $due
     */
    public function failDueRunning(SagaStepRecords $due, bool $sync = false): int
    {
        foreach ($due as $record) {
            if ($record->workflow === null) {
                throw new LogicException("Saga {$record->sagaId} step {$record->step} has no recorded workflow.");
            }

            $this->stepFailed(
                $record->sagaId, $record->workflow, $record->step,
                new StaleSagaStepException($record->sagaId, $record->step),
                sync: $sync, branch: $record->branch
            );
        }

        return $due->count();
    }

    public function stepFailed(string $sagaId, string $workflow, int $step, ?Throwable $exception, bool $sync = false, int $branch = 0): void
    {
        $compensating = $this->isCompensating($sagaId, $step, $branch);

        $reason = $exception ? $exception::class.': '.$exception->getMessage() : null;
        $record = $this->driver->latestFor($sagaId, $step, $branch);
        $steps = $this->resolver->resolve($workflow)->steps();
        $entry = $steps[$step];

        if ($compensating) {
            $stored = $this->storeAndDispatch(new SagaStepRecord($sagaId, $step, SagaStepStatus::CompensationFailed, branch: $branch, workflow: $workflow, payload: $record?->payload, reason: $reason, encrypted: $record !== null && $record->encrypted), SagaCompensationFailed::class, $exception);

            if ($entry instanceof Parallel) {
                $this->settleGroupCompensation($sagaId, $workflow, $step, $entry, $sync);
            }

            return;
        }

        $stored = $this->storeAndDispatch(new SagaStepRecord($sagaId, $step, SagaStepStatus::Failed, branch: $branch, workflow: $workflow, payload: $record?->payload, reason: $reason, encrypted: $record !== null && $record->encrypted), SagaStepFailed::class, $exception);

        if ($entry instanceof Parallel) {
            $statuses = $this->branchStatuses($sagaId, $step, $entry);

            if ($entry->isFailFast() || $this->allTerminal($statuses)) {
                $this->event(SagaCompensating::class, $stored);
                $this->beginCompensationForGroup($sagaId, $workflow, $step, $entry, $sync);
                $this->settleGroupCompensation($sagaId, $workflow, $step, $entry, $sync);
            }

            return;
        }

        if ($step > 0) {
            $this->event(SagaCompensating::class, $stored);
            $this->beginCompensation($sagaId, $workflow, $step - 1, $sync);
        }
    }

    /**
     * @param class-string $stepClass
     * @return class-string|null
     */
    protected function resolveCompensator(string $stepClass): ?string
    {
        $attribute = (new ReflectionClass($stepClass))->getAttributes(CompensatedBy::class)[0] ?? null;

        if ($attribute) {
            return $attribute->newInstance()->compensator;
        }

        return is_subclass_of($stepClass, ShouldCompensate::class) ? $stepClass : null;
    }

    protected function advanceForward(string $sagaId, string $workflow, int $step, mixed $payload, bool $encrypted, bool $sync): void
    {
        $steps = $this->resolver->resolve($workflow)->steps();
        $next = $step + 1;

        if (! isset($steps[$next])) {
            $this->event(SagaCompleted::class, new SagaStepRecord($sagaId, $step, SagaStepStatus::Completed, workflow: $workflow));

            return;
        }

        $this->storeStepEntry($sagaId, $workflow, $steps, $next, $payload, $encrypted);
        $this->dispatchStepEntry($sagaId, $workflow, $steps, $next, $sync);
    }

    /**
     * @param array<int, class-string|Parallel> $steps
     * @return class-string
     */
    protected function stepClassFor(array $steps, int $step, int $branch): string
    {
        $entry = $steps[$step];

        return $entry instanceof Parallel ? $entry->steps()[$branch] : $entry;
    }

    /**
     * @param array<int, class-string|Parallel> $steps
     */
    protected function storeStepEntry(string $sagaId, string $workflow, array $steps, int $step, mixed $payload, bool $encrypted): void
    {
        $entry = $steps[$step];

        if (! $entry instanceof Parallel) {
            $this->driver->store(new SagaStepRecord($sagaId, $step, SagaStepStatus::Pending, workflow: $workflow, payload: $payload, encrypted: $encrypted));

            return;
        }

        foreach (array_keys($entry->steps()) as $branch) {
            $this->driver->store(new SagaStepRecord($sagaId, $step, SagaStepStatus::Pending, branch: $branch, workflow: $workflow, payload: $payload, encrypted: $encrypted));
        }
    }

    /**
     * @param array<int, class-string|Parallel> $steps
     */
    protected function dispatchStepEntry(string $sagaId, string $workflow, array $steps, int $step, bool $sync): void
    {
        $entry = $steps[$step];

        if (! $entry instanceof Parallel) {
            $this->dispatch($this->build($entry, $sagaId, $workflow, $step, $sync), $sync);

            return;
        }

        $branchSync = $entry->isQueued() ? false : $sync;

        foreach ($entry->steps() as $branch => $class) {
            $this->dispatch($this->build($class, $sagaId, $workflow, $step, $branchSync, $branch), $branchSync);
        }
    }

    /**
     * @return array<int, ?SagaStepStatus>
     */
    protected function branchStatuses(string $sagaId, int $step, Parallel $entry): array
    {
        $statuses = [];

        foreach (array_keys($entry->steps()) as $branch) {
            $statuses[$branch] = $this->driver->latestFor($sagaId, $step, $branch)?->status;
        }

        return $statuses;
    }

    /**
     * @param array<int, ?SagaStepStatus> $statuses
     * @param list<SagaStepStatus> $allowed
     */
    protected function allIn(array $statuses, array $allowed): bool
    {
        return ! in_array(null, $statuses, true)
            && array_filter($statuses, fn (SagaStepStatus $status) => ! in_array($status, $allowed, true)) === [];
    }

    /**
     * @param array<int, ?SagaStepStatus> $statuses
     */
    protected function allCompleted(array $statuses): bool
    {
        return $this->allIn($statuses, [SagaStepStatus::Completed]);
    }

    /**
     * @param array<int, ?SagaStepStatus> $statuses
     */
    protected function anyFailed(array $statuses): bool
    {
        return in_array(SagaStepStatus::Failed, $statuses, true);
    }

    /**
     * @param array<int, ?SagaStepStatus> $statuses
     */
    protected function allTerminal(array $statuses): bool
    {
        return $this->allIn($statuses, [SagaStepStatus::Completed, SagaStepStatus::Failed]);
    }

    /**
     * @param array<int, ?SagaStepStatus> $statuses
     */
    protected function allCompensationSettled(array $statuses): bool
    {
        return $this->allIn($statuses, [SagaStepStatus::Compensated, SagaStepStatus::Failed, SagaStepStatus::CompensationFailed]);
    }

    /**
     * @param array<int, ?SagaStepStatus> $statuses
     */
    protected function anyCompensationFailed(array $statuses): bool
    {
        return in_array(SagaStepStatus::CompensationFailed, $statuses, true);
    }

    protected function beginCompensationForGroup(string $sagaId, string $workflow, int $step, Parallel $entry, bool $sync): void
    {
        $sync = $entry->isQueued() ? false : $sync;

        foreach ($entry->steps() as $branch => $class) {
            $previous = $this->driver->latestFor($sagaId, $step, $branch);

            if ($previous?->status !== SagaStepStatus::Completed) {
                continue;
            }

            $compensator = $this->resolveCompensator($class);

            if ($compensator === null) {
                $this->driver->store(new SagaStepRecord($sagaId, $step, SagaStepStatus::Compensated, branch: $branch, workflow: $workflow, payload: $previous->payload, encrypted: $previous->encrypted));

                continue;
            }

            $this->driver->store(new SagaStepRecord($sagaId, $step, SagaStepStatus::CompensationPending, branch: $branch, workflow: $workflow, payload: $previous->payload, encrypted: $previous->encrypted));
            $this->dispatch($this->build($compensator, $sagaId, $workflow, $step, $sync, $branch), $sync);
        }
    }

    /**
     * Once every branch of a group has either completed or already been compensated,
     * settle the group: stop on any CompensationFailed straggler (mirrors a single
     * step's own stall), otherwise cascade the rollback to the step before it.
     */
    protected function settleGroupCompensation(string $sagaId, string $workflow, int $step, Parallel $entry, bool $sync): void
    {
        $statuses = $this->branchStatuses($sagaId, $step, $entry);

        if (! $this->allCompensationSettled($statuses)) {
            return;
        }

        if ($this->anyCompensationFailed($statuses)) {
            return;
        }

        $this->beginCompensation($sagaId, $workflow, $step - 1, $sync);
    }

    /**
     * Once every branch of a group has completed, merge their payloads (keyed by
     * branch index) into one and advance past the group like a normal single step.
     */
    protected function checkGroupForward(string $sagaId, string $workflow, int $step, Parallel $entry, bool $sync): void
    {
        $statuses = $this->branchStatuses($sagaId, $step, $entry);

        if (! $this->allCompleted($statuses)) {
            return;
        }

        $merged = [];

        foreach (array_keys($entry->steps()) as $branch) {
            $record = $this->driver->latestFor($sagaId, $step, $branch);

            if ($record === null) {
                throw new LogicException("Saga {$sagaId} step {$step} branch {$branch} completed but has no recorded state.");
            }

            $merged[$branch] = $this->decode($record->payload, $record->encrypted);
        }

        $encrypt = $this->shouldEncrypt($workflow);
        $this->advanceForward($sagaId, $workflow, $step, $this->encode($merged, $encrypt), $encrypt, $sync);
    }

    protected function beginCompensation(string $sagaId, string $workflow, int $step, bool $sync): void
    {
        if ($step < 0) {
            $previous = $this->driver->latestFor($sagaId, 0);

            $record = $this->storeAndDispatch(new SagaStepRecord(
                $sagaId, 0, SagaStepStatus::RolledBack, workflow: $workflow, payload: $previous?->payload, encrypted: $previous !== null && $previous->encrypted
            ), SagaCompensated::class);

            $this->event(SagaRolledBack::class, $record);

            return;
        }

        $steps = $this->resolver->resolve($workflow)->steps();
        $entry = $steps[$step];

        if ($entry instanceof Parallel) {
            $this->beginCompensationForGroup($sagaId, $workflow, $step, $entry, $sync);
            $this->settleGroupCompensation($sagaId, $workflow, $step, $entry, $sync);

            return;
        }

        $compensator = $this->resolveCompensator($entry);
        $previous = $this->driver->latestFor($sagaId, $step);
        $payload = $previous?->payload;
        $encrypted = $previous !== null && $previous->encrypted;

        if ($compensator === null) {
            $this->driver->store(new SagaStepRecord($sagaId, $step, SagaStepStatus::Compensated, workflow: $workflow, payload: $payload, encrypted: $encrypted));
            $this->beginCompensation($sagaId, $workflow, $step - 1, $sync);

            return;
        }

        $this->driver->store(new SagaStepRecord($sagaId, $step, SagaStepStatus::CompensationPending, workflow: $workflow, payload: $payload, encrypted: $encrypted));
        $this->dispatch($this->build($compensator, $sagaId, $workflow, $step, $sync), $sync);
    }

    protected function shouldEncrypt(string $workflow): bool
    {
        return $this->resolver->resolve($workflow)->encryptContext();
    }

    protected function encode(mixed $value, bool $encrypt): mixed
    {
        $payload = $this->getSerializedPropertyValue($value);

        return $encrypt ? Crypt::encrypt($payload) : $payload;
    }

    protected function decode(mixed $payload, bool $encrypted): mixed
    {
        if ($encrypted && is_string($payload)) {
            return Crypt::decrypt($payload);
        }

        return $payload;
    }

    /**
     * @param class-string $jobClass
     */
    protected function build(string $jobClass, string $sagaId, string $workflow, int $step, bool $sync, int $branch = 0): object
    {
        /** @var object $job */
        $job = app($jobClass);

        $this->interactsWithSaga($job);

        $job->sagaId = $sagaId;
        $job->workflow = $workflow;
        $job->sagaStepIndex = $step;
        $job->branch = $branch;

        return $job;
    }

    protected function dispatch(object $job, bool $sync): void
    {
        $this->interactsWithSaga($job);

        // Guards only against two dispatch() calls racing for the same saga
        // step and direction — the step's own record, always written before
        // this is called, is what stops it being dispatched again afterwards.
        // Released immediately after the atomic acquire, not after the job
        // actually runs: in sync mode a step dispatches inline, so an
        // IterableSagaStep's next iteration dispatches the very same step
        // index — same lock key — from further down the same call stack
        // while this call is still on it. Holding the lock that long would
        // deadlock that redispatch against itself.
        $lock = Cache::lock($this->lockKey($job), 10);

        if (! $lock->get()) {
            return;
        }

        $lock->release();

        $job->sync = $sync;
        $job->context = $this->contextFor($job->sagaId, $job->sagaStepIndex, $job->branch);
        $job->middleware = [new ProcessesSaga()];

        $installedInterruptHandler = $this->installInterruptHandler($job->sagaId, $sync);

        try {
            if (! $job instanceof ShouldQueue) {
                // Illuminate\Bus\Dispatcher::dispatchNow() never looks at $job->middleware
                // — that's only read by CallQueuedHandler, for a job actually pushed
                // through a queue. Passing an explicit handler still runs it through
                // $job->middleware ourselves, while going through dispatchNow() itself
                // (rather than piping around it) means Bus::fake() still intercepts it
                // exactly like any other dispatched job.
                app(Dispatcher::class)->dispatchNow($job, new class
                {
                    public function handle(object $job): mixed
                    {
                        /** @var object{middleware: array<int, object>} $job */
                        return app(Pipeline::class)
                            ->send($job)
                            ->through($job->middleware)
                            ->then(function (object $job) {
                                $method = method_exists($job, 'handle') ? 'handle' : '__invoke';

                                // @phpstan-ignore-next-line argument.type (callable array shape, not a string)
                                return app()->call([$job, $method]);
                            });
                    }
                });

                return;
            }

            if ($sync) {
                $job->connection = 'sync';
            }

            app(Dispatcher::class)->dispatch($job);
        } catch (SagaInterruptedException $exception) {
            // Thrown by the SIGINT handler from inside whichever step happened to be
            // running — ProcessesSaga's own catch(Throwable) block already turned
            // that into a normal stepFailed()/compensation cascade on the way up.
            // Only the frame that owns the handler swallows it, so the interrupt
            // doesn't leak out of start()/retry()/etc. as a crash; any other frame
            // just lets it keep unwinding.
            if (! $installedInterruptHandler) {
                throw $exception;
            }
        } finally {
            if ($installedInterruptHandler) {
                $this->removeInterruptHandler();
            }
        }
    }

    protected function installInterruptHandler(string $sagaId, bool $sync): bool
    {
        if (! $sync
            || static::$interruptHandlerInstalled
            || ! app()->runningInConsole()
            || ! extension_loaded('pcntl')
        ) {
            return false;
        }

        static::$interruptHandlerInstalled = true;
        static::$interruptedSagaId = $sagaId;
        static::$previousAsyncSignals = pcntl_async_signals();

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () {
            $sagaId = static::$interruptedSagaId;

            if ($sagaId === null) {
                throw new LogicException('SIGINT handler fired with no interrupted saga recorded.');
            }

            throw new SagaInterruptedException($sagaId);
        });

        return true;
    }

    protected function removeInterruptHandler(): void
    {
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_async_signals(static::$previousAsyncSignals);

        static::$interruptHandlerInstalled = false;
        static::$interruptedSagaId = null;
    }

    protected function lockKey(object $job): string
    {
        $this->interactsWithSaga($job);

        $compensating = $job instanceof ShouldCompensate && $this->isCompensating($job->sagaId, $job->sagaStepIndex, $job->branch);

        return $this->lockKeyFor($job->sagaId, $job->sagaStepIndex, $job->branch, $compensating);
    }

    protected function lockKeyFor(string $sagaId, int $step, int $branch, bool $compensating): string
    {
        return "saga:{$sagaId}:{$step}:{$branch}:".($compensating ? 'compensate' : 'forward');
    }
}
