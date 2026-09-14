<?php

namespace Henzeb\Saga\Events;

use Henzeb\Saga\DTO\SagaState;

class SagaStepCompensating
{
    public function __construct(
        public readonly SagaState $state,
    ) {}
}
