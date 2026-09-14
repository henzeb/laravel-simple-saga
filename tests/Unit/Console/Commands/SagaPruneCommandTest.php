<?php

use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\Enums\SagaStepStatus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function storeSagaStep(string $sagaId, int $step, SagaStepStatus $status, ?DateTimeImmutable $recordedAt = null, ?string $workflow = null): void
{
    DB::table('saga_steps')->insert([
        'saga_id' => $sagaId,
        'step' => $step,
        'status' => $status->value,
        'workflow' => $workflow,
        'payload' => null,
        'reason' => null,
        'recorded_at' => $recordedAt ?? now(),
    ]);
}

it('prunes sagas whose latest status is Completed', function () {
    storeSagaStep('saga-1', 0, SagaStepStatus::Completed);
    storeSagaStep('saga-2', 0, SagaStepStatus::Failed);

    $this->artisan('saga:prune', ['--force' => true])
        ->expectsOutputToContain('1 saga(s) pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-1')->count())->toBe(0)
        ->and(DB::table('saga_steps')->where('saga_id', 'saga-2')->count())->toBe(1);
});

it('leaves RolledBack sagas alone by default', function () {
    storeSagaStep('saga-1', 0, SagaStepStatus::Completed);
    storeSagaStep('saga-2', 0, SagaStepStatus::RolledBack);

    $this->artisan('saga:prune', ['--force' => true])
        ->expectsOutputToContain('1 saga(s) pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-2')->count())->toBe(1);
});

it('also prunes RolledBack sagas with --with-rolled-back', function () {
    storeSagaStep('saga-1', 0, SagaStepStatus::Completed);
    storeSagaStep('saga-2', 0, SagaStepStatus::RolledBack);

    $this->artisan('saga:prune', ['--with-rolled-back' => true, '--force' => true])
        ->expectsOutputToContain('2 saga(s) pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(0);
});

it('reports what would be pruned without deleting anything on --dry-run', function () {
    storeSagaStep('saga-1', 0, SagaStepStatus::Completed);

    $this->artisan('saga:prune', ['--dry-run' => true, '--force' => true])
        ->expectsOutputToContain('1 saga(s) would be pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(1);
});

it('only prunes sagas of the given --workflow', function () {
    storeSagaStep('saga-a', 0, SagaStepStatus::Completed, workflow: 'App\\WorkflowA');
    storeSagaStep('saga-b', 0, SagaStepStatus::Completed, workflow: 'App\\WorkflowB');

    $this->artisan('saga:prune', ['--workflow' => 'App\\WorkflowA', '--force' => true])
        ->expectsOutputToContain('1 saga(s) pruned.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-a')->count())->toBe(0)
        ->and(DB::table('saga_steps')->where('saga_id', 'saga-b')->count())->toBe(1);
});

it('does not ask for confirmation on --dry-run', function () {
    $this->app['env'] = 'production';

    storeSagaStep('saga-1', 0, SagaStepStatus::Completed);

    $this->artisan('saga:prune', ['--dry-run' => true])
        ->doesntExpectOutputToContain('Are you sure')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(1);
});

it('only prunes sagas last touched before --before', function () {
    storeSagaStep('saga-old', 0, SagaStepStatus::Completed, new DateTimeImmutable('2020-01-01'));
    storeSagaStep('saga-new', 0, SagaStepStatus::Completed, new DateTimeImmutable('2030-01-01'));

    $this->artisan('saga:prune', ['--before' => '2025-01-01', '--force' => true])
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-old')->count())->toBe(0)
        ->and(DB::table('saga_steps')->where('saga_id', 'saga-new')->count())->toBe(1);
});

it('cancels without pruning when not confirmed in production', function () {
    $this->app['env'] = 'production';

    storeSagaStep('saga-1', 0, SagaStepStatus::Completed);

    $this->artisan('saga:prune')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    expect(DB::table('saga_steps')->count())->toBe(1);
});

it('proceeds when confirmed in production', function () {
    $this->app['env'] = 'production';

    storeSagaStep('saga-1', 0, SagaStepStatus::Completed);

    $this->artisan('saga:prune')
        ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(0);
});
