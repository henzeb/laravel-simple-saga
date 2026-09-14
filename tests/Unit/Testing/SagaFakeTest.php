<?php

use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Facades\Saga;
use Illuminate\Support\Facades\Bus;
use Tests\Support\CoordinatorTestOneStepWorkflow;
use Tests\Support\CoordinatorTestStepOne;

it('prevents the step job from actually running', function () {
    $fake = Saga::fake();

    $workflow = Saga::workflow(CoordinatorTestOneStepWorkflow::class);
    $workflow->start(null, sync: true);

    $fake->assertStarted($workflow->id())
        ->assertStatus($workflow->id(), SagaStepStatus::Pending);

    Bus::assertDispatched(CoordinatorTestStepOne::class);
});

it('lets a step job actually run when excepted', function () {
    $fake = Saga::fake()->except([CoordinatorTestStepOne::class]);

    $workflow = Saga::workflow(CoordinatorTestOneStepWorkflow::class);
    $workflow->start(null, sync: true);

    $fake->assertCompleted($workflow->id());
});

it('needs no real database or cache store configured', function () {
    $fake = Saga::fake();

    config()->set('saga.drivers.database.connection', 'this-connection-does-not-exist');

    $workflow = Saga::workflow(CoordinatorTestOneStepWorkflow::class);
    $workflow->start(null, sync: true);

    $fake->assertStatus($workflow->id(), SagaStepStatus::Pending);
});

it('fails the assertion when the saga is not in the expected status', function () {
    $fake = Saga::fake();

    $workflow = Saga::workflow(CoordinatorTestOneStepWorkflow::class);
    $workflow->start(null, sync: true);

    expect(fn () => $fake->assertStatus($workflow->id(), SagaStepStatus::Completed))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});
