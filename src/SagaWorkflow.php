<?php

namespace Henzeb\Saga;

use Henzeb\Saga\DTO\SagaState;
use Illuminate\Support\Str;
use LogicException;

class SagaWorkflow
{
    protected string $resolvedSagaId;

    public function __construct(
        protected SagaCoordinator $coordinator,
        protected Workflow $workflow,
        protected ?string $sagaId = null,
    ) {
        $this->resolvedSagaId = $this->sagaId !== null
            ? $this->coordinator->sagaIdFor($this->workflow, $this->sagaId)
            : (string) Str::ulid();
    }

    public function id(): string
    {
        return $this->resolvedSagaId;
    }

    public function start(mixed $context, bool $sync = false): SagaState
    {
        return $this->coordinator->start($this->workflow, $context, $sync, $this->id());
    }

    public function current(): SagaState
    {
        $this->assertBound();

        return $this->coordinator->current($this->id());
    }

    public function label(): ?string
    {
        $this->assertBound();

        return $this->coordinator->current($this->id())->label();
    }

    public function signal(string $name, mixed $payload = null, bool $sync = false): SagaState
    {
        $this->assertBound();

        return $this->coordinator->signal($this->id(), $name, $payload, $sync);
    }

    public function compensate(bool $sync = false): SagaState
    {
        $this->assertBound();

        return $this->coordinator->compensate($this->id(), $sync);
    }

    public function delete(): void
    {
        $this->assertBound();

        $this->coordinator->delete($this->id());
    }

    public function retry(mixed $context = null, bool $sync = false): SagaState
    {
        $this->assertBound();

        return $this->coordinator->retry($this->id(), $this->workflow, $context, $sync);
    }

    public function retryCompensation(bool $sync = false): SagaState
    {
        $this->assertBound();

        return $this->coordinator->retryCompensation($this->id(), $this->workflow, $sync);
    }

    protected function assertBound(): void
    {
        if ($this->sagaId === null) {
            throw new LogicException(
                'This needs the sagaId of an existing saga — pass it to Saga::workflow($workflow, $sagaId).'
            );
        }
    }
}
