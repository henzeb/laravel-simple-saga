<?php

namespace Henzeb\Saga\Concerns;

trait IterableSagaStep
{
    abstract protected function hasNext(): bool;

    protected function next(): void
    {
    }
}
