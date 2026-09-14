<?php

namespace Henzeb\Saga\DTO;

use Closure;
use DateTimeImmutable;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\StepLabelResolver;

class SagaState
{
    private ?SagaContext $resolvedContext = null;

    /** @var SagaTrail<int, SagaState>|null */
    private ?SagaTrail $resolvedTrail = null;

    private bool $labelResolved = false;

    private ?string $resolvedLabel = null;

    public function __construct(
        public readonly string $sagaId,
        public readonly ?string $workflow,
        public readonly SagaStepStatus $status,
        public readonly int $step,
        private readonly Closure $contextResolver,
        public readonly ?string $reason,
        private readonly Closure $trailResolver,
        public readonly ?string $signal = null,
        public readonly ?DateTimeImmutable $signalExpiresAt = null,
        public readonly ?DateTimeImmutable $recordedAt = null,
        public readonly ?DateTimeImmutable $runningExpiresAt = null,
    ) {}

    public function context(): SagaContext
    {
        return $this->resolvedContext ??= ($this->contextResolver)();
    }

    /** @return SagaTrail<int, SagaState> */
    public function trail(): SagaTrail
    {
        return $this->resolvedTrail ??= ($this->trailResolver)();
    }

    public function label(): ?string
    {
        if (! $this->labelResolved) {
            $this->resolvedLabel = app(StepLabelResolver::class)->resolve($this);
            $this->labelResolved = true;
        }

        return $this->resolvedLabel;
    }
}
