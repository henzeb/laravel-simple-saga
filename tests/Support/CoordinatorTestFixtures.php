<?php

namespace Tests\Support;

use Henzeb\Saga\Attributes\CompensatedBy;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Concerns\IterableSagaStep;
use Henzeb\Saga\Contracts\RetryWhenStale;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Henzeb\Saga\SagaStep;
use Henzeb\Saga\Workflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CoordinatorTestStepOne
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestStepTwo
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestEncryptedWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestStepOne::class, CoordinatorTestStepTwo::class];
    }

    public function encryptContext(): bool
    {
        return true;
    }
}

#[CompensatedBy(CoordinatorTestRefundStepOne::class)]
class CoordinatorTestCompensatedStepOne
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestRefundStepOne implements ShouldCompensate
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestSelfCompensatingStep implements ShouldCompensate
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }

    public function compensate(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestNoCompensatorStep
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestNotASagaJob
{
    use Dispatchable;
}

class CoordinatorTestQueuedRetryStep extends SagaStep implements ShouldQueue, RetryWhenStale
{
    public $tries = 3;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestTwoStepWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestStepOne::class, CoordinatorTestStepTwo::class];
    }
}

class CoordinatorTestOneStepWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestStepOne::class];
    }
}

class CoordinatorTestCompensatingWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestCompensatedStepOne::class, CoordinatorTestStepTwo::class];
    }
}

class CoordinatorTestSelfCompensatingWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestSelfCompensatingStep::class, CoordinatorTestStepTwo::class];
    }
}

#[CompensatedBy(CoordinatorTestResumeWritingStep::class)]
class CoordinatorTestPauseWritingStep implements ShouldCompensate
{
    use Dispatchable, InteractsWithSaga;

    // no compensate() — handle() doubles as both this step's own forward action
    // and the compensation dispatched for CoordinatorTestResumeWritingStep.
    public function handle(): mixed
    {
        return 'paused';
    }
}

#[CompensatedBy(CoordinatorTestPauseWritingStep::class)]
class CoordinatorTestResumeWritingStep implements ShouldCompensate
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return 'resumed';
    }
}

class CoordinatorTestMutualCompensatorWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestPauseWritingStep::class, CoordinatorTestResumeWritingStep::class];
    }
}

#[CompensatedBy(CoordinatorTestRefundStepOne::class)]
class CoordinatorTestInterruptedStepTwo
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        posix_kill(posix_getpid(), SIGINT);

        return $this->context()->raw();
    }
}

class CoordinatorTestInterruptibleWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestCompensatedStepOne::class, CoordinatorTestInterruptedStepTwo::class];
    }
}

class CoordinatorTestNoCompensatorWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestNoCompensatorStep::class, CoordinatorTestStepTwo::class];
    }
}

class CoordinatorTestRetryWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestQueuedRetryStep::class];
    }
}

class CoordinatorTestSyncQueuedWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestStepOne::class, CoordinatorTestQueuedRetryStep::class];
    }
}

class CoordinatorTestInvalidStep
{
    // deliberately missing the InteractsWithSaga concern
}

class CoordinatorTestInvalidWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestInvalidStep::class];
    }
}

class CoordinatorTestOnStaleRetryWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestStepOne::class];
    }

    public function onStaleRunning(): ?string
    {
        return 'retry';
    }
}

class CoordinatorTestSignalTimeoutWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestStepOne::class];
    }

    public function signalTimeout(): ?int
    {
        return 60;
    }
}

class CoordinatorTestRunningTimeoutWorkflow extends Workflow
{
    public function steps(): array
    {
        return [CoordinatorTestStepOne::class];
    }

    public function runningTimeout(): ?int
    {
        return 60;
    }
}

#[CompensatedBy(CoordinatorTestParallelBeforeRefundStep::class)]
class CoordinatorTestParallelBeforeStep
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestParallelBeforeRefundStep implements ShouldCompensate
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestParallelBranchA
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return ['branch' => 'a'];
    }
}

class CoordinatorTestParallelBranchB implements ShouldCompensate
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return ['branch' => 'b'];
    }

    public function compensate(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestParallelAfterStep
{
    use Dispatchable, InteractsWithSaga;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class CoordinatorTestParallelWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            CoordinatorTestParallelBeforeStep::class,
            $this->parallel(CoordinatorTestParallelBranchA::class, CoordinatorTestParallelBranchB::class),
            CoordinatorTestParallelAfterStep::class,
        ];
    }
}

class CoordinatorTestParallelFailFastWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            CoordinatorTestParallelBeforeStep::class,
            $this->parallel(CoordinatorTestParallelBranchA::class, CoordinatorTestParallelBranchB::class)->failFast(),
            CoordinatorTestParallelAfterStep::class,
        ];
    }
}

class CoordinatorTestParallelQueuedWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            CoordinatorTestParallelBeforeStep::class,
            $this->parallel(CoordinatorTestParallelBranchA::class, CoordinatorTestParallelBranchB::class)->queued(),
            CoordinatorTestParallelAfterStep::class,
        ];
    }
}

class CoordinatorTestParallelInvalidStep
{
    // deliberately missing the InteractsWithSaga concern
}

class CoordinatorTestParallelInvalidWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            $this->parallel(CoordinatorTestParallelBranchA::class, CoordinatorTestParallelInvalidStep::class),
        ];
    }
}

class ProcessesSagaTestForwardStep extends SagaStep
{
    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class ProcessesSagaTestIterableStep extends SagaStep
{
    use IterableSagaStep;

    public function handle(): void
    {
        //
    }

    protected function hasNext(): bool
    {
        return $this->context()->array()['page'] < 2;
    }

    protected function next(): void
    {
        $this->context = ['page' => $this->context()->array()['page'] + 1];
    }
}

class ProcessesSagaTestAwaitingStep extends SagaStep
{
    public function handle(): mixed
    {
        return $this->waitFor('approval')->raw();
    }
}

class ProcessesSagaTestAwaitingCompensatorStep extends SagaStep implements ShouldCompensate
{
    public function handle(): mixed
    {
        return $this->context()->raw();
    }

    public function compensate(): mixed
    {
        return $this->waitFor('refunded')->raw();
    }
}

class ProcessesSagaTestAwaitingWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ProcessesSagaTestAwaitingStep::class];
    }
}

class ProcessesSagaTestAwaitingCompensatorWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ProcessesSagaTestAwaitingCompensatorStep::class, CoordinatorTestStepTwo::class];
    }
}

class ProcessesSagaTestQueuedStep extends SagaStep implements ShouldQueue
{
    public $tries = 3;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class ProcessesSagaTestSelfCompensatingStep extends SagaStep implements ShouldCompensate
{
    public function handle(): mixed
    {
        return $this->context()->raw();
    }

    public function compensate(): mixed
    {
        return $this->context()->raw();
    }
}

class ProcessesSagaTestExternalCompensatorStep extends SagaStep implements ShouldCompensate
{
    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class ProcessesSagaTestOneStepWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ProcessesSagaTestForwardStep::class];
    }
}

class ProcessesSagaTestIterableWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ProcessesSagaTestIterableStep::class];
    }
}

class ProcessesSagaTestIterableCompensatingStep extends SagaStep implements ShouldCompensate
{
    use IterableSagaStep;

    public function handle(): mixed
    {
        return $this->context()->raw();
    }

    public function compensate(): mixed
    {
        return $this->context()->raw();
    }

    protected function hasNext(): bool
    {
        return $this->context()->array()['page'] < 2;
    }

    protected function next(): void
    {
        $this->context = ['page' => $this->context()->array()['page'] + 1];
    }
}

class ProcessesSagaTestIterableCompensatingWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ProcessesSagaTestSelfCompensatingStep::class, ProcessesSagaTestIterableCompensatingStep::class];
    }
}

class ProcessesSagaTestTwoStepWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ProcessesSagaTestForwardStep::class, CoordinatorTestStepTwo::class];
    }
}

class ProcessesSagaTestSelfCompensatingWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ProcessesSagaTestSelfCompensatingStep::class, CoordinatorTestStepTwo::class];
    }
}

#[CompensatedBy(ProcessesSagaTestExternalCompensatorStep::class)]
class ProcessesSagaTestExternallyCompensatedStep extends SagaStep
{
    public function handle(): mixed
    {
        return $this->context()->raw();
    }
}

class ProcessesSagaTestExternalCompensatorWorkflow extends Workflow
{
    public function steps(): array
    {
        return [ProcessesSagaTestExternallyCompensatedStep::class, CoordinatorTestStepTwo::class];
    }
}
