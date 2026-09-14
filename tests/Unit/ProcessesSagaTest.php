<?php

use Henzeb\Saga\Contracts\Driver;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Exceptions\StaleSagaStepException;
use Henzeb\Saga\Middleware\ProcessesSaga;
use Henzeb\Saga\SagaManager;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Tests\Support\CoordinatorTestOnStaleRetryWorkflow;
use Tests\Support\CoordinatorTestQueuedRetryStep;
use Tests\Support\CoordinatorTestRetryWorkflow;
use Tests\Support\InMemoryDriver;
use Tests\Support\ProcessesSagaTestAwaitingCompensatorStep;
use Tests\Support\ProcessesSagaTestAwaitingCompensatorWorkflow;
use Tests\Support\ProcessesSagaTestAwaitingStep;
use Tests\Support\ProcessesSagaTestAwaitingWorkflow;
use Tests\Support\ProcessesSagaTestExternalCompensatorStep;
use Tests\Support\ProcessesSagaTestExternalCompensatorWorkflow;
use Tests\Support\ProcessesSagaTestExternallyCompensatedStep;
use Tests\Support\ProcessesSagaTestForwardStep;
use Tests\Support\ProcessesSagaTestIterableCompensatingStep;
use Tests\Support\ProcessesSagaTestIterableCompensatingWorkflow;
use Tests\Support\ProcessesSagaTestIterableStep;
use Tests\Support\ProcessesSagaTestIterableWorkflow;
use Tests\Support\ProcessesSagaTestOneStepWorkflow;
use Tests\Support\ProcessesSagaTestQueuedStep;
use Tests\Support\ProcessesSagaTestSelfCompensatingStep;
use Tests\Support\ProcessesSagaTestSelfCompensatingWorkflow;
use Tests\Support\ProcessesSagaTestTwoStepWorkflow;

function bindSagaDriver(): InMemoryDriver
{
    $driver = new InMemoryDriver();

    // ProcessesSaga resolves its coordinator through SagaManager::coordinator(),
    // the same way production code does — so tests swap the driver in the same
    // place SagaManager builds one, rather than binding Driver::class directly.
    app()->extend(SagaManager::class, function ($service, $app) use ($driver) {
        return new class($driver, $app) extends SagaManager {
            public function __construct(protected Driver $fixedDriver, $container)
            {
                parent::__construct($container);
            }

            public function getDefaultDriver(): string
            {
                return 'fixed';
            }

            protected function createFixedDriver(): Driver
            {
                return $this->fixedDriver;
            }
        };
    });

    return $driver;
}

beforeEach(function () {
    Bus::fake();
});

it('skips handle() and just advances an already-completed step', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['x' => 1]));

    $job = new ProcessesSagaTestForwardStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestTwoStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = ['x' => 1];

    $called = false;
    $result = (new ProcessesSaga())->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()
        ->and($result)->toBeNull()
        ->and($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Pending);

    Bus::assertDispatched(\Tests\Support\CoordinatorTestStepTwo::class);
});

it('re-dispatches an iterable step at the same index while hasNext() is true', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['page' => 1]));

    $job = new ProcessesSagaTestIterableStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestIterableWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = ['page' => 1];

    (new ProcessesSaga())->handle($job, fn ($job) => $job->handle());

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Pending)
        ->and($driver->latestFor('saga-1', 0)->payload)->toBe(['page' => 2]);

    Bus::assertDispatched(ProcessesSagaTestIterableStep::class, function ($dispatched) {
        return $dispatched->sagaStepIndex === 0 && $dispatched->context === ['page' => 2];
    });
});

it('completes and advances an iterable step once hasNext() returns false', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['page' => 2]));

    $job = new ProcessesSagaTestIterableStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestIterableWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = ['page' => 2];

    (new ProcessesSaga())->handle($job, fn ($job) => $job->handle());

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Completed);

    Bus::assertNotDispatched(ProcessesSagaTestIterableStep::class);
});

