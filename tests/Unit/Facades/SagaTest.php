<?php

use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\Facades\Saga;
use Henzeb\Saga\SagaManager;
use Illuminate\Support\Facades\Bus;
use Tests\Support\CoordinatorTestOneStepWorkflow;

it('resolves to the saga manager singleton', function () {
    expect(Saga::getFacadeRoot())->toBeInstanceOf(SagaManager::class);
});

it('starts and gets a saga through the facade', function () {
    Bus::fake();
    $this->artisan('migrate')->run();

    $workflow = Saga::workflow(new CoordinatorTestOneStepWorkflow(), 'order-1');
    $state = $workflow->start(['x' => 1]);

    expect($state)->toBeInstanceOf(SagaState::class);
    expect($workflow->current()->sagaId)->toBe($state->sagaId);
});
