<?php

use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\DTO\SagaStepRecords;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Exceptions\InvalidWorkflowException;
use Henzeb\Saga\Exceptions\SagaNotAwaitingSignalException;
use Henzeb\Saga\Exceptions\SagaNotFoundException;
use Henzeb\Saga\Exceptions\SagaNotRetryableException;
use Henzeb\Saga\Exceptions\SignalTimeoutException;
use Henzeb\Saga\Exceptions\StaleSagaStepException;
use Henzeb\Saga\Parallel;
use Henzeb\Saga\SagaCoordinator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CoordinatorTestCompensatingWorkflow;
use Tests\Support\CoordinatorTestNoCompensatorWorkflow;
use Tests\Support\CoordinatorTestNotASagaJob;
use Tests\Support\CoordinatorTestOneStepWorkflow;
use Tests\Support\CoordinatorTestRefundStepOne;
use Tests\Support\CoordinatorTestRunningTimeoutWorkflow;
use Tests\Support\CoordinatorTestSelfCompensatingStep;
use Tests\Support\CoordinatorTestSelfCompensatingWorkflow;
use Tests\Support\CoordinatorTestSignalTimeoutWorkflow;
use Tests\Support\CoordinatorTestStepOne;
use Tests\Support\CoordinatorTestStepTwo;
use Tests\Support\CoordinatorTestTwoStepWorkflow;
use Tests\Support\CoordinatorTestInvalidWorkflow;
use Tests\Support\InMemoryDriver;

beforeEach(function () {
    Bus::fake();
});

it('returns null context for a step with no recorded state yet', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    expect($coordinator->contextFor('saga-1', 0))->toBeNull();
});

it('returns the latest payload for a step as its context', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['orderId' => 'abc']));
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['orderId' => 'abc', 'total' => 10]));

    $coordinator = new SagaCoordinator($driver);

    expect($coordinator->contextFor('saga-1', 0))->toBe(['orderId' => 'abc', 'total' => 10]);
});

it('reports isCompensating only when the step is currently compensating', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));

    $coordinator = new SagaCoordinator($driver);

    expect($coordinator->isCompensating('saga-1', 0))->toBeFalse();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Compensating));

    expect($coordinator->isCompensating('saga-1', 0))->toBeTrue();
});

it('reports isAlreadyCompleted only when the step is currently completed', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running));

    $coordinator = new SagaCoordinator($driver);

    expect($coordinator->isAlreadyCompleted('saga-1', 0))->toBeFalse();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));

    expect($coordinator->isAlreadyCompleted('saga-1', 0))->toBeTrue();
});

it('reports isStale when the step is Running or Compensating with nothing after it', function (SagaStepStatus $status) {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, $status));

    $coordinator = new SagaCoordinator($driver);

    expect($coordinator->isStale('saga-1', 0))->toBeTrue();
})->with([SagaStepStatus::Running, SagaStepStatus::Compensating]);

it('reports isStale as false for settled statuses', function (SagaStepStatus $status) {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, $status));

    $coordinator = new SagaCoordinator($driver);

    expect($coordinator->isStale('saga-1', 0))->toBeFalse();
})->with([SagaStepStatus::Pending, SagaStepStatus::Completed, SagaStepStatus::Failed, SagaStepStatus::CompensationPending, SagaStepStatus::Compensated, SagaStepStatus::CompensationFailed, SagaStepStatus::RolledBack]);

it('stores a fresh Running record when marking a step running', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->markRunning('saga-1', CoordinatorTestOneStepWorkflow::class, 2);

    $record = $driver->latestFor('saga-1', 2);

    expect($record->sagaId)->toBe('saga-1')
        ->and($record->step)->toBe(2)
        ->and($record->status)->toBe(SagaStepStatus::Running);
});

it('carries the previous record\'s payload forward onto the Running record', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->markRunning('saga-1', CoordinatorTestOneStepWorkflow::class, 0);

    expect($driver->latestFor('saga-1', 0)->payload)->toBe(['order' => 1]);
});

it('carries the previous record\'s payload forward onto the Compensating record', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationPending, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->markCompensating('saga-1', CoordinatorTestCompensatingWorkflow::class, 0);

    expect($driver->latestFor('saga-1', 0)->payload)->toBe(['order' => 1]);
});

