<?php

namespace Henzeb\Saga\Contracts;

use Henzeb\Saga\DTO\SagaState;

interface ProvidesStepLabel
{
    public function toLabel(SagaState $state): string;
}
