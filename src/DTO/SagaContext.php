<?php

namespace Henzeb\Saga\DTO;

use BackedEnum;
use Henzeb\Saga\Exceptions\UnexpectedContextTypeException;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

class SagaContext
{
    use SerializesAndRestoresModelIdentifiers;

    private bool $resolved = false;
    private mixed $value = null;

    public function __construct(
        private readonly mixed $payload,
        private readonly bool $encrypted = false,
    ) {}

    protected function resolve(): mixed
    {
        if (! $this->resolved) {
            $payload = $this->encrypted && is_string($this->payload)
                ? Crypt::decrypt($this->payload)
                : $this->payload;

            $this->value = $this->getRestoredPropertyValue($payload);
            $this->resolved = true;
        }

        return $this->value;
    }

    public function raw(): mixed
    {
        return $this->resolve();
    }

    /**
     * @template T
     * @param class-string<T> $class
     * @return T|null
     */
    public function object(string $class): mixed
    {
        $value = $this->resolve();

        if ($value === null) {
            return null;
        }

        if (! $value instanceof $class) {
            throw new UnexpectedContextTypeException($class, $value);
        }

        return $value;
    }

    /**
     * @template TEnum of BackedEnum
     * @param class-string<TEnum> $enumClass
     * @return TEnum|null
     */
    public function enum(string $enumClass): ?BackedEnum
    {
        $value = $this->resolve();

        if ($value === null) {
            return null;
        }

        if ($value instanceof $enumClass) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedContextTypeException('string|int', $value);
        }

        $case = $enumClass::tryFrom($value);

        if ($case === null) {
            throw new UnexpectedContextTypeException($enumClass, $value);
        }

        return $case;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function array(): ?array
    {
        $value = $this->resolve();

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new UnexpectedContextTypeException('array', $value);
        }

        return $value;
    }

    /**
     * @return Collection<array-key, mixed>|null
     */
    public function collect(): ?Collection
    {
        $value = $this->resolve();

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new UnexpectedContextTypeException('array', $value);
        }

        return collect($value);
    }

    public function string(): ?Stringable
    {
        $value = $this->resolve();

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new UnexpectedContextTypeException('string', $value);
        }

        return Str::of($value);
    }

    public function integer(): ?int
    {
        $value = $this->resolve();

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new UnexpectedContextTypeException('int', $value);
        }

        return $value;
    }

    public function float(): ?float
    {
        $value = $this->resolve();

        if ($value === null) {
            return null;
        }

        if (! is_float($value)) {
            throw new UnexpectedContextTypeException('float', $value);
        }

        return $value;
    }

    public function boolean(): ?bool
    {
        $value = $this->resolve();

        if ($value === null) {
            return null;
        }

        if (! is_bool($value)) {
            throw new UnexpectedContextTypeException('bool', $value);
        }

        return $value;
    }
}
