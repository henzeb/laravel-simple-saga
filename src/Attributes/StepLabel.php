<?php

namespace Henzeb\Saga\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class StepLabel
{
    public function __construct(
        public readonly string $label,
    ) {}
}
