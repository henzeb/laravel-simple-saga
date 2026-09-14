<?php

use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\Drivers\CacheDriver;
use Henzeb\Saga\Drivers\DatabaseDriver;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\SagaManager;
use Henzeb\Saga\SagaWorkflow;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoordinatorTestCompensatingWorkflow;
use Tests\Support\CoordinatorTestOneStepWorkflow;
use Tests\Support\CoordinatorTestRefundStepOne;
use Tests\Support\CoordinatorTestStepOne;

beforeEach(function () {
    Bus::fake();
});

it('defaults to the database driver', function () {
    $manager = new SagaManager($this->app);

    expect($manager->getDefaultDriver())->toBe('database')
        ->and($manager->driver())->toBeInstanceOf(DatabaseDriver::class);
});

it('resolves the cache driver when configured', function () {
    config()->set('saga.default', 'cache');

    $manager = new SagaManager($this->app);

    expect($manager->driver())->toBeInstanceOf(CacheDriver::class);
});

it('builds the database driver using the configured table', function () {
    config()->set('saga.drivers.database.table', 'custom_saga_steps');
    (require __DIR__.'/../../database/migrations/2024_01_01_000000_create_saga_steps_table.php')->up();

    $manager = new SagaManager($this->app);

    $manager->workflow(new CoordinatorTestOneStepWorkflow())->start(null);

    expect(\Illuminate\Support\Facades\DB::table('custom_saga_steps')->count())->toBe(1);
});

it('starts and gets a saga through the manager', function () {
    $this->artisan('migrate')->run();

    $manager = new SagaManager($this->app);

    $workflow = $manager->workflow(new CoordinatorTestOneStepWorkflow(), 'order-1');
    $state = $workflow->start(['x' => 1]);

    expect($state)->toBeInstanceOf(SagaState::class);

    $fetched = $workflow->current();

    expect($fetched->sagaId)->toBe($state->sagaId);
});

it('delivers a signal through the manager', function () {
    $this->artisan('migrate')->run();

    $manager = new SagaManager($this->app);

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1',
        'step' => 0,
        'status' => 'waiting',
        'workflow' => CoordinatorTestOneStepWorkflow::class,
        'payload' => json_encode(['order' => 1]),
        'recorded_at' => now(),
        'encrypted' => false,
        'signal' => 'approval',
    ]);

    $state = $manager->coordinator()->signal('saga-1', 'approval', ['approved' => true]);

    expect($state->status)->toBe(SagaStepStatus::Pending)
        ->and($state->context()->raw())->toBe(['order' => 1]);

    Bus::assertDispatched(CoordinatorTestStepOne::class, function ($job) {
        return $job->sagaId === 'saga-1'
            && $job->context === ['order' => 1]
            && $job->deliveredSignalPayload === ['approved' => true];
    });
});

it('sweeps sagas whose signal wait has expired, through the manager', function () {
    $this->artisan('migrate')->run();

    $manager = new SagaManager($this->app);

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1',
        'step' => 0,
        'status' => 'waiting',
        'workflow' => CoordinatorTestOneStepWorkflow::class,
        'payload' => null,
        'recorded_at' => now(),
        'encrypted' => false,
        'signal' => 'approval',
        'signal_expires_at' => now()->subMinute(),
    ]);

    expect($manager->due())->toHaveCount(1);

    $count = $manager->sweepSignals();

    expect($count)->toBe(1)
        ->and($manager->coordinator()->current('saga-1')->status)->toBe(SagaStepStatus::Failed);
});

it('propagates sync: true from sweepSignals() through to the compensation it starts', function () {
    $this->artisan('migrate')->run();

    $manager = new SagaManager($this->app);

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1',
        'step' => 0,
        'status' => 'completed',
        'workflow' => CoordinatorTestCompensatingWorkflow::class,
        'payload' => json_encode(['order' => 1]),
        'recorded_at' => now(),
        'encrypted' => false,
        'signal' => null,
        'signal_expires_at' => null,
    ]);

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1',
        'step' => 1,
        'status' => 'waiting',
        'workflow' => CoordinatorTestCompensatingWorkflow::class,
        'payload' => null,
        'recorded_at' => now(),
        'encrypted' => false,
        'signal' => 'approval',
        'signal_expires_at' => now()->subMinute(),
    ]);

    $manager->sweepSignals(sync: true);

    Bus::assertDispatched(CoordinatorTestRefundStepOne::class, fn ($job) => $job->sync === true);
});

it('sweeps sagas whose running step has timed out, through the manager', function () {
    $this->artisan('migrate')->run();

    $manager = new SagaManager($this->app);

    DB::table('saga_steps')->insert([
        'saga_id' => 'saga-1',
        'step' => 0,
        'status' => 'running',
        'workflow' => CoordinatorTestOneStepWorkflow::class,
        'payload' => null,
        'recorded_at' => now(),
        'encrypted' => false,
        'running_expires_at' => now()->subMinute(),
    ]);

    expect($manager->dueRunning())->toHaveCount(1);

    $count = $manager->sweepStale();

    expect($count)->toBe(1)
        ->and($manager->coordinator()->current('saga-1')->status)->toBe(SagaStepStatus::Failed);
});

it('builds a SagaWorkflow bound to the given workflow, from an instance or a class name', function () {
    $this->artisan('migrate')->run();

    $manager = new SagaManager($this->app);

    expect($manager->workflow(new CoordinatorTestOneStepWorkflow()))->toBeInstanceOf(SagaWorkflow::class)
        ->and($manager->workflow(CoordinatorTestOneStepWorkflow::class))->toBeInstanceOf(SagaWorkflow::class);

    $state = $manager->workflow(CoordinatorTestOneStepWorkflow::class)->start(['x' => 1]);

    expect($state)->toBeInstanceOf(SagaState::class);
});

it('binds an idempotency key given to workflow(), so start() never needs it repeated', function () {
    $this->artisan('migrate')->run();

    $manager = new SagaManager($this->app);

    $first = $manager->workflow(CoordinatorTestOneStepWorkflow::class, 'order-123')->start(['x' => 1]);
    $second = $manager->workflow(CoordinatorTestOneStepWorkflow::class, 'order-123')->start(['x' => 999]);

    expect($second->sagaId)->toBe($first->sagaId);
});

it('throws when the resolved driver does not implement the Driver contract', function () {
    $manager = new SagaManager($this->app);
    $manager->extend('broken', fn () => new stdClass());
    config()->set('saga.default', 'broken');

    $manager->coordinator();
})->throws(LogicException::class, 'must implement Henzeb\Saga\Contracts\Driver.');
