<?php

namespace Henzeb\Saga\Providers;

use Henzeb\Saga\Console\Commands\SagaCompensateCommand;
use Henzeb\Saga\Console\Commands\SagaDeleteCommand;
use Henzeb\Saga\Console\Commands\SagaListCommand;
use Henzeb\Saga\Console\Commands\SagaPruneCommand;
use Henzeb\Saga\Console\Commands\SagaPruneFailedCommand;
use Henzeb\Saga\Console\Commands\SagaRetryCommand;
use Henzeb\Saga\Console\Commands\SagaShowCommand;
use Henzeb\Saga\Console\Commands\SagaSweepSignalsCommand;
use Henzeb\Saga\Console\Commands\SagaSweepStaleCommand;
use Henzeb\Saga\SagaManager;
use Illuminate\Support\ServiceProvider;

class SagaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/saga.php', 'saga');

        $this->app->singleton(SagaManager::class, fn ($app) => new SagaManager($app));
        $this->app->alias(SagaManager::class, 'saga');
    }

    public function boot(): void
    {
        if (config('saga.default') === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'saga-migrations');

        $this->publishes([
            __DIR__.'/../../config/saga.php' => config_path('saga.php'),
        ], 'laravel-simple-saga');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SagaPruneCommand::class,
                SagaPruneFailedCommand::class,
                SagaShowCommand::class,
                SagaDeleteCommand::class,
                SagaRetryCommand::class,
                SagaSweepSignalsCommand::class,
                SagaSweepStaleCommand::class,
                SagaListCommand::class,
                SagaCompensateCommand::class,
            ]);
        }
    }
}
