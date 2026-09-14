<?php

namespace Henzeb\Saga\Exceptions;

use RuntimeException;

class AwaitingSignalException extends RuntimeException
{
    public function __construct(
        public readonly string $signal,
    ) {
        parent::__construct("Waiting for signal '{$signal}'.");
    }
}
