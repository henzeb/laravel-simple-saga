<?php

use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Facades\Saga;
use Tests\Support\CoordinatorTestCompensatingWorkflow;
use Tests\Support\CoordinatorTestMutualCompensatorWorkflow;
use Tests\Support\CoordinatorTestOneStepWorkflow;
use Tests\Support\CoordinatorTestRetryWorkflow;
use Tests\Support\CoordinatorTestSyncQueuedWorkflow;
use Tests\Support\ProcessesSagaTestIterableWorkflow;

/*
 * Every other test in this package either fakes the bus/queue or calls
 * ProcessesSaga::handle() directly — both bypass the real container resolution
 * and the real Bus::dispatch()/dispatchNow() routing a step actually goes
 * through. These run a saga for real, with no Bus::fake() and no manually
 * bound Driver, to catch exactly that class of bug.
 */
beforeEach(function () {
    $this->artisan('migrate')->run();
});

it('runs a plain, non-ShouldQueue step to completion', function () {
    $state = Saga::workflow(new CoordinatorTestOneStepWorkflow())->start(['x' => 1]);

    expect($state->status)->toBe(SagaStepStatus::Completed);
});

it('runs a ShouldQueue step to completion in sync mode', function () {
    $state = Saga::workflow(new CoordinatorTestRetryWorkflow())->start(['x' => 1], sync: true);

    expect($state->status)->toBe(SagaStepStatus::Completed);
});

it('runs a workflow mixing plain and ShouldQueue steps to completion', function () {
    $state = Saga::workflow(new CoordinatorTestSyncQueuedWorkflow())->start(['x' => 1], sync: true);

    expect($state->status)->toBe(SagaStepStatus::Completed);
});

it('runs a compensating workflow forward to completion for real', function () {
    $state = Saga::workflow(new CoordinatorTestCompensatingWorkflow())->start(['order' => 1], sync: true);

    expect($state->status)->toBe(SagaStepStatus::Completed);
});

it('compensates two steps that use each other as their handle()-based compensator', function () {
    $workflow = Saga::workflow(new CoordinatorTestMutualCompensatorWorkflow(), 'mutual-compensator-saga');

    $completed = $workflow->start(['x' => 1], sync: true);

    expect($completed->status)->toBe(SagaStepStatus::Completed);

    $rolledBack = $workflow->compensate(sync: true);

    expect($rolledBack->status)->toBe(SagaStepStatus::RolledBack);
});

it('completes every iteration of a non-ShouldQueue IterableSagaStep run with sync: true', function () {
    // Regression test: sync mode dispatches inline, so the redispatch stepIterated()
    // triggers for the next iteration happens from further down the very call stack
    // that's still inside the current iteration's dispatch() — same saga, same step
    // index, same dispatch lock key. If that lock were held for the whole nested
    // call (rather than released right after being acquired), this redispatch would
    // find it already taken and silently no-op, leaving the saga stuck mid-iteration
    // with no error at all.
    $state = Saga::workflow(new ProcessesSagaTestIterableWorkflow())->start(['page' => 1], sync: true);

    expect($state->status)->toBe(SagaStepStatus::Completed);
});
