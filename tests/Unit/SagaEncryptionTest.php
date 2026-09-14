<?php

use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\SagaCoordinator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\CoordinatorTestEncryptedWorkflow;
use Tests\Support\CoordinatorTestStepOne;
use Tests\Support\CoordinatorTestStepTwo;
use Tests\Support\InMemoryDriver;

beforeEach(function () {
    Bus::fake();
});

it('stores an encrypted workflow\'s context as ciphertext, not plain', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->start(new CoordinatorTestEncryptedWorkflow(), ['orderId' => 'abc']);

    $record = $driver->latestFor($state->sagaId, 0);

    expect($record->encrypted)->toBeTrue()
        ->and($record->payload)->toBeString()
        ->and($record->payload)->not->toBe(['orderId' => 'abc']);
});

it('transparently decrypts context when read back through SagaState', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->start(new CoordinatorTestEncryptedWorkflow(), ['orderId' => 'abc']);

    expect($state->context()->raw())->toBe(['orderId' => 'abc']);
});

it('hands the dispatched step decrypted context, not ciphertext', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->start(new CoordinatorTestEncryptedWorkflow(), ['orderId' => 'abc']);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) {
        return $job->context === ['orderId' => 'abc'];
    });
});

it('keeps the context encrypted across every step of the saga', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $coordinator->stepCompleted('saga-1', CoordinatorTestEncryptedWorkflow::class, 0, ['orderId' => 'abc']);

    $record = $driver->latestFor('saga-1', 1);

    expect($record->status)->toBe(SagaStepStatus::Pending)
        ->and($record->encrypted)->toBeTrue()
        ->and($record->payload)->toBeString();

    Bus::assertDispatched(CoordinatorTestStepTwo::class, function ($job) {
        return $job->context === ['orderId' => 'abc'];
    });
});

it('can decrypt the payload with Laravel\'s own Crypt facade, proving it is real encryption', function () {
    $driver = new InMemoryDriver();
    $coordinator = new SagaCoordinator($driver);

    $state = $coordinator->start(new CoordinatorTestEncryptedWorkflow(), ['orderId' => 'abc']);

    $record = $driver->latestFor($state->sagaId, 0);

    expect(Crypt::decrypt($record->payload))->toBe(['orderId' => 'abc']);
});