it('re-dispatches an iterable compensator at the same index while hasNext() is true', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::CompensationPending, payload: ['page' => 1]));

    $job = new ProcessesSagaTestIterableCompensatingStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestIterableCompensatingWorkflow::class;
    $job->sagaStepIndex = 1;
    $job->sync = false;
    $job->context = ['page' => 1];

    (new ProcessesSaga())->handle($job, fn () => null);

    expect($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::CompensationPending)
        ->and($driver->latestFor('saga-1', 1)->payload)->toBe(['page' => 2]);

    Bus::assertDispatched(ProcessesSagaTestIterableCompensatingStep::class, function ($dispatched) {
        return $dispatched->sagaStepIndex === 1 && $dispatched->context === ['page' => 2];
    });
});

it('completes and compensates the previous step once an iterable compensator finishes', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['x' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::CompensationPending, payload: ['page' => 2]));

    $job = new ProcessesSagaTestIterableCompensatingStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestIterableCompensatingWorkflow::class;
    $job->sagaStepIndex = 1;
    $job->sync = false;
    $job->context = ['page' => 2];

    (new ProcessesSaga())->handle($job, fn () => null);

    expect($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Compensated)
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::CompensationPending);

    Bus::assertDispatched(ProcessesSagaTestSelfCompensatingStep::class);
});

it('parks a step that calls waitFor() instead of completing it', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending));

    $job = new ProcessesSagaTestAwaitingStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestAwaitingWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;

    (new ProcessesSaga())->handle($job, fn ($j) => $j->handle());

    $record = $driver->latestFor('saga-1', 0);

    expect($record->status)->toBe(SagaStepStatus::Waiting)
        ->and($record->signal)->toBe('approval');

    Bus::assertNotDispatched(ProcessesSagaTestAwaitingStep::class);
});

it('completes a step normally once waitFor() returns the delivered signal', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['order' => 1]));

    $job = new ProcessesSagaTestAwaitingStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestAwaitingWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = ['order' => 1];
    $job->deliveredSignal = 'approval';
    $job->deliveredSignalPayload = ['approved' => true];

    $result = (new ProcessesSaga())->handle($job, fn ($j) => $j->handle());

    expect($result)->toBe(['approved' => true])
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Completed);
});

it('parks a compensator that calls waitFor() as CompensationWaiting', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationPending));

    $job = new ProcessesSagaTestAwaitingCompensatorStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestAwaitingCompensatorWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;

    (new ProcessesSaga())->handle($job, function () {
        //
    });

    $record = $driver->latestFor('saga-1', 0);

    expect($record->status)->toBe(SagaStepStatus::CompensationWaiting)
        ->and($record->signal)->toBe('refunded');
});

it('completes a compensator normally once waitFor() returns the delivered signal', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationPending, payload: ['order' => 1]));

    $job = new ProcessesSagaTestAwaitingCompensatorStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestAwaitingCompensatorWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = ['order' => 1];
    $job->deliveredSignal = 'refunded';
    $job->deliveredSignalPayload = ['refunded' => true];

    $result = (new ProcessesSaga())->handle($job, function () {
        //
    });

    expect($result)->toBe(['refunded' => true])
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::RolledBack);
});

it('fails a stale running step by default instead of retrying', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running));

    $job = new ProcessesSagaTestForwardStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestOneStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;

    $called = false;
    $result = (new ProcessesSaga())->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()->and($result)->toBeNull();

    $record = $driver->latestFor('saga-1', 0);

    expect($record->status)->toBe(SagaStepStatus::Failed)
        ->and($record->reason)->toContain(StaleSagaStepException::class);
});

it('retries a stale step when the job implements RetryWhenStale', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running));

    $job = new CoordinatorTestQueuedRetryStep();
    $job->sagaId = 'saga-1';
    $job->workflow = CoordinatorTestRetryWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;

    $called = false;
    (new ProcessesSaga())->handle($job, function ($j) use (&$called) {
        $called = true;

        return 'done';
    });

    expect($called)->toBeTrue()
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Completed);
});

it('retries a stale step per the workflow\'s onStaleRunning override', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running));

    $job = new ProcessesSagaTestForwardStep();
    $job->sagaId = 'saga-1';
    $job->workflow = CoordinatorTestOnStaleRetryWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;

    $called = false;
    (new ProcessesSaga())->handle($job, function () use (&$called) {
        $called = true;

        return 'done';
    });

    expect($called)->toBeTrue();
});

