<?php

namespace Henzeb\Saga\Exceptions;

use RuntimeException;

class SignalTimeoutException extends RuntimeException
{
    public function __construct(
        public readonly string $sagaId,
        public readonly int $step,
        public readonly string $signal,
    ) {
        parent::__construct("Saga {$sagaId} step {$step} timed out waiting for signal '{$signal}'.");
    }
}
