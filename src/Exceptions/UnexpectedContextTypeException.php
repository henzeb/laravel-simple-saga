<?php

namespace Henzeb\Saga\Exceptions;

use UnexpectedValueException;

class UnexpectedContextTypeException extends UnexpectedValueException
{
    public function __construct(
        public readonly string $expected,
        public readonly mixed $actual,
    ) {
        $type = get_debug_type($actual);

        parent::__construct("Expected saga context value of type {$expected}, got {$type}.");
    }
}
