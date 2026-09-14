<?php

namespace Tests;

use Henzeb\Saga\Providers\SagaServiceProvider;
use Illuminate\Encryption\Encrypter;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SagaServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC')));
    }
}
