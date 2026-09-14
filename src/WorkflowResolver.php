<?php

namespace Henzeb\Saga;

use Henzeb\Saga\Exceptions\WorkflowResolutionException;

class WorkflowResolver
{
    public function resolve(string $workflow): Workflow
    {
        $instance = app($workflow);

        if (! $instance instanceof Workflow) {
            throw new WorkflowResolutionException($workflow);
        }

        return $instance;
    }
}
