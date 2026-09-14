<?php

use Henzeb\Saga\Enums\SagaStepStatus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function pruneFailedStoreStep(string $sagaId, int $step, SagaStepStatus $status, ?string $workflow = null): void
{
    DB::table('saga_steps')->insert([
        'saga_id' => $sagaId,
        'step' => $step,
        'status' => $status->value,
        'workflow' => $workflow,
        'payload' => null,
        'reason' => null,
        'recorded_at' => now(),
    ]);
}

it('prunes sagas whose latest status is Failed by default', function () {
    pruneFailedStoreStep('saga-failed', 0, SagaStepStatus::Failed);
    pruneFailedStoreStep('saga-compensation-failed', 0, SagaStepStatus::CompensationFailed);

    $this->artisan('saga:prune-failed', ['--force' => true])
        ->expectsOutputToContain('1 saga(s) pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-failed')->count())->toBe(0)
        ->and(DB::table('saga_steps')->where('saga_id', 'saga-compensation-failed')->count())->toBe(1);
});

it('also prunes CompensationFailed sagas with --with-compensation-failed', function () {
    pruneFailedStoreStep('saga-failed', 0, SagaStepStatus::Failed);
    pruneFailedStoreStep('saga-compensation-failed', 0, SagaStepStatus::CompensationFailed);

    $this->artisan('saga:prune-failed', ['--with-compensation-failed' => true, '--force' => true])
        ->expectsOutputToContain('2 saga(s) pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(0);
});

it('also prunes CompensationFailed sagas with the -c shortcut', function () {
    pruneFailedStoreStep('saga-failed', 0, SagaStepStatus::Failed);
    pruneFailedStoreStep('saga-compensation-failed', 0, SagaStepStatus::CompensationFailed);

    $this->artisan('saga:prune-failed -c --force')
        ->expectsOutputToContain('2 saga(s) pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(0);
});

it('only prunes sagas of the given --workflow', function () {
    pruneFailedStoreStep('saga-a', 0, SagaStepStatus::Failed, 'App\\WorkflowA');
    pruneFailedStoreStep('saga-b', 0, SagaStepStatus::Failed, 'App\\WorkflowB');

    $this->artisan('saga:prune-failed', ['--workflow' => 'App\\WorkflowA', '--force' => true])
        ->expectsOutputToContain('1 saga(s) pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-a')->count())->toBe(0)
        ->and(DB::table('saga_steps')->where('saga_id', 'saga-b')->count())->toBe(1);
});

it('does not ask for confirmation on --dry-run', function () {
    $this->app['env'] = 'production';

    pruneFailedStoreStep('saga-failed', 0, SagaStepStatus::Failed);

    $this->artisan('saga:prune-failed', ['--dry-run' => true])
        ->doesntExpectOutputToContain('Are you sure')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(1);
});

it('reports what would be pruned without deleting anything on --dry-run', function () {
    pruneFailedStoreStep('saga-failed', 0, SagaStepStatus::Failed);

    $this->artisan('saga:prune-failed', ['--dry-run' => true, '--force' => true])
        ->expectsOutputToContain('1 saga(s) would be pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(1);
});

it('cancels without pruning when not confirmed in production', function () {
    $this->app['env'] = 'production';

    pruneFailedStoreStep('saga-failed', 0, SagaStepStatus::Failed);

    $this->artisan('saga:prune-failed')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    expect(DB::table('saga_steps')->count())->toBe(1);
});

it('proceeds when confirmed in production', function () {
    $this->app['env'] = 'production';

    pruneFailedStoreStep('saga-failed', 0, SagaStepStatus::Failed);

    $this->artisan('saga:prune-failed')
        ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(0);
});
