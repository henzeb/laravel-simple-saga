<?php

namespace Henzeb\Saga;

abstract class Workflow
{
    /** @return array<class-string|Parallel> */
    abstract public function steps(): array;

    /**
     * @param class-string ...$steps
     */
    protected function parallel(string ...$steps): Parallel
    {
        return new Parallel(...$steps);
    }

    public function onStaleRunning(): ?string
    {
        return null; // null = defer to config('saga.on_stale_running')
    }

    public function encryptContext(): bool
    {
        return false;
    }

    public function signalTimeout(): ?int
    {
        return null; // null = defer to config('saga.signal_timeout'); either null = no timeout
    }

    public function runningTimeout(): ?int
    {
        return null; // null = defer to config('saga.running_timeout'); either null = no timeout
    }
}
