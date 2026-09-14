<?php

namespace Henzeb\Saga\DTO;

use Illuminate\Support\Collection;
use LogicException;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
class SagaTrail extends Collection
{
    public function push(...$values): static
    {
        throw $this->immutable();
    }

    public function add($item): static
    {
        throw $this->immutable();
    }

    public function prepend($value, $key = null): static
    {
        throw $this->immutable();
    }

    public function unshift(...$values): static
    {
        throw $this->immutable();
    }

    public function pop($count = 1): mixed
    {
        throw $this->immutable();
    }

    public function shift($count = 1): mixed
    {
        throw $this->immutable();
    }

    public function pull($key, $default = null): mixed
    {
        throw $this->immutable();
    }

    public function put($key, $value): static
    {
        throw $this->immutable();
    }

    public function forget($keys): static
    {
        throw $this->immutable();
    }

    public function splice($offset, $length = null, $replacement = []): static
    {
        throw $this->immutable();
    }

    // @phpstan-ignore-next-line method.childReturnType (parent's @phpstan-this-out can't be satisfied by a method that only throws)
    public function transform(callable $callback)
    {
        throw $this->immutable();
    }

    public function offsetSet($offset, $value): void
    {
        throw $this->immutable();
    }

    public function offsetUnset($offset): void
    {
        throw $this->immutable();
    }

    protected function immutable(): LogicException
    {
        return new LogicException('SagaTrail is immutable — it records history, not a working list.');
    }
}
