<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoordinatorTestCompensatingWorkflow;
use Tests\Support\CoordinatorTestOneStepWorkflow;
use Tests\Support\CoordinatorTestRefundStepOne;

beforeEach(function () {
    $this->artisan('migrate')->run();
    Bus::fake();
});

it('fails sagas whose signal wait has passed its deadline', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'waiting',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(), 'signal' => 'approval',
        'signal_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('saga:sweep-signals')
        ->expectsOutputToContain('1 saga(s) timed out')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-1')->latest('id')->first()->status)->toBe('failed');
});

it('leaves a signal wait alone before its deadline', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'waiting',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(), 'signal' => 'approval',
        'signal_expires_at' => now()->addHour(),
    ]);

    $this->artisan('saga:sweep-signals')
        ->expectsOutputToContain('0 saga(s) timed out')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-1')->latest('id')->first()->status)->toBe('waiting');
});

it('only sweeps waits due before --before', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'waiting',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(), 'signal' => 'approval',
        'signal_expires_at' => '2025-06-01 00:00:00',
    ]);

    $this->artisan('saga:sweep-signals', ['--before' => '2025-01-01'])
        ->expectsOutputToContain('0 saga(s) timed out')
        ->assertExitCode(0);
});

it('reports what would time out without failing anything on --dry-run', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'waiting',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(), 'signal' => 'approval',
        'signal_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('saga:sweep-signals', ['--dry-run' => true])
        ->expectsOutputToContain('1 saga(s) would time out')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-1')->latest('id')->first()->status)->toBe('waiting');
});

it('does not ask for confirmation on --dry-run', function () {
    $this->app['env'] = 'production';

    $this->artisan('saga:sweep-signals', ['--dry-run' => true])
        ->doesntExpectOutputToContain('Are you sure')
        ->assertExitCode(0);
});

it('propagates --sync into the compensation it starts', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed',
        'workflow' => CoordinatorTestCompensatingWorkflow::class, 'payload' => json_encode(['order' => 1]),
        'reason' => null, 'recorded_at' => now(),
    ]);

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 1, 'status' => 'waiting',
        'workflow' => CoordinatorTestCompensatingWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(), 'signal' => 'approval',
        'signal_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('saga:sweep-signals', ['--sync' => true])
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, fn ($job) => $job->sync === true);
});

it('cancels without sweeping when not confirmed in production', function () {
    $this->app['env'] = 'production';

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'waiting',
        'workflow' => CoordinatorTestOneStepWorkflow::class, 'payload' => null,
        'reason' => null, 'recorded_at' => now(), 'signal' => 'approval',
        'signal_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('saga:sweep-signals')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-1')->latest('id')->first()->status)->toBe('waiting');
});
