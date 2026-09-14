<?php

namespace Henzeb\Saga\Exceptions;

use Henzeb\Saga\Workflow;
use InvalidArgumentException;

class WorkflowResolutionException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $workflow,
    ) {
        parent::__construct("{$workflow} does not resolve to a ".Workflow::class.' instance.');
    }
}
