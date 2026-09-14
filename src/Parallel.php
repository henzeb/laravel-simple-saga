<?php

namespace Henzeb\Saga;

class Parallel
{
    /** @var class-string[] */
    protected array $steps;

    protected bool $failFast = false;

    protected bool $queued = false;

    /**
     * @param class-string ...$steps
     */
    public function __construct(string ...$steps)
    {
        $this->steps = $steps;
    }

    public function failFast(): static
    {
        $this->failFast = true;

        return $this;
    }

    /**
     * Dispatch this group's branches onto the real queue, even when the saga
     * is otherwise being run with sync: true.
     */
    public function queued(): static
    {
        $this->queued = true;

        return $this;
    }

    /** @return class-string[] */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isFailFast(): bool
    {
        return $this->failFast;
    }

    public function isQueued(): bool
    {
        return $this->queued;
    }
}
