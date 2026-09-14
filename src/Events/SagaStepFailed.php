<?php

namespace Henzeb\Saga\Events;

use Henzeb\Saga\DTO\SagaState;
use Throwable;

class SagaStepFailed
{
    public function __construct(
        public readonly SagaState $state,
        public readonly ?Throwable $exception,
    ) {}
}
