<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

it('deletes one saga outright, regardless of status', function () {
    DB::table('saga_steps')->insert([
        ['saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed', 'payload' => null, 'reason' => null, 'recorded_at' => now()],
        ['saga_id' => 'saga-2', 'step' => 0, 'status' => 'failed', 'payload' => null, 'reason' => null, 'recorded_at' => now()],
    ]);

    $this->artisan('saga:delete', ['sagaId' => 'saga-1', '--force' => true])
        ->expectsOutputToContain('Saga saga-1 deleted.')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->where('saga_id', 'saga-1')->count())->toBe(0)
        ->and(DB::table('saga_steps')->where('saga_id', 'saga-2')->count())->toBe(1);
});

it('cancels without deleting when not confirmed in production', function () {
    $this->app['env'] = 'production';

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed', 'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:delete', ['sagaId' => 'saga-1'])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    expect(DB::table('saga_steps')->count())->toBe(1);
});

it('proceeds when confirmed in production', function () {
    $this->app['env'] = 'production';

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1', 'step' => 0, 'status' => 'completed', 'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:delete', ['sagaId' => 'saga-1'])
        ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
        ->assertExitCode(0);

    expect(DB::table('saga_steps')->count())->toBe(0);
});
