<?php

use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\Drivers\DatabaseDriver;
use Henzeb\Saga\Enums\SagaStepStatus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function makeDatabaseDriver(): DatabaseDriver
{
    return new DatabaseDriver(DB::connection());
}

it('stores a record as a row', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['x' => 1], reason: null));

    $row = DB::table('saga_steps')->first();

    expect($row->saga_id)->toBe('saga-1')
        ->and((int) $row->step)->toBe(0)
        ->and($row->status)->toBe('completed')
        ->and($row->payload)->toBe('{"x":1}');
});

it('round-trips the signal name on a record', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, signal: 'approval'));

    expect($driver->latest('saga-1')->signal)->toBe('approval');
});

it('round-trips the signal deadline on a record', function () {
    $driver = makeDatabaseDriver();
    $expiresAt = new DateTimeImmutable('2030-01-01 12:00:00');

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, signal: 'approval', signalExpiresAt: $expiresAt));

    expect($driver->latest('saga-1')->signalExpiresAt)->toEqual($expiresAt);
});

it('lists sagas that are running or have failed, excluding Completed and RolledBack', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-pending', 0, SagaStepStatus::Pending));
    $driver->store(new SagaStepRecord('saga-running', 0, SagaStepStatus::Running));
    $driver->store(new SagaStepRecord('saga-waiting', 0, SagaStepStatus::Waiting, signal: 'approval'));
    $driver->store(new SagaStepRecord('saga-failed', 0, SagaStepStatus::Failed));
    $driver->store(new SagaStepRecord('saga-compensation-failed', 0, SagaStepStatus::CompensationFailed));
    $driver->store(new SagaStepRecord('saga-completed', 0, SagaStepStatus::Completed));
    $driver->store(new SagaStepRecord('saga-rolled-back', 0, SagaStepStatus::RolledBack));

    $active = collect($driver->active())->pluck('sagaId')->sort()->values()->all();

    expect($active)->toBe([
        'saga-compensation-failed', 'saga-failed', 'saga-pending', 'saga-running', 'saga-waiting',
    ]);
});

it('filters active() to sagas of one workflow class', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-a', 0, SagaStepStatus::Pending, workflow: 'App\\WorkflowA'));
    $driver->store(new SagaStepRecord('saga-b', 0, SagaStepStatus::Pending, workflow: 'App\\WorkflowB'));

    $active = collect($driver->active('App\\WorkflowA'))->pluck('sagaId')->all();

    expect($active)->toBe(['saga-a']);
});

it('finds sagas whose signal wait has passed its deadline', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-due', 0, SagaStepStatus::Waiting, signal: 'approval', signalExpiresAt: new DateTimeImmutable('2020-01-01')));
    $driver->store(new SagaStepRecord('saga-not-due', 0, SagaStepStatus::Waiting, signal: 'approval', signalExpiresAt: new DateTimeImmutable('2999-01-01')));
    $driver->store(new SagaStepRecord('saga-no-deadline', 0, SagaStepStatus::Waiting, signal: 'approval'));
    $driver->store(new SagaStepRecord('saga-not-waiting', 0, SagaStepStatus::Completed));

    $due = $driver->dueSignals(new DateTimeImmutable('2025-01-01'));

    expect($due)->toHaveCount(1)
        ->and($due[0]->sagaId)->toBe('saga-due');
});

it('finds compensating sagas whose signal wait has passed its deadline', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::CompensationWaiting, signal: 'refunded', signalExpiresAt: new DateTimeImmutable('2020-01-01')));

    expect($driver->dueSignals())->toHaveCount(1);
});

it('defaults dueSignals to now when no cutoff is given', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Waiting, signal: 'approval', signalExpiresAt: new DateTimeImmutable('2020-01-01')));

    expect($driver->dueSignals())->toHaveCount(1);
});

it('finds sagas whose running step has passed its timeout', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-due', 0, SagaStepStatus::Running, runningExpiresAt: new DateTimeImmutable('2020-01-01')));
    $driver->store(new SagaStepRecord('saga-not-due', 0, SagaStepStatus::Running, runningExpiresAt: new DateTimeImmutable('2999-01-01')));
    $driver->store(new SagaStepRecord('saga-no-deadline', 0, SagaStepStatus::Running));
    $driver->store(new SagaStepRecord('saga-not-running', 0, SagaStepStatus::Completed));

    $due = $driver->dueRunning(new DateTimeImmutable('2025-01-01'));

    expect($due)->toHaveCount(1)
        ->and($due[0]->sagaId)->toBe('saga-due');
});

it('finds compensating sagas whose running step has passed its timeout', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Compensating, runningExpiresAt: new DateTimeImmutable('2020-01-01')));

    expect($driver->dueRunning())->toHaveCount(1);
});

it('defaults dueRunning to now when no cutoff is given', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running, runningExpiresAt: new DateTimeImmutable('2020-01-01')));

    expect($driver->dueRunning())->toHaveCount(1);
});