it('keeps a step\'s payload through the real Pending -> Running -> Failed lifecycle, so a retry has context', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->markRunning('saga-1', CoordinatorTestOneStepWorkflow::class, 0);
    $coordinator->stepFailed('saga-1', CoordinatorTestOneStepWorkflow::class, 0, new RuntimeException('boom'));

    expect($driver->latestFor('saga-1', 0)->payload)->toBe(['order' => 1]);
});

it('keeps a step\'s payload through the real CompensationPending -> Compensating -> CompensationFailed lifecycle', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationPending, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->markCompensating('saga-1', CoordinatorTestCompensatingWorkflow::class, 0);
    $coordinator->stepFailed('saga-1', CoordinatorTestCompensatingWorkflow::class, 0, new RuntimeException('refund failed'));

    expect($driver->latestFor('saga-1', 0)->payload)->toBe(['order' => 1]);
});

it('accepts a job using the InteractsWithSaga concern', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->interactsWithSaga(new CoordinatorTestStepOne());
})->throwsNoExceptions();

it('rejects a job not using the InteractsWithSaga concern', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->interactsWithSaga(new CoordinatorTestNotASagaJob());
})->throws(LogicException::class, 'must use the Henzeb\Saga\Concerns\InteractsWithSaga concern.');

it('completes a step and advances to the next one', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestTwoStepWorkflow::class, 0, ['x' => 1]);

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Completed)
        ->and($driver->latestFor('saga-1', 0)->payload)->toBe(['x' => 1])
        ->and($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Pending)
        ->and($driver->latestFor('saga-1', 1)->payload)->toBe(['x' => 1]);

    Bus::assertDispatched(CoordinatorTestStepTwo::class, function ($job) {
        return $job->sagaId === 'saga-1'
            && $job->workflow === CoordinatorTestTwoStepWorkflow::class
            && $job->sagaStepIndex === 1
            && $job->context === ['x' => 1]
            && $job->sync === false;
    });
});

it('iterates a step with its new context, without advancing or writing a new payload', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepIterated('saga-1', CoordinatorTestTwoStepWorkflow::class, 0, ['x' => 1]);

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Pending)
        ->and($driver->latestFor('saga-1', 0)->payload)->toBe(['x' => 1]);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0 && $job->context === ['x' => 1];
    });
    Bus::assertNotDispatched(CoordinatorTestStepTwo::class);
});

it('iterates a compensating step, keeping it marked as compensating', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['order' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Compensating, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepIterated('saga-1', CoordinatorTestSelfCompensatingWorkflow::class, 0, ['order' => 1], compensating: true);

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::CompensationPending)
        ->and($coordinator->isCompensating('saga-1', 0))->toBeTrue();

    Bus::assertDispatched(CoordinatorTestSelfCompensatingStep::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0 && $job->context === ['order' => 1];
    });
});

it('records a step as Waiting with the signal name it awaits, dispatching nothing', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepAwaitsSignal('saga-1', CoordinatorTestOneStepWorkflow::class, 0, 'approval', ['x' => 1]);

    $record = $driver->latestFor('saga-1', 0);

    expect($record->status)->toBe(SagaStepStatus::Waiting)
        ->and($record->signal)->toBe('approval')
        ->and($record->payload)->toBe(['x' => 1]);

    Bus::assertNothingDispatched();
});

it('leaves the signal deadline null when neither the workflow nor config sets one', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepAwaitsSignal('saga-1', CoordinatorTestOneStepWorkflow::class, 0, 'approval', null);

    expect($driver->latestFor('saga-1', 0)->signalExpiresAt)->toBeNull();
});

it('sets the signal deadline from the workflow\'s own signalTimeout()', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $before = now();
    $coordinator->stepAwaitsSignal('saga-1', CoordinatorTestSignalTimeoutWorkflow::class, 0, 'approval', null);
    $after = now();

    $expiresAt = $driver->latestFor('saga-1', 0)->signalExpiresAt;

    expect($expiresAt)->not->toBeNull()
        ->and($expiresAt)->toBeGreaterThanOrEqual($before->addSeconds(60))
        ->and($expiresAt)->toBeLessThanOrEqual($after->addSeconds(60));
});

it('falls back to config(\'saga.signal_timeout\') when the workflow does not override it', function () {
    config()->set('saga.signal_timeout', 30);

    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepAwaitsSignal('saga-1', CoordinatorTestOneStepWorkflow::class, 0, 'approval', null);

    expect($driver->latestFor('saga-1', 0)->signalExpiresAt)->not->toBeNull();
});

