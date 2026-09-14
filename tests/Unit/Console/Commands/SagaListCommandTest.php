<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\CoordinatorTestOneStepWorkflow;
use Tests\Support\CoordinatorTestTwoStepWorkflow;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

it('lists a running saga\'s current step and status', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'pending',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => '2026-01-01 10:00:00',
    ]);
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 1, 'status' => 'running',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => '2026-01-01 10:00:05',
    ]);

    $this->artisan('saga:list')
        ->expectsOutputToContain('running')
        ->assertExitCode(0);
});

it('shows the saga\'s start time, not the latest record\'s time', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'pending',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => '2026-01-01 10:00:00',
    ]);
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 1, 'status' => 'running',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => '2026-01-01 10:00:05',
    ]);

    $this->artisan('saga:list')
        ->expectsOutputToContain('2026-01-01 10:00:00')
        ->doesntExpectOutputToContain('2026-01-01 10:00:05')
        ->assertExitCode(0);
});

it('includes failed sagas, not only running ones', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'failed',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => 'boom', 'recorded_at' => now(),
    ]);

    $this->artisan('saga:list')
        ->expectsOutputToContain('failed')
        ->assertExitCode(0);
});

it('filters to sagas of the given --workflow', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-a', 'step' => 0, 'status' => 'pending',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(),
    ]);
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-b', 'step' => 0, 'status' => 'pending',
        'workflow' => CoordinatorTestTwoStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:list', ['--workflow' => CoordinatorTestOneStepWorkflow::class])
        ->expectsOutputToContain('saga-a')
        ->doesntExpectOutputToContain('saga-b')
        ->assertExitCode(0);
});

it('excludes completed and rolled-back sagas', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-done', 'step' => 0, 'status' => 'completed',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:list')
        ->expectsOutputToContain('No sagas are currently running or failed')
        ->assertExitCode(0);
});
