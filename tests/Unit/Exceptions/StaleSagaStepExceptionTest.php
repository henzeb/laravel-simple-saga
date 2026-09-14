<?php

use Henzeb\Saga\Exceptions\StaleSagaStepException;

it('carries the saga id and step and builds a readable message', function () {
    $exception = new StaleSagaStepException('saga-123', 2);

    expect($exception->sagaId)->toBe('saga-123')
        ->and($exception->step)->toBe(2)
        ->and($exception->getMessage())
        ->toContain('saga-123')
        ->toContain('step 2');
});
