<?php

namespace Henzeb\Saga;

use Henzeb\Saga\Attributes\CompensatedBy;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Henzeb\Saga\Exceptions\InvalidWorkflowException;
use Henzeb\Saga\Parallel;
use ReflectionClass;

class WorkflowValidator
{
    /** @var array<class-string<Workflow>, true> */
    protected static array $validated = [];

    public function validate(Workflow $workflow): void
    {
        if (isset(static::$validated[$workflow::class])) {
            return;
        }

        $errors = [];

        foreach ($workflow->steps() as $step) {
            foreach ($step instanceof Parallel ? $step->steps() : [$step] as $branch) {
                $errors = [...$errors, ...$this->validateStep($branch)];
            }
        }

        if ($errors !== []) {
            throw new InvalidWorkflowException($workflow::class, $errors);
        }

        static::$validated[$workflow::class] = true;
    }

    /**
     * @param class-string $step
     * @return string[]
     */
    protected function validateStep(string $step): array
    {
        $errors = [];

        if (! in_array(InteractsWithSaga::class, class_uses_recursive($step), true)) {
            $errors[] = "{$step} must use the InteractsWithSaga trait.";
        }

        $selfCompensates = method_exists($step, 'compensate');
        $attribute = (new ReflectionClass($step))->getAttributes(CompensatedBy::class)[0] ?? null;

        if ($selfCompensates && $attribute) {
            $errors[] = "{$step} cannot both define compensate() and carry #[CompensatedBy].";
        } elseif ($attribute) {
            $target = $attribute->newInstance()->compensator;
            if (! is_subclass_of($target, ShouldCompensate::class)) {
                $errors[] = "{$step}'s compensator {$target} must implement ShouldCompensate.";
            }
        }

        return $errors;
    }
}
