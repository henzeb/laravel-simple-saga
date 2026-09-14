<?php

use Henzeb\Saga\Exceptions\WorkflowResolutionException;
use Henzeb\Saga\WorkflowResolver;
use Tests\Support\CoordinatorTestOneStepWorkflow;

it('resolves a workflow class name to an instance through the container', function () {
    $workflow = (new WorkflowResolver())->resolve(CoordinatorTestOneStepWorkflow::class);

    expect($workflow)->toBeInstanceOf(CoordinatorTestOneStepWorkflow::class);
});

it('throws when the resolved class is not a Workflow', function () {
    (new WorkflowResolver())->resolve(stdClass::class);
})->throws(WorkflowResolutionException::class, stdClass::class.' does not resolve to a Henzeb\Saga\Workflow instance.');
