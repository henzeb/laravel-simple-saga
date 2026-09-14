<?php

namespace Henzeb\Saga\Exceptions;

use RuntimeException;

class SagaNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $sagaId,
    ) {
        parent::__construct("Saga {$sagaId} could not be found.");
    }
}
