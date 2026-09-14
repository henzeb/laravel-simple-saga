<?php

namespace Henzeb\Saga\Facades;

use Henzeb\Saga\Drivers\CacheDriver;
use Henzeb\Saga\SagaManager;
use Henzeb\Saga\Testing\SagaFake;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;

class Saga extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'saga';
    }

    public static function fake(): SagaFake
    {
        $driver = new CacheDriver(Cache::store('array'));

        app(SagaManager::class)->extend('array', fn () => $driver);
        Config::set('saga.default', 'array');

        return new SagaFake($driver, Bus::fake());
    }
}
