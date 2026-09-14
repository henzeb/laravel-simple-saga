<?php

use Henzeb\Saga\DTO\SagaStepRecord;
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
use Henzeb\Saga\SagaCoordinator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Support\CoordinatorTestCompensatingWorkflow;
use Tests\Support\CoordinatorTestNoCompensatorWorkflow;
use Tests\Support\CoordinatorTestOneStepWorkflow;
use Tests\Support\CoordinatorTestTwoStepWorkflow;
use Tests\Support\InMemoryDriver;

beforeEach(function () {
    Bus::fake();
    Event::fake();
});

it('dispatches SagaStarted when a saga starts', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->start(new CoordinatorTestOneStepWorkflow(), ['x' => 1]);

    Event::assertDispatched(SagaStarted::class, function (SagaStarted $event) {
        return $event->state->workflow === CoordinatorTestOneStepWorkflow::class
            && $event->state->context()->raw() === ['x' => 1];
    });
});

it('dispatches SagaStepStarted when a forward step is marked running', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->markRunning('saga-1', CoordinatorTestOneStepWorkflow::class, 0);

    Event::assertDispatched(SagaStepStarted::class, function (SagaStepStarted $event) {
        return $event->state->sagaId === 'saga-1'
            && $event->state->workflow === CoordinatorTestOneStepWorkflow::class
            && $event->state->step === 0
            && $event->state->status === SagaStepStatus::Running;
    });
});

it('dispatches SagaStepCompensating when a compensator is marked compensating', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->markCompensating('saga-1', CoordinatorTestCompensatingWorkflow::class, 0);

    Event::assertDispatched(SagaStepCompensating::class, function (SagaStepCompensating $event) {
        return $event->state->sagaId === 'saga-1'
            && $event->state->step === 0
            && $event->state->status === SagaStepStatus::Compensating;
    });
    Event::assertNotDispatched(SagaStepStarted::class);
});

it('dispatches SagaStepCompleted when a forward step completes', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->stepCompleted('saga-1', CoordinatorTestTwoStepWorkflow::class, 0, ['x' => 1]);

    Event::assertDispatched(SagaStepCompleted::class, function (SagaStepCompleted $event) {
        return $event->state->sagaId === 'saga-1'
            && $event->state->workflow === CoordinatorTestTwoStepWorkflow::class
            && $event->state->step === 0
            && $event->state->context()->raw() === ['x' => 1];
    });
});

it('dispatches SagaCompleted once the last step finishes, instead of SagaStepCompleted only', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->stepCompleted('saga-1', CoordinatorTestOneStepWorkflow::class, 0, ['x' => 1]);

    Event::assertDispatched(SagaStepCompleted::class);
    Event::assertDispatched(SagaCompleted::class, function (SagaCompleted $event) {
        return $event->state->sagaId === 'saga-1' && $event->state->context()->raw() === ['x' => 1];
    });
});

it('dispatches SagaStepFailed with the state and the exception when a forward step fails', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $exception = new RuntimeException('boom');
    $coordinator->stepFailed('saga-1', CoordinatorTestOneStepWorkflow::class, 0, $exception);

    Event::assertDispatched(SagaStepFailed::class, function (SagaStepFailed $event) use ($exception) {
        return $event->state->sagaId === 'saga-1' && $event->state->step === 0 && $event->exception === $exception;
    });
});

it('dispatches SagaStepIterated when a step is iterated', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->stepIterated('saga-1', CoordinatorTestOneStepWorkflow::class, 0, ['x' => 1]);

    Event::assertDispatched(SagaStepIterated::class, function (SagaStepIterated $event) {
        return $event->state->sagaId === 'saga-1'
            && $event->state->workflow === CoordinatorTestOneStepWorkflow::class
            && $event->state->step === 0;
    });
    Event::assertNotDispatched(SagaStepCompleted::class);
});

it('dispatches SagaStepAwaitingSignal when a step is parked awaiting a signal', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->stepAwaitsSignal('saga-1', CoordinatorTestOneStepWorkflow::class, 0, 'approval', ['x' => 1]);

    Event::assertDispatched(SagaStepAwaitingSignal::class, function (SagaStepAwaitingSignal $event) {
        return $event->state->sagaId === 'saga-1'
            && $event->state->workflow === CoordinatorTestOneStepWorkflow::class
            && $event->state->step === 0
            && $event->state->signal === 'approval';
    });
    Event::assertNotDispatched(SagaStepCompleted::class);
});

it('dispatches SagaCompensating once a forward failure has something to compensate', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestCompensatingWorkflow::class, 1, new RuntimeException('boom'));

    Event::assertDispatched(SagaCompensating::class, function (SagaCompensating $event) {
        return $event->state->sagaId === 'saga-1' && $event->state->step === 1;
    });
});

it('does not dispatch SagaCompensating for a forward failure at the first step, with nothing to compensate', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->stepFailed('saga-1', CoordinatorTestOneStepWorkflow::class, 0, new RuntimeException('boom'));

    Event::assertNotDispatched(SagaCompensating::class);
});

it('dispatches SagaCompensating when manually compensating a saga that already completed', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestCompensatingWorkflow::class));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->compensate('saga-1');

    Event::assertDispatched(SagaCompensating::class, function (SagaCompensating $event) {
        return $event->state->sagaId === 'saga-1' && $event->state->step === 0;
    });
});

it('dispatches SagaStepCompensated when a compensator actually finishes', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Compensating, payload: ['order' => 1]));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestCompensatingWorkflow::class, 0, ['order' => 1], compensating: true);

    Event::assertDispatched(SagaStepCompensated::class, function (SagaStepCompensated $event) {
        return $event->state->sagaId === 'saga-1' && $event->state->step === 0;
    });
});

it('dispatches neither SagaStepCompensating nor SagaStepCompensated for a step with no compensator', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepFailed('saga-1', CoordinatorTestNoCompensatorWorkflow::class, 1, new RuntimeException('boom'));

    Event::assertNotDispatched(SagaStepCompensating::class);
    Event::assertNotDispatched(SagaStepCompensated::class);
    Event::assertDispatched(SagaCompensated::class);
    Event::assertDispatched(SagaRolledBack::class);
});

it('dispatches SagaCompensated and SagaRolledBack once compensation walks past the first step', function () {
    $coordinator = new SagaCoordinator(new InMemoryDriver());

    $coordinator->stepCompleted('saga-1', CoordinatorTestOneStepWorkflow::class, 0, null, compensating: true);

    Event::assertDispatched(SagaCompensated::class, function (SagaCompensated $event) {
        return $event->state->sagaId === 'saga-1' && $event->state->workflow === CoordinatorTestOneStepWorkflow::class;
    });
    Event::assertDispatched(SagaRolledBack::class, function (SagaRolledBack $event) {
        return $event->state->sagaId === 'saga-1' && $event->state->workflow === CoordinatorTestOneStepWorkflow::class;
    });
});

it('dispatches SagaCompensationFailed with the state and the exception when a compensator fails', function () {
    $driver = new InMemoryDriver();
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Compensating));
    $coordinator = new SagaCoordinator($driver);

    $exception = new RuntimeException('refund failed');
    $coordinator->stepFailed('saga-1', CoordinatorTestCompensatingWorkflow::class, 0, $exception);

    Event::assertDispatched(SagaCompensationFailed::class, function (SagaCompensationFailed $event) use ($exception) {
        return $event->state->sagaId === 'saga-1' && $event->state->step === 0 && $event->exception === $exception;
    });
});
