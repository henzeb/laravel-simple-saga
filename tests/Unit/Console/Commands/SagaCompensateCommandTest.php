<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoordinatorTestCompensatingWorkflow;
use Tests\Support\CoordinatorTestRefundStepOne;
use Tests\Support\CoordinatorTestSelfCompensatingStep;
use Tests\Support\CoordinatorTestSelfCompensatingWorkflow;

beforeEach(function () {
    $this->artisan('migrate')->run();
    Bus::fake();
});

function insertStuckSaga(string $sagaId, string $workflow, string $status = 'running', ?string $recordedAt = null): void
{
    DB::table('saga_steps')->insert([
        'saga_id' => $sagaId, 'step' => 0, 'status' => 'completed',
        'workflow' => $workflow, 'payload' => json_encode(['order' => 1]),
        'reason' => null, 'recorded_at' => now(),
    ]);

    DB::table('saga_steps')->insert([
        'saga_id' => $sagaId, 'step' => 1, 'status' => $status,
        'workflow' => $workflow, 'payload' => null,
        'reason' => null, 'recorded_at' => $recordedAt ?? now(),
    ]);
}

it('compensates a single stuck saga', function () {
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAY', CoordinatorTestCompensatingWorkflow::class);

    $this->artisan('saga:compensate', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY'])
        ->expectsOutputToContain('compensation started')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, function ($job) {
        return $job->sagaId === '01M2K6VH0H0HT2ZP4RVMWT6AAY';
    });
});

it('propagates --sync into the compensation it starts', function () {
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAY', CoordinatorTestCompensatingWorkflow::class);

    $this->artisan('saga:compensate', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', '--sync' => true])
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, fn ($job) => $job->sync === true);
});

it('compensates a saga that already completed successfully, including its last step', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'completed',
        'workflow' => CoordinatorTestCompensatingWorkflow::class,
        'payload' => json_encode(['order' => 1]), 'reason' => null, 'recorded_at' => now(),
    ]);
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 1, 'status' => 'completed',
        'workflow' => CoordinatorTestCompensatingWorkflow::class,
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:compensate', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY'])
        ->expectsOutputToContain('compensation started')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class);
});

it('fails with an error when the saga has nothing left to compensate', function () {
    DB::table('saga_steps')->insert([
        'saga_id' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', 'step' => 0, 'status' => 'rolled_back',
        'workflow' => CoordinatorTestCompensatingWorkflow::class,
        'payload' => null, 'reason' => null, 'recorded_at' => now(),
    ]);

    $this->artisan('saga:compensate', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY'])
        ->expectsOutputToContain('cannot be compensated')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

it('fails with an error for an unknown saga', function () {
    $this->artisan('saga:compensate', ['sagaId' => 'missing'])
        ->expectsOutputToContain('could not be found')
        ->assertExitCode(1);
});

it('rejects a sagaId combined with --workflow', function () {
    $this->artisan('saga:compensate', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', '--workflow' => CoordinatorTestCompensatingWorkflow::class])
        ->expectsOutputToContain('not both')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

it('rejects a sagaId combined with --since', function () {
    $this->artisan('saga:compensate', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', '--since' => '1 hour ago'])
        ->expectsOutputToContain('not both')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

it('bulk-compensates every saga stuck mid-run when no sagaId is given', function () {
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAY', CoordinatorTestCompensatingWorkflow::class);
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAZ', CoordinatorTestSelfCompensatingWorkflow::class, 'pending');

    $this->artisan('saga:compensate')
        ->expectsOutputToContain('Compensated 2 saga(s).')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class);
    Bus::assertDispatched(CoordinatorTestSelfCompensatingStep::class);
});

it('bulk-compensates only sagas of the given --workflow', function () {
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAY', CoordinatorTestCompensatingWorkflow::class);
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAZ', CoordinatorTestSelfCompensatingWorkflow::class, 'pending');

    $this->artisan('saga:compensate', ['--workflow' => CoordinatorTestCompensatingWorkflow::class])
        ->expectsOutputToContain('Compensated 1 saga(s).')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class);
    Bus::assertNotDispatched(CoordinatorTestSelfCompensatingStep::class);
});

it('bulk-compensates only sagas stuck since before --since', function () {
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAY', CoordinatorTestCompensatingWorkflow::class, 'running', now()->subHours(2)->toDateTimeString());
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAZ', CoordinatorTestSelfCompensatingWorkflow::class, 'pending', now()->toDateTimeString());

    $this->artisan('saga:compensate', ['--since' => '1 hour ago'])
        ->expectsOutputToContain('Compensated 1 saga(s).')
        ->assertExitCode(0);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class);
    Bus::assertNotDispatched(CoordinatorTestSelfCompensatingStep::class);
});

it('reports no stuck sagas found', function () {
    $this->artisan('saga:compensate')
        ->expectsOutputToContain('No stuck sagas found.')
        ->assertExitCode(0);
});

it('reports a single saga would be compensated without compensating it on --dry-run', function () {
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAY', CoordinatorTestCompensatingWorkflow::class);

    $this->artisan('saga:compensate', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY', '--dry-run' => true])
        ->expectsOutputToContain('would be compensated')
        ->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('reports bulk what would be compensated without compensating anything on --dry-run', function () {
    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAY', CoordinatorTestCompensatingWorkflow::class);

    $this->artisan('saga:compensate', ['--dry-run' => true])
        ->expectsOutputToContain('Would compensate 1 saga(s).')
        ->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('does not ask for confirmation on --dry-run', function () {
    $this->app['env'] = 'production';

    $this->artisan('saga:compensate', ['--dry-run' => true])
        ->doesntExpectOutputToContain('Are you sure')
        ->assertExitCode(0);
});

it('cancels without compensating when not confirmed in production', function () {
    $this->app['env'] = 'production';

    insertStuckSaga('01M2K6VH0H0HT2ZP4RVMWT6AAY', CoordinatorTestCompensatingWorkflow::class);

    $this->artisan('saga:compensate', ['sagaId' => '01M2K6VH0H0HT2ZP4RVMWT6AAY'])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});
