<?php

use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\Exceptions\AwaitingSignalException;

it('wraps its raw context in a SagaContext', function () {
    $job = new class {
        use InteractsWithSaga;

        public function callContext(): SagaContext
        {
            return $this->context();
        }
    };

    $job->context = ['orderId' => 'abc'];

    $context = $job->callContext();

    expect($context)->toBeInstanceOf(SagaContext::class)
        ->and($context->raw())->toBe(['orderId' => 'abc']);
});

function interactsWithSagaTestJob(): object
{
    return new class {
        use InteractsWithSaga;

        public function callWaitFor(string $name): SagaContext
        {
            return $this->waitFor($name);
        }

        public function callIdempotencyKey(?string $name = null): string
        {
            return $this->idempotencyKey($name);
        }
    };
}

it('builds an idempotency key from sagaId, step, and branch', function () {
    $job = interactsWithSagaTestJob();
    $job->sagaId = 'saga-1';
    $job->sagaStepIndex = 2;
    $job->branch = 1;

    expect($job->callIdempotencyKey())->toBe('saga:saga-1:2:1');
});

it('appends an optional name to the idempotency key', function () {
    $job = interactsWithSagaTestJob();
    $job->sagaId = 'saga-1';
    $job->sagaStepIndex = 0;

    expect($job->callIdempotencyKey('charge'))->toBe('saga:saga-1:0:0:charge');
});

it('produces the same idempotency key across repeated calls for the same job state', function () {
    $job = interactsWithSagaTestJob();
    $job->sagaId = 'saga-1';
    $job->sagaStepIndex = 3;

    expect($job->callIdempotencyKey())->toBe($job->callIdempotencyKey());
});

it('defaults deliveredSignal to null', function () {
    expect(interactsWithSagaTestJob()->deliveredSignal)->toBeNull();
});

it('throws AwaitingSignalException carrying the signal name when no matching signal was delivered', function () {
    interactsWithSagaTestJob()->callWaitFor('approval');
})->throws(AwaitingSignalException::class, "Waiting for signal 'approval'.");

it('carries the signal name on the thrown AwaitingSignalException', function () {
    $job = interactsWithSagaTestJob();

    try {
        $job->callWaitFor('approval');
    } catch (AwaitingSignalException $exception) {
        expect($exception->signal)->toBe('approval');

        return;
    }

    $this->fail('Expected AwaitingSignalException was not thrown.');
});

it('still throws when deliveredSignal is set to a different name', function () {
    $job = interactsWithSagaTestJob();
    $job->deliveredSignal = 'something-else';
    $job->context = ['x' => 1];

    $job->callWaitFor('approval');
})->throws(AwaitingSignalException::class);

it('returns the signal\'s own payload wrapped in a SagaContext once deliveredSignal matches the awaited name, leaving context untouched', function () {
    $job = interactsWithSagaTestJob();
    $job->context = ['order' => 1];
    $job->deliveredSignal = 'approval';
    $job->deliveredSignalPayload = ['approved' => true];

    $context = $job->callWaitFor('approval');

    expect($context)->toBeInstanceOf(SagaContext::class)
        ->and($context->raw())->toBe(['approved' => true])
        ->and($job->context)->toBe(['order' => 1]);
});

it('returns a SagaContext wrapping null when the delivered signal carried no payload', function () {
    $job = interactsWithSagaTestJob();
    $job->deliveredSignal = 'approval';

    $context = $job->callWaitFor('approval');

    expect($context)->toBeInstanceOf(SagaContext::class)
        ->and($context->raw())->toBeNull();
});
