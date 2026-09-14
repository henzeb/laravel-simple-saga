<?php

use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\SagaCoordinator;
use Illuminate\Support\Facades\Bus;
use Tests\Support\CoordinatorTestParallelAfterStep;
use Tests\Support\CoordinatorTestParallelBeforeRefundStep;
use Tests\Support\CoordinatorTestParallelBranchA;
use Tests\Support\CoordinatorTestParallelBranchB;
use Tests\Support\CoordinatorTestParallelFailFastWorkflow;
use Tests\Support\CoordinatorTestParallelQueuedWorkflow;
use Tests\Support\CoordinatorTestParallelWorkflow;
use Tests\Support\InMemoryDriver;

beforeEach(function () {
    Bus::fake();
});

it('waits for every branch of a group before advancing, merging their payloads by branch index', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestParallelWorkflow::class));

    $coordinator->stepCompleted('saga-1', CoordinatorTestParallelWorkflow::class, 1, ['branch' => 'a'], branch: 0);

    expect($driver->latestFor('saga-1', 2))->toBeNull();

    $coordinator->stepCompleted('saga-1', CoordinatorTestParallelWorkflow::class, 1, ['branch' => 'b'], branch: 1);

    $record = $driver->latestFor('saga-1', 2);

    expect($record->status)->toBe(SagaStepStatus::Pending)
        ->and($record->payload)->toBe([0 => ['branch' => 'a'], 1 => ['branch' => 'b']]);

    Bus::assertDispatched(CoordinatorTestParallelAfterStep::class);
});

it('under the default policy, only compensates a completed sibling once every branch is terminal, then cascades', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestParallelWorkflow::class));

    // Branch 1 (has a compensator) completes; branch 0 (no compensator) then fails.
    $coordinator->stepCompleted('saga-1', CoordinatorTestParallelWorkflow::class, 1, ['branch' => 'b'], branch: 1);
    $coordinator->stepFailed('saga-1', CoordinatorTestParallelWorkflow::class, 1, new RuntimeException('a failed'), branch: 0);

    expect($driver->latestFor('saga-1', 1, 0)->status)->toBe(SagaStepStatus::Failed)
        ->and($driver->latestFor('saga-1', 1, 1)->status)->toBe(SagaStepStatus::CompensationPending);

    Bus::assertDispatched(CoordinatorTestParallelBranchB::class);
    Bus::assertNotDispatched(CoordinatorTestParallelBeforeRefundStep::class);

    // The dispatched compensator finishing is what settles the group and cascades backward.
    $coordinator->stepCompleted('saga-1', CoordinatorTestParallelWorkflow::class, 1, ['refunded' => true], compensating: true, branch: 1);

    expect($driver->latestFor('saga-1', 1, 1)->status)->toBe(SagaStepStatus::Compensated);
    Bus::assertDispatched(CoordinatorTestParallelBeforeRefundStep::class);
});

it('under the default policy, does not compensate anything while a sibling is still running', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestParallelWorkflow::class));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Pending, branch: 1, workflow: CoordinatorTestParallelWorkflow::class));

    $coordinator->stepFailed('saga-1', CoordinatorTestParallelWorkflow::class, 1, new RuntimeException('a failed'), branch: 0);

    Bus::assertNothingDispatched();
    expect($driver->latestFor('saga-1', 1, 0)->status)->toBe(SagaStepStatus::Failed);

    // The straggler finishing afterward is what reconciles: it gets compensated
    // instead of being treated as a forward completion.
    $coordinator->stepCompleted('saga-1', CoordinatorTestParallelWorkflow::class, 1, ['branch' => 'b'], branch: 1);

    expect($driver->latestFor('saga-1', 1, 1)->status)->toBe(SagaStepStatus::CompensationPending);
    Bus::assertDispatched(CoordinatorTestParallelBranchB::class);
    Bus::assertNotDispatched(CoordinatorTestParallelAfterStep::class);
});

it('with queued(), dispatches a group onto the real queue even when the saga is otherwise run sync', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestParallelQueuedWorkflow::class, 0, ['seed' => true], sync: true);

    Bus::assertDispatched(
        CoordinatorTestParallelBranchA::class,
        fn ($job) => $job->sync === false,
    );
    Bus::assertDispatched(
        CoordinatorTestParallelBranchB::class,
        fn ($job) => $job->sync === false,
    );
});

it('with failFast(), compensates a completed sibling immediately without waiting for a still-running branch', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, workflow: CoordinatorTestParallelFailFastWorkflow::class));

    $coordinator->stepFailed('saga-1', CoordinatorTestParallelFailFastWorkflow::class, 1, new RuntimeException('a failed'), branch: 0);

    // Nothing to compensate yet — branch 1 hasn't completed.
    Bus::assertNothingDispatched();

    // Branch 1 finishes after the group already started failing: it gets
    // compensated immediately in place of being treated as a completion.
    $coordinator->stepCompleted('saga-1', CoordinatorTestParallelFailFastWorkflow::class, 1, ['branch' => 'b'], branch: 1);

    expect($driver->latestFor('saga-1', 1, 1)->status)->toBe(SagaStepStatus::CompensationPending);
    Bus::assertDispatched(CoordinatorTestParallelBranchB::class);
    Bus::assertNotDispatched(CoordinatorTestParallelAfterStep::class);
});