it('leaves the running deadline null when neither the workflow nor config sets one', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->markRunning('saga-1', CoordinatorTestOneStepWorkflow::class, 0);

    expect($driver->latestFor('saga-1', 0)->runningExpiresAt)->toBeNull();
});

it('sets the running deadline from the workflow\'s own runningTimeout()', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $before = now();
    $coordinator->markRunning('saga-1', CoordinatorTestRunningTimeoutWorkflow::class, 0);
    $after = now();

    $expiresAt = $driver->latestFor('saga-1', 0)->runningExpiresAt;

    expect($expiresAt)->not->toBeNull()
        ->and($expiresAt)->toBeGreaterThanOrEqual($before->addSeconds(60))
        ->and($expiresAt)->toBeLessThanOrEqual($after->addSeconds(60));
});

it('falls back to config(\'saga.running_timeout\') when the workflow does not override it', function () {
    config()->set('saga.running_timeout', 30);

    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->markRunning('saga-1', CoordinatorTestOneStepWorkflow::class, 0);

    expect($driver->latestFor('saga-1', 0)->runningExpiresAt)->not->toBeNull();
});

it('fails every due running step, cascading exactly like any other forward or compensation failure', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running, workflow: CoordinatorTestOneStepWorkflow::class));
    $driver->store(new SagaStepRecord(
        'saga-2', 0, SagaStepStatus::Compensating, workflow: CoordinatorTestSelfCompensatingWorkflow::class
    ));
    $coordinator = new SagaCoordinator($driver);

    $due = new SagaStepRecords([
        $driver->latestFor('saga-1', 0),
        $driver->latestFor('saga-2', 0),
    ]);

    $count = $coordinator->failDueRunning($due);

    expect($count)->toBe(2)
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Failed)
        ->and($driver->latestFor('saga-1', 0)->reason)->toContain(StaleSagaStepException::class)
        ->and($driver->latestFor('saga-2', 0)->status)->toBe(SagaStepStatus::CompensationFailed);
});

it('fails every due signal, cascading exactly like any other forward or compensation failure', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, workflow: CoordinatorTestOneStepWorkflow::class, signal: 'approval'));
    $driver->store(new SagaStepRecord(
        'saga-2', 0, SagaStepStatus::CompensationWaiting, workflow: CoordinatorTestSelfCompensatingWorkflow::class, signal: 'refunded'
    ));
    $coordinator = new SagaCoordinator($driver);

    $due = new SagaStepRecords([
        $driver->latestFor('saga-1', 0),
        $driver->latestFor('saga-2', 0),
    ]);

    $count = $coordinator->failDueSignals($due);

    expect($count)->toBe(2)
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Failed)
        ->and($driver->latestFor('saga-1', 0)->reason)->toContain(SignalTimeoutException::class)
        ->and($driver->latestFor('saga-2', 0)->status)->toBe(SagaStepStatus::CompensationFailed);
});

it('returns 0 when nothing is due', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    expect($coordinator->failDueSignals(new SagaStepRecords()))->toBe(0);
});

it('propagates sync: true from failDueSignals() into the compensation it starts', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class, payload: ['order' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Waiting, workflow: CoordinatorTestCompensatingWorkflow::class, signal: 'approval'));
    $coordinator = new SagaCoordinator($driver);

    $due = new SagaStepRecords([$driver->latestFor('saga-1', 1)]);

    $coordinator->failDueSignals($due, sync: true);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, fn ($job) => $job->sync === true);
});

it('defaults failDueSignals() to sync: false', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class, payload: ['order' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Waiting, workflow: CoordinatorTestCompensatingWorkflow::class, signal: 'approval'));
    $coordinator = new SagaCoordinator($driver);

    $due = new SagaStepRecords([$driver->latestFor('saga-1', 1)]);

    $coordinator->failDueSignals($due);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, fn ($job) => $job->sync === false);
});

it('propagates sync: true from sweepSignals() through to the compensation it starts', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class, payload: ['order' => 1]));
    $driver->store(new SagaStepRecord(
        'saga-1', 1, SagaStepStatus::Waiting, workflow: CoordinatorTestCompensatingWorkflow::class,
        signal: 'approval', signalExpiresAt: new DateTimeImmutable('2020-01-01')
    ));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->sweepSignals(sync: true);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, fn ($job) => $job->sync === true);
});

