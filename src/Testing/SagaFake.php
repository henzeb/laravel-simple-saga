<?php

namespace Henzeb\Saga\Testing;

use Henzeb\Saga\Contracts\Driver;
use Henzeb\Saga\Enums\SagaStepStatus;
use Illuminate\Support\Testing\Fakes\BusFake;
use PHPUnit\Framework\Assert as PHPUnit;

class SagaFake
{
    public function __construct(
        protected Driver $driver,
        protected BusFake $bus,
    ) {}

    /**
     * @param array<int, class-string> $jobs
     */
    public function except(array $jobs): static
    {
        $this->bus->except($jobs);

        return $this;
    }

    public function assertStatus(string $sagaId, SagaStepStatus $status): static
    {
        $actual = $this->driver->latest($sagaId)?->status;

        PHPUnit::assertSame(
            $status, $actual,
            "Expected saga [{$sagaId}] to have status [{$status->value}], but it has ".
            ($actual === null ? 'no recorded state' : "[{$actual->value}]").'.'
        );

        return $this;
    }

    public function assertStarted(string $sagaId): static
    {
        PHPUnit::assertNotNull(
            $this->driver->latest($sagaId),
            "Expected saga [{$sagaId}] to have started, but it has no recorded state."
        );

        return $this;
    }

    public function assertCompleted(string $sagaId): static
    {
        return $this->assertStatus($sagaId, SagaStepStatus::Completed);
    }

    public function assertFailed(string $sagaId): static
    {
        return $this->assertStatus($sagaId, SagaStepStatus::Failed);
    }

    public function assertRolledBack(string $sagaId): static
    {
        return $this->assertStatus($sagaId, SagaStepStatus::RolledBack);
    }

    public function assertCompensationFailed(string $sagaId): static
    {
        return $this->assertStatus($sagaId, SagaStepStatus::CompensationFailed);
    }
}
