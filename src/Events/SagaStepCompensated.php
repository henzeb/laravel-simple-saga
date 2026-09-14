<?php

namespace Henzeb\Saga\Events;

use Henzeb\Saga\DTO\SagaState;

class SagaStepCompensated
{
    public function __construct(
        public readonly SagaState $state,
    ) {}
}
