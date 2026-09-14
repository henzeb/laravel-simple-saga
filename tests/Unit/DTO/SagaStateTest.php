<?php

use Henzeb\Saga\Attributes\StepLabel;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\DTO\SagaTrail;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\StepLabelResolver;
use Henzeb\Saga\Workflow;

#[StepLabel('Charge Payment')]
class SagaStateTestLabeledStep
{
    use InteractsWithSaga;
}

class SagaStateTestLabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [SagaStateTestLabeledStep::class];
    }
}

function saga_state_test_instance(?Closure $contextResolver = null, ?Closure $trailResolver = null, ?string $workflow = null, int $step = 0): SagaState
{
    return new SagaState(
        sagaId: 'saga-1',
        workflow: $workflow,
        status: SagaStepStatus::Pending,
        step: $step,
        contextResolver: $contextResolver ?? fn () => new SagaContext(null),
        reason: null,
        trailResolver: $trailResolver ?? fn () => new SagaTrail(),
    );
}

it('does not call the context resolver until context() is called', function () {
    $called = false;

    saga_state_test_instance(contextResolver: function () use (&$called) {
        $called = true;

        return new SagaContext(null);
    });

    expect($called)->toBeFalse();
});

it('memoizes the resolved context, calling the resolver only once', function () {
    $calls = 0;

    $state = saga_state_test_instance(contextResolver: function () use (&$calls) {
        $calls++;

        return new SagaContext(['x' => 1]);
    });

    $state->context();
    $state->context();

    expect($calls)->toBe(1);
});

it('does not call the trail resolver until trail() is called', function () {
    $called = false;

    saga_state_test_instance(trailResolver: function () use (&$called) {
        $called = true;

        return new SagaTrail();
    });

    expect($called)->toBeFalse();
});

it('memoizes the resolved trail, calling the resolver only once', function () {
    $calls = 0;

    $state = saga_state_test_instance(trailResolver: function () use (&$calls) {
        $calls++;

        return new SagaTrail(['x']);
    });

    $state->trail();
    $state->trail();

    expect($calls)->toBe(1);
});

it('returns the trail as a SagaTrail', function () {
    $state = saga_state_test_instance(trailResolver: fn () => new SagaTrail(['a', 'b']));

    expect($state->trail())->toBeInstanceOf(SagaTrail::class)
        ->and($state->trail())->toHaveCount(2);
});

it('resolves its label through the container-bound StepLabelResolver, with no prefix', function () {
    $state = saga_state_test_instance(workflow: SagaStateTestLabeledWorkflow::class, step: 0);

    expect($state->label())->toBe('Charge Payment');
});

it('memoizes the resolved label, resolving StepLabelResolver only once', function () {
    $calls = 0;

    app()->bind(StepLabelResolver::class, function () use (&$calls) {
        $calls++;

        return new class extends StepLabelResolver
        {
            public function resolve(SagaState $state): ?string
            {
                return 'Custom Label';
            }
        };
    });

    $state = saga_state_test_instance();

    expect($state->label())->toBe('Custom Label')
        ->and($state->label())->toBe('Custom Label')
        ->and($calls)->toBe(1);
});

it('memoizes a null label, without re-resolving StepLabelResolver', function () {
    $calls = 0;

    app()->bind(StepLabelResolver::class, function () use (&$calls) {
        $calls++;

        return new class extends StepLabelResolver
        {
            public function resolve(SagaState $state): ?string
            {
                return null;
            }
        };
    });

    $state = saga_state_test_instance();

    expect($state->label())->toBeNull()
        ->and($state->label())->toBeNull()
        ->and($calls)->toBe(1);
});
