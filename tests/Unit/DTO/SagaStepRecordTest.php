<?php

use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\DTO\SagaStepRecord;
use Henzeb\Saga\Enums\SagaStepStatus;

it('exposes its constructor arguments as readonly properties', function () {
    $recordedAt = new DateTimeImmutable();

    $record = new SagaStepRecord(
        sagaId: 'saga-1',
        step: 2,
        status: SagaStepStatus::Failed,
        payload: ['foo' => 'bar'],
        reason: 'something broke',
        recordedAt: $recordedAt,
        signal: 'approval',
    );

    expect($record->sagaId)->toBe('saga-1')
        ->and($record->step)->toBe(2)
        ->and($record->status)->toBe(SagaStepStatus::Failed)
        ->and($record->payload)->toBe(['foo' => 'bar'])
        ->and($record->reason)->toBe('something broke')
        ->and($record->recordedAt)->toBe($recordedAt)
        ->and($record->signal)->toBe('approval');
});

it('defaults payload, reason, recordedAt and signal to null', function () {
    $record = new SagaStepRecord('saga-1', 0, SagaStepStatus::Pending);

    expect($record->payload)->toBeNull()
        ->and($record->reason)->toBeNull()
        ->and($record->recordedAt)->toBeNull()
        ->and($record->signal)->toBeNull();
});

it('wraps its payload in a SagaContext', function () {
    $record = new SagaStepRecord('saga-1', 0, SagaStepStatus::Completed, payload: ['foo' => 'bar']);

    $context = $record->context();

    expect($context)->toBeInstanceOf(SagaContext::class)
        ->and($context->raw())->toBe(['foo' => 'bar']);
});
