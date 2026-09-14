<?php

namespace Henzeb\Saga\Events;

use Henzeb\Saga\DTO\SagaState;

class SagaRolledBack
{
    public function __construct(
        public readonly SagaState $state,
    ) {}
}
