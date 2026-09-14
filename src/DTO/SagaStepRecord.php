<?php

namespace Henzeb\Saga\DTO;

use DateTimeImmutable;
use Henzeb\Saga\Enums\SagaStepStatus;

class SagaStepRecord
{
    public function __construct(
        public readonly string $sagaId,
        public readonly int $step,
        public readonly SagaStepStatus $status,
        public readonly int $branch = 0,
        public readonly ?string $workflow = null,
        public readonly mixed $payload = null,
        public readonly ?string $reason = null,
        public readonly ?DateTimeImmutable $recordedAt = null,
        public readonly bool $encrypted = false,
        public readonly ?string $signal = null,
        public readonly ?DateTimeImmutable $signalExpiresAt = null,
        public readonly ?DateTimeImmutable $runningExpiresAt = null,
    ) {}

    public function context(): SagaContext
    {
        return new SagaContext($this->payload, $this->encrypted);
    }
}
