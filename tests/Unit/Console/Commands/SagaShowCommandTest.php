<?php

use Henzeb\Saga\Attributes\StepLabel;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Contracts\ProvidesStepLabel;
use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Workflow;
use Illuminate\Support\Facades\DB;

#[StepLabel('Charge Payment')]
class SagaShowCommandTestLabeledStep
{
    use InteractsWithSaga;
}

class SagaShowCommandTestLabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [SagaShowCommandTestLabeledStep::class];
    }
}

class SagaShowCommandTestUnlabeledStep
{
    use InteractsWithSaga;
}

class SagaShowCommandTestUnlabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [SagaShowCommandTestUnlabeledStep::class];
    }
}

class SagaShowCommandTestInvokableLabel
{
    public function __invoke(SagaState $state): string
    {
        return 'Invoked: '.$state->context()->array()['sku'];
    }
}

#[StepLabel(SagaShowCommandTestInvokableLabel::class)]
class SagaShowCommandTestInvokableLabeledStep
{
    use InteractsWithSaga;
}

class SagaShowCommandTestInvokableLabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [SagaShowCommandTestInvokableLabeledStep::class];
    }
}

class SagaShowCommandTestInterfaceLabel implements ProvidesStepLabel
{
    public function toLabel(SagaState $state): string
    {
        return 'Resolved: '.$state->context()->array()['sku'];
    }
}

#[StepLabel(SagaShowCommandTestInterfaceLabel::class)]
class SagaShowCommandTestInterfaceLabeledStep
{
    use InteractsWithSaga;
}

class SagaShowCommandTestInterfaceLabeledWorkflow extends Workflow
{
    public function steps(): array
    {
        return [SagaShowCommandTestInterfaceLabeledStep::class];
    }
}

beforeEach(function () {
    $this->artisan('migrate')->run();
});

it('shows the current status of a saga, without its trail, by default', function () {
    DB::table('saga_steps')->insert([
        ['saga_id' => 'saga-1', 'step' => 0, 'status' => 'pending', 'payload' => null, 'reason' => null, 'recorded_at' => now()],
        ['saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed', 'payload' => null, 'reason' => null, 'recorded_at' => now()],
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->expectsOutputToContain('Saga ID')
        ->expectsOutputToContain('Completed')
        ->doesntExpectOutputToContain('History')
        ->assertExitCode(0);
});

it('shows the full trail when --trail is passed', function () {
    DB::table('saga_steps')->insert([
        ['saga_id' => 'saga-1', 'step' => 0, 'status' => 'pending', 'payload' => null, 'reason' => null, 'recorded_at' => now()],
        ['saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed', 'payload' => null, 'reason' => null, 'recorded_at' => now()],
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1', '--trail' => true])
        ->expectsOutputToContain('History')
        ->expectsOutputToContain('Pending')
        ->expectsOutputToContain('Completed')
        ->assertExitCode(0);
});

it('shows the full trail when the -t shortcut is passed', function () {
    DB::table('saga_steps')->insert([
        ['saga_id' => 'saga-1', 'step' => 0, 'status' => 'pending', 'payload' => null, 'reason' => null, 'recorded_at' => now()],
        ['saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed', 'payload' => null, 'reason' => null, 'recorded_at' => now()],
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1', '-t' => true])
        ->expectsOutputToContain('History')
        ->expectsOutputToContain('Pending')
        ->assertExitCode(0);
});

it('shows the failure reason when present', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => SagaStepStatus::Failed->value,
        'payload' => null, 'reason' => 'RuntimeException: boom', 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->expectsOutputToContain('RuntimeException: boom')
        ->assertExitCode(0);
});

it('shows a trail row\'s reason on its own indented line, not appended to the row', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => SagaStepStatus::Failed->value,
        'payload' => null, 'reason' => 'RuntimeException: boom', 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1', '--trail' => true])
        ->expectsOutputToContain('↳ RuntimeException: boom')
        ->assertExitCode(0);
});

it('falls back to the raw reason when it is not in "Class: message" shape', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => SagaStepStatus::Failed->value,
        'payload' => null, 'reason' => 'boom', 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->expectsOutputToContain('boom')
        ->assertExitCode(0);
});

it('shows the workflow class when the record carries one', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed', 'workflow' => 'App\\Sagas\\PlaceOrder',
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->expectsOutputToContain('App\\Sagas\\PlaceOrder')
        ->assertExitCode(0);
});

it('shows the awaited signal name when the saga is waiting', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => SagaStepStatus::Waiting->value,
        'payload' => null, 'reason' => null, 'recorded_at' => now(), 'signal' => 'approval',
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->expectsOutputToContain('approval')
        ->assertExitCode(0);
});

it('omits the Awaiting Signal line when the saga is not waiting', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->doesntExpectOutputToContain('Awaiting Signal')
        ->assertExitCode(0);
});

