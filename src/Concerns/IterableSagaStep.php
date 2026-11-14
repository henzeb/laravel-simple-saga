<?php

namespace Henzeb\Saga\Concerns;

// @phpstan-ignore trait.unused
trait IterableSagaStep
{
    abstract protected function hasNext(): bool;

    protected function next(): void
    {
    }
}
