<?php

use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\SagaStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

it('does not require ShouldQueue, so it can run synchronously', function () {
    $step = new class extends SagaStep {
        public function handle(): string
        {
            return 'done';
        }
    };

    expect($step)->not->toBeInstanceOf(ShouldQueue::class)
        ->and(class_uses_recursive($step))->toContain(Dispatchable::class);
});

it('carries the saga state a step needs, and can opt into ShouldQueue', function () {
    $step = new class extends SagaStep implements ShouldQueue {
        public function handle(): string
        {
            return 'done';
        }
    };

    expect($step)->toBeInstanceOf(ShouldQueue::class);

    $step->sagaId = 'saga-1';
    $step->workflow = 'App\Workflows\Checkout';
    $step->sagaStepIndex = 0;
    $step->context = ['orderId' => 'abc'];

    expect($step->handle())->toBe('done');

    $context = (fn () => $this->context())->call($step);

    expect($context)->toBeInstanceOf(SagaContext::class)
        ->and($context->raw())->toBe(['orderId' => 'abc']);
});
