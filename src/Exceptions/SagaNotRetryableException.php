<?php

namespace Henzeb\Saga\Exceptions;

use Henzeb\Saga\Enums\SagaStepStatus;
use RuntimeException;

class SagaNotRetryableException extends RuntimeException
{
    public function __construct(
        public readonly string $sagaId,
        public readonly SagaStepStatus $status,
    ) {
        parent::__construct("Saga {$sagaId} cannot be retried from its current status ({$status->value}).");
    }
}