it('returns the full trail for a saga, in order', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending));
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Pending));

    $trail = $driver->get('saga-1');

    expect($trail)->toHaveCount(3)
        ->and($trail[0]->status)->toBe(SagaStepStatus::Pending)
        ->and($trail[1]->status)->toBe(SagaStepStatus::Completed)
        ->and($trail[2]->step)->toBe(1);
});

it('returns an empty trail for an unknown saga', function () {
    expect(makeDatabaseDriver()->get('missing'))->toHaveCount(0);
});

it('returns the latest record for a saga overall', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Running));

    expect($driver->latest('saga-1')->step)->toBe(1)
        ->and($driver->latest('saga-1')->status)->toBe(SagaStepStatus::Running);
});

it('returns null latest for an unknown saga', function () {
    expect(makeDatabaseDriver()->latest('missing'))->toBeNull();
});

it('returns the latest record for one specific step', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending));
    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Running));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Pending));

    expect($driver->latestFor('saga-1', 0)->status)->toBe(SagaStepStatus::Running)
        ->and($driver->latestFor('saga-1', 1)->status)->toBe(SagaStepStatus::Pending)
        ->and($driver->latestFor('saga-1', 2))->toBeNull();
});

it('distinguishes branches at the same step', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Completed, branch: 0));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Running, branch: 1));

    expect($driver->latestFor('saga-1', 1, 0)->status)->toBe(SagaStepStatus::Completed)
        ->and($driver->latestFor('saga-1', 1, 1)->status)->toBe(SagaStepStatus::Running)
        ->and($driver->latestFor('saga-1', 1, 2))->toBeNull();
});

it('deletes everything stored for one saga, leaving others untouched', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));
    $driver->store(new SagaStepRecord('saga-2', 0, SagaStepStatus::Completed));

    $driver->delete('saga-1');

    expect($driver->get('saga-1'))->toHaveCount(0)
        ->and($driver->get('saga-2'))->toHaveCount(1);
});

it('prunes sagas whose latest status matches, leaving others', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-completed', 0, SagaStepStatus::Pending));
    $driver->store(new SagaStepRecord('saga-completed', 0, SagaStepStatus::Completed));

    $driver->store(new SagaStepRecord('saga-failed', 0, SagaStepStatus::Pending));
    $driver->store(new SagaStepRecord('saga-failed', 0, SagaStepStatus::Failed));

    $pruned = $driver->prune(SagaStepStatus::Completed);

    expect($pruned)->toBe(1)
        ->and($driver->get('saga-completed'))->toHaveCount(0)
        ->and($driver->get('saga-failed'))->toHaveCount(2);
});

it('prunes only sagas of the given workflow class', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-a', 0, SagaStepStatus::Completed, workflow: 'App\\WorkflowA'));
    $driver->store(new SagaStepRecord('saga-b', 0, SagaStepStatus::Completed, workflow: 'App\\WorkflowB'));

    $pruned = $driver->prune(SagaStepStatus::Completed, workflow: 'App\\WorkflowA');

    expect($pruned)->toBe(1)
        ->and($driver->get('saga-a'))->toHaveCount(0)
        ->and($driver->get('saga-b'))->toHaveCount(1);
});

it('counts matching sagas without deleting them when dryRun is true', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));

    $pruned = $driver->prune(SagaStepStatus::Completed, dryRun: true);

    expect($pruned)->toBe(1)
        ->and($driver->get('saga-1'))->toHaveCount(1);
});

it('only prunes sagas whose latest status matches, not ones that merely passed through it', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));
    $driver->store(new SagaStepRecord('saga-1', 1, SagaStepStatus::Pending));

    $pruned = $driver->prune(SagaStepStatus::Completed);

    expect($pruned)->toBe(0)
        ->and($driver->get('saga-1'))->toHaveCount(2);
});

it('prunes only sagas last touched before the given date', function () {
    $driver = makeDatabaseDriver();

    $driver->store(new SagaStepRecord('saga-old', 0, SagaStepStatus::Completed, recordedAt: new DateTimeImmutable('2020-01-01')));
    $driver->store(new SagaStepRecord('saga-new', 0, SagaStepStatus::Completed, recordedAt: new DateTimeImmutable('2030-01-01')));

    $pruned = $driver->prune(SagaStepStatus::Completed, new DateTimeImmutable('2025-01-01'));

    expect($pruned)->toBe(1)
        ->and($driver->get('saga-old'))->toHaveCount(0)
        ->and($driver->get('saga-new'))->toHaveCount(1);
});

it('returns 0 when pruning finds nothing matching', function () {
    expect(makeDatabaseDriver()->prune(SagaStepStatus::Completed))->toBe(0);
});

it('uses the configured table name', function () {
    config()->set('saga.drivers.database.table', 'custom_saga_steps');
    (require __DIR__.'/../../../database/migrations/2024_01_01_000000_create_saga_steps_table.php')->up();

    $driver = new DatabaseDriver(DB::connection(), 'custom_saga_steps');

    $driver->store(new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed));

    expect(DB::table('custom_saga_steps')->count())->toBe(1)
        ->and(DB::table('saga_steps')->count())->toBe(0);
});