it('omits the Workflow line when the record does not carry one', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->doesntExpectOutputToContain('Workflow')
        ->assertExitCode(0);
});

it('shows a step\'s #[StepLabel] in the trail when the step class carries one', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'workflow' => SagaShowCommandTestLabeledWorkflow::class,
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1', '--trail' => true])
        ->expectsOutputToContain('Step 1 — Charge Payment')
        ->assertExitCode(0);
});

it('does not repeat "Step" in the header line, only in the trail', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'workflow' => SagaShowCommandTestLabeledWorkflow::class,
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->expectsOutputToContain('1 — Charge Payment')
        ->doesntExpectOutputToContain('Step 1 — Charge Payment')
        ->assertExitCode(0);
});

it('falls back to the step class basename when it has no #[StepLabel]', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'workflow' => SagaShowCommandTestUnlabeledWorkflow::class,
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1', '--trail' => true])
        ->expectsOutputToContain('Step 1 — SagaShowCommandTestUnlabeledStep')
        ->assertExitCode(0);
});

it('resolves a #[StepLabel] pointing at an invokable class, passing it the step\'s own state', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'workflow' => SagaShowCommandTestInvokableLabeledWorkflow::class,
        'payload' => json_encode(['sku' => 'ABC-123']), 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1', '--trail' => true])
        ->expectsOutputToContain('Step 1 — Invoked: ABC-123')
        ->assertExitCode(0);
});

it('resolves a #[StepLabel] pointing at a class implementing ProvidesStepLabel', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'workflow' => SagaShowCommandTestInterfaceLabeledWorkflow::class,
        'payload' => json_encode(['sku' => 'XYZ-789']), 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1', '--trail' => true])
        ->expectsOutputToContain('Step 1 — Resolved: XYZ-789')
        ->assertExitCode(0);
});

it('falls back to a bare step number when the workflow class cannot be resolved', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed', 'workflow' => 'App\\Sagas\\PlaceOrder',
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1'])
        ->doesntExpectOutputToContain('Step 1 — ')
        ->doesntExpectOutputToContain('— ')
        ->assertExitCode(0);
});

it('shows every trail row, even past the default limit, when output is not a tty', function () {
    for ($i = 0; $i < 25; $i++) {
        DB::table('saga_steps')->insert([
            'saga_id' => 'saga-1', 'step' => 0, 'status' => 'failed',
            'payload' => null, 'reason' => "marker-{$i}", 'recorded_at' => now()->addSeconds($i),
        ]);
    }

    // Paging only ever kicks in against a real interactive terminal (stream_isatty),
    // which the test runner's captured output never is — so nothing gets dropped here,
    // not even the very first row, well past the default limit of 20.
    $this->artisan('saga:show', ['sagaId' => 'saga-1', '--trail' => true])
        ->expectsOutputToContain('marker-0')
        ->expectsOutputToContain('marker-24')
        ->assertExitCode(0);
});

it('accepts a custom --limit', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:show', ['sagaId' => 'saga-1', '--trail' => true, '--limit' => 5])
        ->assertExitCode(0);
});

it('fails with an error for an unknown saga', function () {
    $this->artisan('saga:show', ['sagaId' => 'missing'])
        ->expectsOutputToContain('could not be found')
        ->assertExitCode(1);
});