it('records a compensating step as CompensationWaiting when it awaits a signal', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Compensating));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepAwaitsSignal('saga-1', CoordinatorTestSelfCompensatingWorkflow::class, 0, 'refunded', ['order' => 1], compensating: true);

    $record = $driver->latestFor('saga-1', 0);

    expect($record->status)->toBe(SagaStepStatus::CompensationWaiting)
        ->and($record->signal)->toBe('refunded')
        ->and($coordinator->isCompensating('saga-1', 0))->toBeTrue();
});

it('delivers a signal, keeping the step\'s own context untouched, and redispatching the step', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, workflow: CoordinatorTestOneStepWorkflow::class, payload: ['order' => 1], signal: 'approval'));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->signal('saga-1', 'approval', ['approved' => true]);

    expect($state->status)->toBe(SagaStepStatus::Pending)
        ->and($state->context()->raw())->toBe(['order' => 1]);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0
            && $job->context === ['order' => 1]
            && $job->deliveredSignal === 'approval'
            && $job->deliveredSignalPayload === ['approved' => true];
    });
});

it('delivers a signal with no payload at all', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, workflow: CoordinatorTestOneStepWorkflow::class, payload: ['order' => 1], signal: 'approval'));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->signal('saga-1', 'approval');

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->context === ['order' => 1]
            && $job->deliveredSignal === 'approval' && $job->deliveredSignalPayload === null;
    });
});

it('delivers a signal to a self-compensating step, redispatching its compensator', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationWaiting, workflow: CoordinatorTestSelfCompensatingWorkflow::class, payload: ['order' => 1], signal: 'refunded'));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->signal('saga-1', 'refunded', ['refunded' => true]);

    expect($state->status)->toBe(SagaStepStatus::CompensationPending)
        ->and($state->context()->raw())->toBe(['order' => 1]);

    Bus::assertDispatched(CoordinatorTestSelfCompensatingStep::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0
            && $job->context === ['order' => 1]
            && $job->deliveredSignal === 'refunded'
            && $job->deliveredSignalPayload === ['refunded' => true];
    });
});

it('delivers a signal to an externally-compensated step, redispatching the compensator job', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationWaiting, workflow: CoordinatorTestCompensatingWorkflow::class, payload: ['order' => 1], signal: 'refunded'));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->signal('saga-1', 'refunded', ['refunded' => true]);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0
            && $job->context === ['order' => 1]
            && $job->deliveredSignal === 'refunded'
            && $job->deliveredSignalPayload === ['refunded' => true];
    });
});

it('refuses to deliver a signal the saga is not currently waiting for', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, workflow: CoordinatorTestOneStepWorkflow::class, signal: 'approval'));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->signal('saga-1', 'wrong-name');
})->throws(SagaNotAwaitingSignalException::class);

it('refuses to deliver a signal to a saga that is not currently waiting at all', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestOneStepWorkflow::class));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->signal('saga-1', 'approval');
})->throws(SagaNotAwaitingSignalException::class);

it('throws SagaNotFoundException when signaling a saga that does not exist', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->signal('missing-saga', 'approval');
})->throws(SagaNotFoundException::class);

it('points a ShouldQueue step at the sync connection when advancing under sync: true', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', \Tests\Support\CoordinatorTestSyncQueuedWorkflow::class, 0, null, sync: true);

    Bus::assertDispatched(\Tests\Support\CoordinatorTestQueuedRetryStep::class, function ($job) {
        return $job->connection === 'sync' && $job->sync === true;
    });
});

it('completes the last step without dispatching anything further', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestOneStepWorkflow::class, 0, ['x' => 1]);

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Completed);

    Bus::assertNothingDispatched();
});

it('completing a compensating step dispatches the previous step\'s compensator', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestCompensatingWorkflow::class, 1, null, compensating: true);

    expect($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Compensated)
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::CompensationPending)
        ->and($driver->latestFor('saga-1', 0)->payload)->toBe(['order' => 1]);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0 && $job->context === ['order' => 1];
    });
});

it('cascades compensation through steps without a compensator', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestNoCompensatorWorkflow::class, 1, null, compensating: true);

    expect($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Compensated)
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::RolledBack);

    Bus::assertNothingDispatched();
});

