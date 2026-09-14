<?php

namespace Henzeb\Saga\Exceptions;

use RuntimeException;

class StaleSagaStepException extends RuntimeException
{
    public function __construct(
        public readonly string $sagaId,
        public readonly int $step,
    ) {
        parent::__construct("Saga {$sagaId} step {$step} was redelivered while already in progress; outcome could not be determined.");
    }
}
