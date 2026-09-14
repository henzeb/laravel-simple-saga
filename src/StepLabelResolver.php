<?php

namespace Henzeb\Saga;

use Henzeb\Saga\Attributes\CompensatedBy;
use Henzeb\Saga\Attributes\StepLabel;
use Henzeb\Saga\Contracts\ProvidesStepLabel;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\Enums\SagaStepStatus;
use ReflectionClass;
use Throwable;

class StepLabelResolver
{
    /** @var array<string, array<int, class-string|Parallel>> */
    protected array $stepsByWorkflow = [];

    public function __construct(
        protected WorkflowResolver $resolver = new WorkflowResolver(),
    ) {}

    public function resolve(SagaState $state): ?string
    {
        return $state->workflow !== null ? $this->label($state) : null;
    }

    protected function label(SagaState $state): ?string
    {
        /** @var string $workflow */
        $workflow = $state->workflow;

        if (! isset($this->stepsByWorkflow[$workflow])) {
            try {
                $this->stepsByWorkflow[$workflow] = $this->resolver->resolve($workflow)->steps();
            } catch (Throwable) {
                $this->stepsByWorkflow[$workflow] = [];
            }
        }

        $stepClass = $this->stepsByWorkflow[$workflow][$state->step] ?? null;

        if ($stepClass === null || $stepClass instanceof Parallel) {
            return null;
        }

        $targetClass = $this->isCompensating($state->status)
            ? $this->resolveCompensator($stepClass) ?? $stepClass
            : $stepClass;

        $attribute = (new ReflectionClass($targetClass))->getAttributes(StepLabel::class)[0] ?? null;

        if ($attribute === null) {
            return class_basename($targetClass);
        }

        return $this->resolveValue($attribute->newInstance()->label, $state);
    }

    protected function isCompensating(SagaStepStatus $status): bool
    {
        return in_array($status, [
            SagaStepStatus::CompensationPending,
            SagaStepStatus::Compensating,
            SagaStepStatus::CompensationWaiting,
            SagaStepStatus::Compensated,
            SagaStepStatus::CompensationFailed,
            SagaStepStatus::RolledBack,
        ], true);
    }

    /**
     * @param class-string $stepClass
     * @return class-string|null
     */
    protected function resolveCompensator(string $stepClass): ?string
    {
        $attribute = (new ReflectionClass($stepClass))->getAttributes(CompensatedBy::class)[0] ?? null;

        if ($attribute) {
            return $attribute->newInstance()->compensator;
        }

        return is_subclass_of($stepClass, ShouldCompensate::class) ? $stepClass : null;
    }

    protected function resolveValue(string $value, SagaState $state): string
    {
        if (! class_exists($value)) {
            return $value;
        }

        try {
            $resolved = app($value);

            return match (true) {
                $resolved instanceof ProvidesStepLabel => $resolved->toLabel($state),
                is_callable($resolved) => $resolved($state),
                default => class_basename($value),
            };
        } catch (Throwable) {
            return class_basename($value);
        }
    }
}
