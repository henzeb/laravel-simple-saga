<?php

namespace Henzeb\Saga\Exceptions;

use RuntimeException;

class SagaInterruptedException extends RuntimeException
{
    public function __construct(
        public readonly string $sagaId,
    ) {
        parent::__construct("Saga {$sagaId} was interrupted (SIGINT) and has been compensated.");
    }
}
