<?php

use Henzeb\Saga\Exceptions\InvalidWorkflowException;

it('carries the workflow class and errors, and builds a readable message', function () {
    $exception = new InvalidWorkflowException('App\Workflows\Checkout', [
        'Step one is broken.',
        'Step two is broken.',
    ]);

    expect($exception->workflow)->toBe('App\Workflows\Checkout')
        ->and($exception->errors)->toBe(['Step one is broken.', 'Step two is broken.'])
        ->and($exception->getMessage())
        ->toContain('App\Workflows\Checkout')
        ->toContain('Step one is broken.')
        ->toContain('Step two is broken.');
});
