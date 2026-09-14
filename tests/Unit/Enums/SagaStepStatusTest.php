<?php

use Henzeb\Saga\Enums\SagaStepStatus;

it('has the expected cases with string backing values', function () {
    expect(SagaStepStatus::Pending->value)->toBe('pending')
        ->and(SagaStepStatus::Running->value)->toBe('running')
        ->and(SagaStepStatus::Waiting->value)->toBe('waiting')
        ->and(SagaStepStatus::Completed->value)->toBe('completed')
        ->and(SagaStepStatus::Failed->value)->toBe('failed')
        ->and(SagaStepStatus::CompensationPending->value)->toBe('compensation_pending')
        ->and(SagaStepStatus::Compensating->value)->toBe('compensating')
        ->and(SagaStepStatus::CompensationWaiting->value)->toBe('compensation_waiting')
        ->and(SagaStepStatus::Compensated->value)->toBe('compensated')
        ->and(SagaStepStatus::CompensationFailed->value)->toBe('compensation_failed')
        ->and(SagaStepStatus::RolledBack->value)->toBe('rolled_back');
});

it('can be resolved from its string value', function (SagaStepStatus $status) {
    expect(SagaStepStatus::from($status->value))->toBe($status);
})->with(SagaStepStatus::cases());
