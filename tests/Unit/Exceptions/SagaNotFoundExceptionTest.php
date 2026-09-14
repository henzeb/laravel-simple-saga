<?php

use Henzeb\Saga\Exceptions\SagaNotFoundException;

it('carries the saga id and builds a readable message', function () {
    $exception = new SagaNotFoundException('saga-123');

    expect($exception->sagaId)->toBe('saga-123')
        ->and($exception->getMessage())->toContain('saga-123');
});
