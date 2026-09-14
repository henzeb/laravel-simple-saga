<?php

use Henzeb\Saga\Attributes\CompensatedBy;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Henzeb\Saga\Exceptions\InvalidWorkflowException;
use Henzeb\Saga\Workflow;
use Henzeb\Saga\WorkflowValidator;

class ValidatorTestParallelInvalidWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            $this->parallel(ValidatorTestValidStep::class, ValidatorTestMissingTraitStep::class),
        ];
    }
}

class ValidatorTestValidStep
{
    use InteractsWithSaga;
}

class ValidatorTestRefundStep implements ShouldCompensate
{
    use InteractsWithSaga;
}

#[CompensatedBy(ValidatorTestRefundStep::class)]
class ValidatorTestExternalCompensatorStep
{
    use InteractsWithSaga;
}

class ValidatorTestMissingTraitStep
{
    //
}

class ValidatorTestSelfCompensatingWithAttributeStep implements ShouldCompensate
{
    use InteractsWithSaga;
}

#[CompensatedBy(ValidatorTestSelfCompensatingWithAttributeStep::class)]
class ValidatorTestBothCompensationRoutesStep implements ShouldCompensate
{
    use InteractsWithSaga;

    public function compensate(): void
    {
        //
    }
}

#[CompensatedBy(ValidatorTestMarkerOnlyStepB::class)]
class ValidatorTestMarkerOnlyStepA implements ShouldCompensate
{
    use InteractsWithSaga;
}

#[CompensatedBy(ValidatorTestMarkerOnlyStepA::class)]
class ValidatorTestMarkerOnlyStepB implements ShouldCompensate
{
    use InteractsWithSaga;
}

#[CompensatedBy(ValidatorTestMissingTraitStep::class)]
class ValidatorTestBadCompensatorTargetStep
{
    use InteractsWithSaga;
}

class ValidatorTestValidWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ValidatorTestValidStep::class];
    }
}

class ValidatorTestExternalCompensatorWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ValidatorTestExternalCompensatorStep::class];
    }
}

class ValidatorTestMissingTraitWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ValidatorTestMissingTraitStep::class];
    }
}

class ValidatorTestBothCompensationRoutesWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ValidatorTestBothCompensationRoutesStep::class];
    }
}

class ValidatorTestBadCompensatorTargetWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ValidatorTestBadCompensatorTargetStep::class];
    }
}

class ValidatorTestMarkerOnlyMutualWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ValidatorTestMarkerOnlyStepA::class, ValidatorTestMarkerOnlyStepB::class];
    }
}

it('passes a workflow made of valid, unrelated steps', function () {
    $validator = new WorkflowValidator();

    $validator->validate(new ValidatorTestValidWorkflow());
})->throwsNoExceptions();

it('passes a workflow using the external compensator route', function () {
    $validator = new WorkflowValidator();

    $validator->validate(new ValidatorTestExternalCompensatorWorkflow());
})->throwsNoExceptions();

it('rejects a step missing the InteractsWithSaga trait', function () {
    $validator = new WorkflowValidator();

    $validator->validate(new ValidatorTestMissingTraitWorkflow());
})->throws(InvalidWorkflowException::class, 'must use the InteractsWithSaga trait');

it('rejects a step that both defines compensate() and carries #[CompensatedBy]', function () {
    $validator = new WorkflowValidator();

    $validator->validate(new ValidatorTestBothCompensationRoutesWorkflow());
})->throws(InvalidWorkflowException::class, 'cannot both define compensate() and carry #[CompensatedBy]');

it('passes two steps that implement ShouldCompensate as a marker only and compensate each other', function () {
    $validator = new WorkflowValidator();

    $validator->validate(new ValidatorTestMarkerOnlyMutualWorkflow());
})->throwsNoExceptions();

it('rejects a #[CompensatedBy] target that does not implement ShouldCompensate', function () {
    $validator = new WorkflowValidator();

    $validator->validate(new ValidatorTestBadCompensatorTargetWorkflow());
})->throws(InvalidWorkflowException::class, 'must implement ShouldCompensate');

it('validates each branch of a Parallel entry like a plain step', function () {
    $validator = new WorkflowValidator();

    $validator->validate(new ValidatorTestParallelInvalidWorkflow());
})->throws(InvalidWorkflowException::class, 'must use the InteractsWithSaga trait');

it('memoizes validation per workflow class, validating only once', function () {
    $calls = 0;

    $workflow = new class($calls) extends Workflow {
        public function __construct(private int &$calls) {}

        public function steps(): array
        {
            $this->calls++;

            return [ValidatorTestValidStep::class];
        }
    };

    $validator = new WorkflowValidator();

    $validator->validate($workflow);
    $validator->validate($workflow);

    expect($calls)->toBe(1);
});
