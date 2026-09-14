<?php

use Henzeb\Saga\Attributes\CompensatedBy;
use Henzeb\Saga\Attributes\StepLabel;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Contracts\ProvidesStepLabel;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\DTO\SagaTrail;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\StepLabelResolver;
use Henzeb\Saga\Workflow;

#[StepLabel('Charge Payment')]
class StepLabelResolverTestLabeledStep
{
    use InteractsWithSaga;
}

class StepLabelResolverTestLabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepLabelResolverTestLabeledStep::class];
    }
}

class StepLabelResolverTestUnlabeledStep
{
    use InteractsWithSaga;
}

class StepLabelResolverTestUnlabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepLabelResolverTestUnlabeledStep::class];
    }
}

class StepLabelResolverTestInvokableLabel
{
    public function __invoke(SagaState $state): string
    {
        return 'Invoked: '.$state->context()->array()['sku'];
    }
}

#[StepLabel(StepLabelResolverTestInvokableLabel::class)]
class StepLabelResolverTestInvokableLabeledStep
{
    use InteractsWithSaga;
}

class StepLabelResolverTestInvokableLabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepLabelResolverTestInvokableLabeledStep::class];
    }
}

class StepLabelResolverTestInterfaceLabel implements ProvidesStepLabel
{
    public function toLabel(SagaState $state): string
    {
        return 'Resolved: '.$state->context()->array()['sku'];
    }
}

#[StepLabel(StepLabelResolverTestInterfaceLabel::class)]
class StepLabelResolverTestInterfaceLabeledStep
{
    use InteractsWithSaga;
}

class StepLabelResolverTestInterfaceLabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepLabelResolverTestInterfaceLabeledStep::class];
    }
}

#[StepLabel('Reserve Stock')]
#[CompensatedBy(StepLabelResolverTestExternalCompensator::class)]
class StepLabelResolverTestStepWithExternalCompensator
{
    use InteractsWithSaga;
}

#[StepLabel('Release Stock')]
class StepLabelResolverTestExternalCompensator implements ShouldCompensate
{
    use InteractsWithSaga;
}

class StepLabelResolverTestExternalCompensatorWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepLabelResolverTestStepWithExternalCompensator::class];
    }
}

#[StepLabel('Refund Payment')]
class StepLabelResolverTestSelfCompensatingStep implements ShouldCompensate
{
    use InteractsWithSaga;
}

class StepLabelResolverTestSelfCompensatingWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepLabelResolverTestSelfCompensatingStep::class];
    }
}

function stepLabelResolverTestState(?string $workflow, int $step, mixed $context = null, SagaStepStatus $status = SagaStepStatus::Completed): SagaState
{
    return new SagaState(
        sagaId: 'saga-1',
        workflow: $workflow,
        status: $status,
        step: $step,
        contextResolver: fn () => new SagaContext($context),
        reason: null,
        trailResolver: fn () => new SagaTrail(),
    );
}

it('resolves a #[StepLabel] string label as-is, with no prefix', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(StepLabelResolverTestLabeledWorkflow::class, 0));

    expect($label)->toBe('Charge Payment');
});

it('falls back to the step class basename when it has no #[StepLabel]', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(StepLabelResolverTestUnlabeledWorkflow::class, 0));

    expect($label)->toBe('StepLabelResolverTestUnlabeledStep');
});

it('resolves a #[StepLabel] pointing at an invokable class, passing it the step\'s own state', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(
        StepLabelResolverTestInvokableLabeledWorkflow::class, 0, ['sku' => 'ABC-123']
    ));

    expect($label)->toBe('Invoked: ABC-123');
});

it('resolves a #[StepLabel] pointing at a class implementing ProvidesStepLabel', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(
        StepLabelResolverTestInterfaceLabeledWorkflow::class, 0, ['sku' => 'XYZ-789']
    ));

    expect($label)->toBe('Resolved: XYZ-789');
});

it('returns null for a step index beyond the workflow\'s own steps', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(StepLabelResolverTestLabeledWorkflow::class, 1));

    expect($label)->toBeNull();
});

it('returns null when the state has no recorded workflow', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(null, 0));

    expect($label)->toBeNull();
});

it('returns null when the workflow class cannot be resolved', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState('App\\Sagas\\Missing', 0));

    expect($label)->toBeNull();
});

it('labels a forward step by its own class while it is still forward', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(
        StepLabelResolverTestExternalCompensatorWorkflow::class, 0, status: SagaStepStatus::Completed
    ));

    expect($label)->toBe('Reserve Stock');
});

it('labels a step by its external compensator once it is compensating, not by the step itself', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(
        StepLabelResolverTestExternalCompensatorWorkflow::class, 0, status: SagaStepStatus::Compensating
    ));

    expect($label)->toBe('Release Stock');
});

it('labels by the external compensator for every compensating status', function (SagaStepStatus $status) {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(
        StepLabelResolverTestExternalCompensatorWorkflow::class, 0, status: $status
    ));

    expect($label)->toBe('Release Stock');
})->with([
    SagaStepStatus::CompensationPending,
    SagaStepStatus::Compensating,
    SagaStepStatus::CompensationWaiting,
    SagaStepStatus::Compensated,
    SagaStepStatus::CompensationFailed,
    SagaStepStatus::RolledBack,
]);

it('labels a self-compensating step the same way whether forward or compensating', function () {
    $resolver = new StepLabelResolver();

    $forward = $resolver->resolve(stepLabelResolverTestState(
        StepLabelResolverTestSelfCompensatingWorkflow::class, 0, status: SagaStepStatus::Completed
    ));
    $compensating = $resolver->resolve(stepLabelResolverTestState(
        StepLabelResolverTestSelfCompensatingWorkflow::class, 0, status: SagaStepStatus::Compensating
    ));

    expect($forward)->toBe('Refund Payment')
        ->and($compensating)->toBe('Refund Payment');
});

it('falls back to the step\'s own label when compensating a step with no compensator at all', function () {
    $resolver = new StepLabelResolver();

    $label = $resolver->resolve(stepLabelResolverTestState(
        StepLabelResolverTestLabeledWorkflow::class, 0, status: SagaStepStatus::Compensated
    ));

    expect($label)->toBe('Charge Payment');
});
