<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoordinatorTestSelfCompensatingStep;
use Tests\Support\CoordinatorTestSelfCompensatingWorkflow;
use Tests\Support\CoordinatorTestStepOne;
use Tests\Support\CoordinatorTestTwoStepWorkflow;

beforeEach(function () {
    $this->artisan('migrate')->run();
    Bus::fake();
});

it('retries a rolled-back saga, auto-detecting its workflow from the record', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'rolled_back',
        'workflow' => CoordinatorTestTwoStepWorkflow::class,
        'payload' => json_encode(['x' => 1]), 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:retry', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY'])
        ->expectsOutputToContain('retried')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) {
        return $job->sagaId === '01M2K6VH0H0HT2ZP4RVMWT6AAY' && $job->context === ['x' => 1];
    });
});

it('retries a stuck compensation, auto-detecting its workflow from the record', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'compensation_failed',
        'workflow' => CoordinatorTestSelfCompensatingWorkflow::class,
        'payload' => json_encode(['refund' => 5]), 'reason' => 'RuntimeException: refund failed', 'recorded_at' => now(),
    ]);

    $this->artisan('saga:retry', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY'])
        ->expectsOutputToContain('retried')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestSelfCompensatingStep::class, function ($job) {
        return $job->sagaId === '01M2K6VH0H0HT2ZP4RVMWT6AAY' && $job->context === ['refund' => 5];
    });
});

it('fails with an error when the saga is not in a retryable status', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'completed',
        'workflow' => CoordinatorTestTwoStepWorkflow::class,
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:retry', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY'])
        ->expectsOutputToContain('cannot be retried')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

it('fails with an error for an unknown saga', function () {
    $this->artisan('saga:retry', ['sagaId' => 'missing'])
        ->expectsOutputToContain('could not be found')
        ->assertExitCode(1);
});

it('rejects a sagaId combined with --workflow', function () {
    $this->artisan('saga:retry', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', '--workflow' => CoordinatorTestTwoStepWorkflow::class])
        ->expectsOutputToContain('not both')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

it('bulk-retries every retryable saga when no sagaId or --workflow is given', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'rolled_back',
        'workflow' => CoordinatorTestTwoStepWorkflow::class,
        'payload' => json_encode(['x' => 1]), 'reason' => null, 'recorded_at' => now(),
    ]);
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAZ', 'step' => 0, 'status' => 'compensation_failed',
        'workflow' => CoordinatorTestSelfCompensatingWorkflow::class,
        'payload' => json_encode(['refund' => 5]), 'reason' => 'RuntimeException: refund failed', 'recorded_at' => now(),
    ]);

    $this->artisan('saga:retry')
        ->expectsOutputToContain('Retried 2 saga(s), skipped 0.')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestStepOne::class);
    Bus::assertDispatched(CoordinatorTestSelfCompensatingStep::class);
});

it('bulk-retries only sagas of the given --workflow', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'rolled_back',
        'workflow' => CoordinatorTestTwoStepWorkflow::class,
        'payload' => json_encode(['x' => 1]), 'reason' => null, 'recorded_at' => now(),
    ]);
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAZ', 'step' => 0, 'status' => 'compensation_failed',
        'workflow' => CoordinatorTestSelfCompensatingWorkflow::class,
        'payload' => json_encode(['refund' => 5]), 'reason' => 'RuntimeException: refund failed', 'recorded_at' => now(),
    ]);

    $this->artisan('saga:retry', ['--workflow' => CoordinatorTestTwoStepWorkflow::class])
        ->expectsOutputToContain('Retried 1 saga(s), skipped 0.')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestStepOne::class);
    Bus::assertNotDispatched(CoordinatorTestSelfCompensatingStep::class);
});

it('reports no retryable sagas found', function () {
    $this->artisan('saga:retry')
        ->expectsOutputToContain('No retryable sagas found.')
        ->assertExitCode(0);
});

it('reports a single saga would be retried without retrying it on --dry-run', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'rolled_back',
        'workflow' => CoordinatorTestTwoStepWorkflow::class,
        'payload' => json_encode(['x' => 1]), 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:retry', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', '--dry-run' => true])
        ->expectsOutputToContain('would be retried')
        ->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('reports bulk what would be retried without retrying anything on --dry-run', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'rolled_back',
        'workflow' => CoordinatorTestTwoStepWorkflow::class,
        'payload' => json_encode(['x' => 1]), 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:retry', ['--dry-run' => true])
        ->expectsOutputToContain('Would retry 1 saga(s), skipped 0.')
        ->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('does not ask for confirmation on --dry-run', function () {
    $this->app['env'] = 'production';

    $this->artisan('saga:retry', ['--dry-run' => true])
        ->doesntExpectOutputToContain('Are you sure')
        ->assertExitCode(0);
});

it('cancels without retrying when not confirmed in production', function () {
    $this->app['env'] = 'production';

    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'rolled_back',
        'workflow' => CoordinatorTestTwoStepWorkflow::class,
        'payload' => json_encode(['x' => 1]), 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:retry', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY'])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