it('stops compensating once it walks past the first step, marking the saga rolled back', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestOneStepWorkflow::class, 0, null, compensating: true);

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::RolledBack);

    Bus::assertNothingDispatched();
});

it('re-dispatches a self-compensating step for its own rollback', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['a' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestSelfCompensatingWorkflow::class, 1, null, compensating: true);

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::CompensationPending);

    Bus::assertDispatched(CoordinatorTestSelfCompensatingStep::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0;
    });
});

it('records a forward failure and begins compensating the previous step', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestCompensatingWorkflow::class, 1, new RuntimeException('boom'));

    $failed = $driver->latestFor('saga-1', 1);

    expect($failed->status)->toBe(SagaStepStatus::Failed)
        ->and($failed->reason)->toBe('RuntimeException: boom')
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::CompensationPending);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class);
});

it('propagates sync: true from a forward failure into its compensation', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestCompensatingWorkflow::class, 1, new RuntimeException('boom'), sync: true);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, function ($job) {
        return $job->sync === true;
    });
});

it('defaults compensation to sync: false when a forward failure does not say otherwise', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestCompensatingWorkflow::class, 1, new RuntimeException('boom'));

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, function ($job) {
        return $job->sync === false;
    });
});

it('records a forward failure at the first step without compensating anything', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestOneStepWorkflow::class, 0, new RuntimeException('boom'));

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Failed);

    Bus::assertNothingDispatched();
});

it('records a null reason when stepFailed is given no exception', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestOneStepWorkflow::class, 0, null);

    expect($driver->latestFor('saga-1', 0)->reason)->toBeNull();
});

it('records a compensation failure and halts, dispatching nothing further', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Compensating));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestCompensatingWorkflow::class, 0, new RuntimeException('refund failed'));

    $record = $driver->latestFor('saga-1', 0);

    expect($record->status)->toBe(SagaStepStatus::CompensationFailed)
        ->and($record->reason)->toBe('RuntimeException: refund failed');

    Bus::assertNothingDispatched();
});

it('compensates a saga still in-flight by failing its current step manually', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class, payload: ['order' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Running, workflow: CoordinatorTestCompensatingWorkflow::class));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->compensate('saga-1');

    expect($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Failed)
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::CompensationPending)
        ->and($state->status)->toBe(SagaStepStatus::CompensationPending);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class);
});

it('compensates a saga that already completed successfully, including its last step', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class, payload: ['order' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->compensate('saga-1');

    expect($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Compensated)
        ->and($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::CompensationPending)
        ->and($state->status)->toBe(SagaStepStatus::CompensationPending);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class);
});

it('does nothing when compensate() is called on a saga with nothing left to compensate', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::RolledBack, workflow: CoordinatorTestCompensatingWorkflow::class));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->compensate('saga-1');

    expect($state->status)->toBe(SagaStepStatus::RolledBack);

    Bus::assertNothingDispatched();
});

it('propagates sync: true from a manual compensate() into the compensation it starts', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class, payload: ['order' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Running, workflow: CoordinatorTestCompensatingWorkflow::class));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->compensate('saga-1', sync: true);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, fn ($job) => $job->sync === true);
});

it('defaults a manual compensate() to sync: false', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class, payload: ['order' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Running, workflow: CoordinatorTestCompensatingWorkflow::class));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->compensate('saga-1');

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, fn ($job) => $job->sync === false);
});

it('throws SagaNotFoundException when compensating a saga that does not exist', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->compensate('missing');
})->throws(SagaNotFoundException::class);

it('re-advances a completed step without re-running it, via ensureAdvanced', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['x' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->ensureAdvanced('saga-1', CoordinatorTestTwoStepWorkflow::class, 0);

    expect($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Pending);

    Bus::assertDispatched(CoordinatorTestStepTwo::class, function ($job) {
        return $job->sagaStepIndex === 1 && $job->context === ['x' => 1];
    });
});

it('does not dispatch a step whose lock is already held', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    Cache::lock('saga:saga-1:1:0:forward', 60)->get();

    $coordinator->stepCompleted('saga-1', CoordinatorTestTwoStepWorkflow::class, 0, ['x' => 1]);

    expect($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Pending);

    Bus::assertNothingDispatched();
});

it('releases the dispatch lock immediately, without waiting for the step to finish', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestTwoStepWorkflow::class, 0, ['x' => 1]);

    Bus::assertDispatched(CoordinatorTestStepTwo::class);

    expect(Cache::lock('saga:saga-1:1:0:forward')->get())->toBeTrue();
});

