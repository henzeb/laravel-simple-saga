<?php

namespace Henzeb\Saga\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class CompensatedBy
{
    /**
     * @param class-string $compensator
     */
    public function __construct(
        public readonly string $compensator,
    ) {}
}
