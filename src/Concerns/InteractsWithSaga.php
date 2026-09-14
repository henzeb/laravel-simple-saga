<?php

namespace Henzeb\Saga\Concerns;

use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\Exceptions\AwaitingSignalException;

trait InteractsWithSaga
{
    public string $sagaId;
    public string $workflow;
    public int $sagaStepIndex;
    public int $branch = 0;
    public bool $sync = false;
    public mixed $context = null;
    public ?string $deliveredSignal = null;
    public mixed $deliveredSignalPayload = null;

    protected function context(): SagaContext
    {
        return new SagaContext($this->context);
    }

    protected function waitFor(string $name): SagaContext
    {
        if ($this->deliveredSignal === $name) {
            return new SagaContext($this->deliveredSignalPayload);
        }

        throw new AwaitingSignalException($name);
    }

    protected function idempotencyKey(?string $name = null): string
    {
        $key = "saga:{$this->sagaId}:{$this->sagaStepIndex}:{$this->branch}";

        return $name !== null ? "{$key}:{$name}" : $key;
    }
}
