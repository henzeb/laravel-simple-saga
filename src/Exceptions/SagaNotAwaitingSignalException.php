<?php

namespace Henzeb\Saga\Exceptions;

use RuntimeException;

class SagaNotAwaitingSignalException extends RuntimeException
{
    public function __construct(
        public readonly string $sagaId,
        public readonly string $signal,
    ) {
        parent::__construct("Saga {$sagaId} is not waiting for signal '{$signal}'.");
    }
}