it('retries a stale step per config when neither the job nor the workflow says otherwise', function () {
    Config::set('saga.on_stale_running', 'retry');

    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running));

    $job = new ProcessesSagaTestForwardStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestOneStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;

    $called = false;
    (new ProcessesSaga())->handle($job, function () use (&$called) {
        $called = true;

        return 'done';
    });

    expect($called)->toBeTrue();
});

it('marks the step running before calling handle() on a forward job', function () {
    $driver = bindSagaDriver();

    $job = new ProcessesSagaTestForwardStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestOneStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;

    $seenStatus = null;
    (new ProcessesSaga())->handle($job, function () use (&$seenStatus, $driver) {
        $seenStatus = $driver->latestFor('saga-1', 0)?->status;

        return 'done';
    });

    expect($seenStatus)->toBe(SagaStepStatus::Running);
});

it('completes the step and advances after a successful forward run', function () {
    $driver = bindSagaDriver();

    $job = new ProcessesSagaTestForwardStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestTwoStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = ['x' => 1];

    $result = (new ProcessesSaga())->handle($job, fn ($j) => $j->handle());

    expect($result)->toBe(['x' => 1])
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Completed);

    Bus::assertDispatched(\Tests\Support\CoordinatorTestStepTwo::class);
});

it('calls compensate() instead of $next when the job is currently compensating', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationPending, payload: ['refund' => 5]));

    $job = new ProcessesSagaTestSelfCompensatingStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestSelfCompensatingWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = ['refund' => 5];

    $called = false;
    $result = (new ProcessesSaga())->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()
        ->and($result)->toBe(['refund' => 5])
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::RolledBack);
});

it('falls back to $next when compensating a job with an external compensator (no compensate method)', function () {
    $driver = bindSagaDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationPending, payload: ['refund' => 5]));

    $job = new ProcessesSagaTestExternalCompensatorStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestExternalCompensatorWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = ['refund' => 5];

    $called = false;
    (new ProcessesSaga())->handle($job, function ($j) use (&$called) {
        $called = true;

        return $j->handle();
    });

    expect($called)->toBeTrue()
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::RolledBack);
});

it('reports failure on the final attempt and rethrows', function () {
    $driver = bindSagaDriver();

    $job = new ProcessesSagaTestForwardStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestOneStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = true; // sync is always a final attempt
    $job->context = null;

    expect(fn () => (new ProcessesSaga())->handle($job, function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    $record = $driver->latestFor('saga-1', 0);

    expect($record->status)->toBe(SagaStepStatus::Failed)
        ->and($record->reason)->toBe('RuntimeException: boom');
});

it('rethrows without reporting failure when a queued job still has attempts left', function () {
    $driver = bindSagaDriver();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('isReleased')->andReturn(false);
    $queueJob->shouldReceive('attempts')->andReturn(1);

    $job = new ProcessesSagaTestQueuedStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestOneStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;
    $job->job = $queueJob;

    expect(fn () => (new ProcessesSaga())->handle($job, function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($driver->latestFor('saga-1', 0)?->status)->not->toBe(SagaStepStatus::Failed);
});

it('reports failure once a queued job has exhausted its attempts', function () {
    $driver = bindSagaDriver();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('isReleased')->andReturn(false);
    $queueJob->shouldReceive('attempts')->andReturn(3);

    $job = new ProcessesSagaTestQueuedStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestOneStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;
    $job->job = $queueJob;

    expect(fn () => (new ProcessesSaga())->handle($job, function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Failed);
});

it('does not mark completed when the underlying queue job was released', function () {
    $driver = bindSagaDriver();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('isReleased')->andReturn(true);

    $job = new ProcessesSagaTestQueuedStep();
    $job->sagaId = 'saga-1';
    $job->workflow = ProcessesSagaTestOneStepWorkflow::class;
    $job->sagaStepIndex = 0;
    $job->sync = false;
    $job->context = null;
    $job->job = $queueJob;

    $result = (new ProcessesSaga())->handle($job, fn ($j) => 'ok');

    expect($result)->toBe('ok')
        ->and($driver->latestFor('saga-1', 0)?->status)->not->toBe(SagaStepStatus::Completed);
});