it('never actually runs a plain, non-ShouldQueue step while Bus::fake() is active', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    // CoordinatorTestStepOne is a plain class, not ShouldQueue — dispatch() has to
    // route it through Dispatcher::dispatchNow() rather than the queue, and
    // Bus::fake() must still intercept that path before its handle() ever runs.
    $state = $coordinator->start(new CoordinatorTestOneStepWorkflow(), ['x' => 1]);

    expect($state->status)->toBe(SagaStepStatus::Pending);

    Bus::assertDispatched(CoordinatorTestStepOne::class);
});

it('starts a saga: stores the initial context and dispatches the first step', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 1]);

    expect($state->status)->toBe(SagaStepStatus::Pending)
        ->and($state->step)->toBe(0)
        ->and($state->context()->raw())->toBe(['x' => 1]);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) use ($state) {
        return $job->sagaId === $state->sagaId
            && $job->workflow === CoordinatorTestTwoStepWorkflow::class
            && $job->sagaStepIndex === 0
            && $job->context === ['x' => 1];
    });
});

it('starts a saga from a workflow class name as well as an instance', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->start(CoordinatorTestTwoStepWorkflow::class, ['x' => 1]);

    expect($state->status)->toBe(SagaStepStatus::Pending)
        ->and($state->step)->toBe(0)
        ->and($state->context()->raw())->toBe(['x' => 1]);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) use ($state) {
        return $job->sagaId === $state->sagaId
            && $job->workflow === CoordinatorTestTwoStepWorkflow::class
            && $job->sagaStepIndex === 0
            && $job->context === ['x' => 1];
    });
});

it('starts only once for the same idempotency key, returning the existing saga on a repeat call', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $first = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 1], sagaId: 'order-123');
    $second = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 999], sagaId: 'order-123');

    expect($second->sagaId)->toBe($first->sagaId)
        ->and($second->context()->raw())->toBe(['x' => 1]);

    Bus::assertDispatchedTimes(CoordinatorTestStepOne::class, 1);
});

it('starts a separate saga per idempotency key, and a separate one again without any key', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $a = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 1], sagaId: 'order-123');
    $b = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 1], sagaId: 'order-456');
    $c = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 1]);

    expect($a->sagaId)->not->toBe($b->sagaId)
        ->and($a->sagaId)->not->toBe($c->sagaId)
        ->and($b->sagaId)->not->toBe($c->sagaId);
});

it('scopes an idempotency key to the workflow, so different workflows never collide on the same key', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $a = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 1], sagaId: 'same-key');
    $b = $coordinator->start(new CoordinatorTestOneStepWorkflow(), ['x' => 1], sagaId: 'same-key');

    expect($a->sagaId)->not->toBe($b->sagaId);
});

it('resolves sagaIdFor() to the exact same id start() uses for that workflow and key', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $id = $coordinator->sagaIdFor(new CoordinatorTestTwoStepWorkflow(), 'order-123');
    $state = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 1], sagaId: 'order-123');

    expect($id)->toBe($state->sagaId);
});

it('shapes sagaIdFor() exactly like a real ulid, deterministically', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $id = $coordinator->sagaIdFor(new CoordinatorTestTwoStepWorkflow(), 'order-123');

    expect($id)->toHaveLength(26)
        ->and(\Symfony\Component\Uid\Ulid::isValid($id))->toBeTrue()
        ->and($coordinator->sagaIdFor(new CoordinatorTestTwoStepWorkflow(), 'order-123'))->toBe($id);
});

it('starts a saga under an explicitly given, already-valid sagaId instead of generating one', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $ownId = (string) new Symfony\Component\Uid\Ulid();
    $state = $coordinator->start(new CoordinatorTestTwoStepWorkflow(), ['x' => 1], sagaId: $ownId);

    expect($state->sagaId)->toBe($ownId);
});

it('validates the workflow before starting, throwing for an invalid one', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->start(new CoordinatorTestInvalidWorkflow(), null);
})->throws(InvalidWorkflowException::class);

