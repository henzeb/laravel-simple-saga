<?php

use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\DTO\SagaTrail;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\SagaCoordinator;
use Henzeb\Saga\SagaWorkflow;
use Tests\Support\CoordinatorTestOneStepWorkflow;

function saga_workflow_test_state(SagaStepStatus $status): SagaState
{
    return new SagaState(
        sagaId: 'saga-1',
        workflow: CoordinatorTestOneStepWorkflow::class,
        status: $status,
        step: 0,
        contextResolver: fn () => new SagaContext(null),
        reason: null,
        trailResolver: fn () => new SagaTrail(),
    );
}

it('delegates start() to the coordinator with its bound workflow', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Pending);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('start')->once()->with($workflow, ['x' => 1], true, Mockery::type('string'))->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow))->start(['x' => 1], sync: true);

    expect($result)->toBe($state);
});

it('generates a sagaId the first time id() is called, and reuses it on every later call and on start()', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Pending);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $sagaWorkflow = new SagaWorkflow($coordinator, $workflow);

    $id = $sagaWorkflow->id();

    expect($id)->toBeString()->and($sagaWorkflow->id())->toBe($id);

    $coordinator->shouldReceive('start')->once()->with($workflow, ['x' => 1], false, $id)->andReturn($state);

    $result = $sagaWorkflow->start(['x' => 1]);

    expect($result)->toBe($state)
        ->and($sagaWorkflow->id())->toBe($id);
});

it('resolves id() deterministically from a constructor-bound idempotency key, without asking the coordinator to start anything', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');

    $sagaWorkflow = new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123');

    expect($sagaWorkflow->id())->toBe('hashed-id')
        ->and($sagaWorkflow->id())->toBe('hashed-id'); // cached, sagaIdFor() only called once
});

it('starts using the idempotency key bound at construction, without it being passed to start() again', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Pending);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('start')->once()->with($workflow, ['x' => 1], false, 'hashed-id')->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->start(['x' => 1]);

    expect($result)->toBe($state);
});

it('delegates current() to the coordinator against its resolved id', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Completed);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('current')->once()->with('hashed-id')->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->current();

    expect($result)->toBe($state);
});

it('delegates label() to the coordinator against its resolved id', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Completed);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('current')->once()->with('hashed-id')->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->label();

    expect($result)->toBe('CoordinatorTestStepOne');
});

it('refuses to label() without a sagaId bound via Saga::workflow()', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();

    $coordinator = Mockery::mock(SagaCoordinator::class);

    (new SagaWorkflow($coordinator, $workflow))->label();
})->throws(LogicException::class);

it('delegates signal() to the coordinator against its resolved id', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Pending);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('signal')->once()->with('hashed-id', 'approval', ['approved' => true], true)->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->signal('approval', ['approved' => true], sync: true);

    expect($result)->toBe($state);
});

it('delegates compensate() to the coordinator against its resolved id', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Failed);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('compensate')->once()->with('hashed-id', false)->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->compensate();

    expect($result)->toBe($state);
});

it('delegates compensate(sync: true) to the coordinator against its resolved id', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Failed);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('compensate')->once()->with('hashed-id', true)->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->compensate(sync: true);

    expect($result)->toBe($state);
});

it('delegates delete() to the coordinator against its resolved id', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('delete')->once()->with('hashed-id');

    (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->delete();
});

it('delegates retry() to the coordinator, resolving its bound sagaId first', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::Pending);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('retry')->once()->with('hashed-id', $workflow, ['x' => 2], true)->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->retry(['x' => 2], sync: true);

    expect($result)->toBe($state);
});

it('delegates retryCompensation() to the coordinator, resolving its bound sagaId first', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();
    $state = saga_workflow_test_state(SagaStepStatus::CompensationPending);

    $coordinator = Mockery::mock(SagaCoordinator::class);
    $coordinator->shouldReceive('sagaIdFor')->once()->with($workflow, 'order-123')->andReturn('hashed-id');
    $coordinator->shouldReceive('retryCompensation')->once()->with('hashed-id', $workflow, true)->andReturn($state);

    $result = (new SagaWorkflow($coordinator, $workflow, sagaId: 'order-123'))->retryCompensation(sync: true);

    expect($result)->toBe($state);
});

it('refuses to retry() without a sagaId bound via Saga::workflow()', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();

    $coordinator = Mockery::mock(SagaCoordinator::class);

    (new SagaWorkflow($coordinator, $workflow))->retry();
})->throws(LogicException::class);

it('refuses to retryCompensation() without a sagaId bound via Saga::workflow()', function () {
    $workflow = new CoordinatorTestOneStepWorkflow();

    $coordinator = Mockery::mock(SagaCoordinator::class);

    (new SagaWorkflow($coordinator, $workflow))->retryCompensation();
})->throws(LogicException::class);
