<?php

namespace Henzeb\Saga\Exceptions;

use InvalidArgumentException;

class InvalidWorkflowException extends InvalidArgumentException
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public readonly string $workflow,
        public readonly array $errors,
    ) {
        parent::__construct(
            sprintf("Workflow %s is invalid:\n- %s", $workflow, implode("\n- ", $errors))
        );
    }
}