it('gets the current state of a saga from its latest record', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['x' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['x' => 1, 'y' => 2]));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->current('saga-1');

    expect($state->sagaId)->toBe('saga-1')
        ->and($state->status)->toBe(SagaStepStatus::Completed)
        ->and($state->step)->toBe(0)
        ->and($state->context()->raw())->toBe(['x' => 1, 'y' => 2])
        ->and($state->reason)->toBeNull()
        ->and($state->trail())->toHaveCount(2);
});

it('builds each trail entry as its own SagaState, with a trail restricted to what preceded it', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['x' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['x' => 1]));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Pending, payload: ['x' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $trail = $coordinator->current('saga-1')->trail();

    expect($trail)->toHaveCount(3);

    [$first, $second, $third] = $trail->all();

    expect($first)->toBeInstanceOf(SagaState::class)
        ->and($first->status)->toBe(SagaStepStatus::Pending)
        ->and($first->step)->toBe(0)
        ->and($first->trail())->toHaveCount(0);

    expect($second->status)->toBe(SagaStepStatus::Completed)
        ->and($second->trail())->toHaveCount(1);

    expect($third->status)->toBe(SagaStepStatus::Pending)
        ->and($third->step)->toBe(1)
        ->and($third->trail())->toHaveCount(2);
});

it('throws SagaNotFoundException when getting a saga that does not exist', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->current('missing-saga');
})->throws(SagaNotFoundException::class);

it('carries the payload forward onto a Failed record so it survives the failure', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestOneStepWorkflow::class, 0, new RuntimeException('boom'));

    expect($driver->latestFor('saga-1', 0)->payload)->toBe(['order' => 1]);
});

it('marks the saga RolledBack, carrying the final context forward, once compensation walks past step 0', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestOneStepWorkflow::class, 0, ['order' => 1], compensating: true);

    $record = $driver->latestFor('saga-1', 0);

    expect($record->status)->toBe(SagaStepStatus::RolledBack)
        ->and($record->payload)->toBe(['order' => 1]);
});

it('retries a rolled-back saga under the same sagaId, defaulting to its final context', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::RolledBack, payload: ['x' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->retry('saga-1', CoordinatorTestTwoStepWorkflow::class);

    expect($state->sagaId)->toBe('saga-1')
        ->and($state->status)->toBe(SagaStepStatus::Pending)
        ->and($state->step)->toBe(0)
        ->and($state->context()->raw())->toBe(['x' => 1]);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0 && $job->context === ['x' => 1];
    });
});

it('retries a rolled-back saga with an overridden context when one is given', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::RolledBack, payload: ['x' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->retry('saga-1', CoordinatorTestTwoStepWorkflow::class, ['x' => 2]);

    expect($state->context()->raw())->toBe(['x' => 2]);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) {
        return $job->context === ['x' => 2];
    });
});

it('refuses to retry a saga that is not rolled back', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->retry('saga-1', CoordinatorTestTwoStepWorkflow::class);
})->throws(SagaNotRetryableException::class);

it('throws SagaNotFoundException when retrying a saga that does not exist', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->retry('missing-saga', CoordinatorTestTwoStepWorkflow::class);
})->throws(SagaNotFoundException::class);

it('retries a stuck CompensationFailed step, self-compensating', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationFailed, payload: ['refund' => 5]));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->retryCompensation('saga-1', CoordinatorTestSelfCompensatingWorkflow::class);

    expect($state->status)->toBe(SagaStepStatus::CompensationPending)
        ->and($state->step)->toBe(0)
        ->and($state->context()->raw())->toBe(['refund' => 5]);

    Bus::assertDispatched(CoordinatorTestSelfCompensatingStep::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0 && $job->context === ['refund' => 5];
    });
});

it('retries a stuck CompensationFailed step, external compensator', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationFailed, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->retryCompensation('saga-1', CoordinatorTestCompensatingWorkflow::class);

    expect($state->status)->toBe(SagaStepStatus::CompensationPending);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, function ($job) {
        return $job->sagaId === 'saga-1' && $job->sagaStepIndex === 0 && $job->context === ['order' => 1];
    });
});

it('refuses to retry a compensation that is not stuck at CompensationFailed', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Compensated));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->retryCompensation('saga-1', CoordinatorTestSelfCompensatingWorkflow::class);
})->throws(SagaNotRetryableException::class);

it('throws SagaNotFoundException when retrying compensation for a saga that does not exist', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->retryCompensation('missing-saga', CoordinatorTestSelfCompensatingWorkflow::class);
})->throws(SagaNotFoundException::class);

