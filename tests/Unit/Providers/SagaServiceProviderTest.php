<?php

use Henzeb\Saga\Providers\SagaServiceProvider;
use Henzeb\Saga\SagaManager;

it('registers the service provider', function () {
    expect($this->app->getProviders(SagaServiceProvider::class))->not->toBeEmpty();
});

it('merges the default saga config', function () {
    expect(config('saga.default'))->toBe('database')
        ->and(config('saga.on_stale_running'))->toBe('fail')
        ->and(config('saga.drivers.database.table'))->toBe('saga_steps');
});

it('binds the saga manager as a singleton', function () {
    $manager = $this->app->make('saga');

    expect($manager)->toBeInstanceOf(SagaManager::class)
        ->and($this->app->make('saga'))->toBe($manager);
});

it('loads the saga_steps migration when the default driver is database', function () {
    $this->artisan('migrate')->run();

    expect(Illuminate\Support\Facades\Schema::hasTable('saga_steps'))->toBeTrue();
});
