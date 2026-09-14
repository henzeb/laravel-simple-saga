<?php

use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Events\SagaCompensated;
use Henzeb\Saga\Events\SagaCompensating;
use Henzeb\Saga\Events\SagaRolledBack;
use Henzeb\Saga\Events\SagaStepCompensated;
use Henzeb\Saga\Events\SagaStepCompensating;
use Henzeb\Saga\Facades\Saga;
use Illuminate\Support\Facades\Event;
use Tests\Support\CoordinatorTestInterruptibleWorkflow;

/*
 * Ctrl+C during a synchronous, console-mode run should compensate the saga
 * instead of just killing the process. CoordinatorTestInterruptedStepTwo
 * raises SIGINT at itself to stand in for the real thing.
 */
beforeEach(function () {
    $this->artisan('migrate')->run();
});

it('compensates a saga interrupted by SIGINT during a sync console run', function () {
    // Bus isn't faked here — this exercises the real ProcessesSaga middleware,
    // which is the only place SagaStepCompensating/SagaStepCompensated fire from.
    Event::fake([
        SagaCompensating::class, SagaStepCompensating::class,
        SagaStepCompensated::class, SagaCompensated::class, SagaRolledBack::class,
    ]);

    $state = Saga::workflow(new CoordinatorTestInterruptibleWorkflow())->start(['order' => 1], sync: true);

    expect($state->status)->toBe(SagaStepStatus::RolledBack);

    Event::assertDispatched(SagaCompensating::class);
    Event::assertDispatched(SagaStepCompensating::class, fn ($event) => $event->state->step === 0);
    Event::assertDispatched(SagaStepCompensated::class, fn ($event) => $event->state->step === 0);
    Event::assertDispatched(SagaCompensated::class);
    Event::assertDispatched(SagaRolledBack::class);
});