it('throws when retrying compensation for a step stuck CompensationFailed that has no compensator', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationFailed));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->retryCompensation('saga-1', CoordinatorTestOneStepWorkflow::class);
})->throws(LogicException::class, 'is CompensationFailed but has no compensator to retry.');

it('throws when signalling a Waiting step whose record has no recorded workflow', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, workflow: null, signal: 'approval'));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->signal('saga-1', 'approval');
})->throws(LogicException::class, 'has no recorded workflow.');

it('throws when signalling a CompensationWaiting step whose step has no compensator', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationWaiting, workflow: CoordinatorTestOneStepWorkflow::class, signal: 'approval'));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->signal('saga-1', 'approval');
})->throws(LogicException::class, 'is CompensationWaiting but has no compensator to resume.');

it('throws when beginSaga cannot read back the step 0 record it just stored', function () {
    $driver = new class extends InMemoryDriver
    {
        public function store(SagaStepRecord $record): void
        {
            // intentionally does not persist, to simulate a broken driver
        }
    };
    $coordinator = new SagaCoordinator($driver);

    $coordinator->start(CoordinatorTestOneStepWorkflow::class, null);
})->throws(LogicException::class, 'was not found immediately after being stored.');

it('throws when compensating a saga whose record has no recorded workflow', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: null));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->compensate('saga-1');
})->throws(LogicException::class, 'has no recorded workflow.');

it('throws when iterating a compensating step that has no compensator', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->stepIterated('saga-1', CoordinatorTestOneStepWorkflow::class, 0, null, compensating: true);
})->throws(LogicException::class, 'is iterating while compensating but has no compensator.');

it('throws when failDueSignals() is given a record with no recorded workflow', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $due = new SagaStepRecords([
        new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, workflow: null, signal: 'approval'),
    ]);

    $coordinator->failDueSignals($due);
})->throws(LogicException::class, 'has no recorded workflow.');

it('throws when failDueSignals() is given a record with no recorded signal name', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $due = new SagaStepRecords([
        new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, workflow: CoordinatorTestOneStepWorkflow::class, signal: null),
    ]);

    $coordinator->failDueSignals($due);
})->throws(LogicException::class, 'is due but has no recorded signal name.');

it('throws when failDueRunning() is given a record with no recorded workflow', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $due = new SagaStepRecords([
        new SagaStepRecord('saga-1', 0, SagaStepStatus::Running, workflow: null),
    ]);

    $coordinator->failDueRunning($due);
})->throws(LogicException::class, 'has no recorded workflow.');

it('throws when a parallel group\'s branch has no recorded state left to merge', function () {
    $driver = new class extends InMemoryDriver
    {
        private array $seen = [];

        public function latestFor(string $sagaId, int $step, int $branch = 0): ?SagaStepRecord
        {
            $key = "{$sagaId}:{$step}:{$branch}";

            if (isset($this->seen[$key])) {
                return null;
            }

            $this->seen[$key] = true;

            return parent::latestFor($sagaId, $step, $branch);
        }
    };
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, branch: 0));
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, branch: 1));

    $coordinator = new SagaCoordinator($driver);
    $entry = new Parallel(CoordinatorTestStepOne::class, CoordinatorTestStepTwo::class);

    $method = new ReflectionMethod($coordinator, 'checkGroupForward');
    $method->invoke($coordinator, 'saga-1', CoordinatorTestOneStepWorkflow::class, 0, $entry, false);
})->throws(LogicException::class, 'completed but has no recorded state.');

it('throws when the SIGINT handler fires with no interrupted saga recorded', function () {
    if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
        test()->markTestSkipped('Requires the pcntl and posix extensions.');
    }

    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $install = new ReflectionMethod($coordinator, 'installInterruptHandler');
    $installed = $install->invoke($coordinator, 'saga-1', true);
    expect($installed)->toBeTrue();

    $interruptedSagaId = new ReflectionProperty(SagaCoordinator::class, 'interruptedSagaId');
    $interruptedSagaId->setValue(null, null);

    try {
        expect(fn () => posix_kill(posix_getpid(), SIGINT))
            ->toThrow(LogicException::class, 'SIGINT handler fired with no interrupted saga recorded.');
    } finally {
        $remove = new ReflectionMethod($coordinator, 'removeInterruptHandler');
        $remove->invoke($coordinator);
    }
});
