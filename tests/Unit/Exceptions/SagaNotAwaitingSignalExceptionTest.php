<?php

use Henzeb\Saga\Exceptions\SagaNotAwaitingSignalException;

it('carries the saga id and signal name and builds a readable message', function () {
    $exception = new SagaNotAwaitingSignalException('saga-123', 'approval');

    expect($exception->sagaId)->toBe('saga-123')
        ->and($exception->signal)->toBe('approval')
        ->and($exception->getMessage())
        ->toContain('saga-123')
        ->toContain('approval');
});
