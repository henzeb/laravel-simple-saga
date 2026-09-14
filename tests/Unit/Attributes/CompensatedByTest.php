<?php

use Henzeb\Saga\Attributes\CompensatedBy;

#[CompensatedBy(stdClass::class)]
class CompensatedByTestStep {}

it('exposes the compensator class it was constructed with', function () {
    $compensatedBy = new CompensatedBy(stdClass::class);

    expect($compensatedBy->compensator)->toBe(stdClass::class);
});

it('can be read back off a class via reflection', function () {
    $attribute = (new ReflectionClass(CompensatedByTestStep::class))
        ->getAttributes(CompensatedBy::class)[0] ?? null;

    expect($attribute)->not->toBeNull()
        ->and($attribute->newInstance()->compensator)->toBe(stdClass::class);
});
