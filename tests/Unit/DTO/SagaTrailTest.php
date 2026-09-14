<?php

use Henzeb\Saga\DTO\SagaTrail;
use Illuminate\Support\Collection;

it('is a Collection', function () {
    expect(new SagaTrail(['a', 'b']))->toBeInstanceOf(Collection::class);
});

it('supports read-only Collection methods normally', function () {
    $trail = new SagaTrail(['a', 'b', 'c']);

    expect($trail->count())->toBe(3)
        ->and($trail->first())->toBe('a')
        ->and($trail->values()->all())->toBe(['a', 'b', 'c']);
});

it('returns a SagaTrail from non-mutating Collection methods', function () {
    $trail = new SagaTrail(['a', 'b']);

    expect($trail->map(fn ($v) => $v))->toBeInstanceOf(SagaTrail::class)
        ->and($trail->values())->toBeInstanceOf(SagaTrail::class)
        ->and($trail->filter(fn ($v) => true))->toBeInstanceOf(SagaTrail::class);
});

it('throws on every mutating Collection method', function (string $method, array $args) {
    $trail = new SagaTrail(['a', 'b']);

    $trail->{$method}(...$args);
})->with([
    'push' => ['push', ['c']],
    'add' => ['add', ['c']],
    'prepend' => ['prepend', ['c']],
    'unshift' => ['unshift', ['c']],
    'pop' => ['pop', []],
    'shift' => ['shift', []],
    'pull' => ['pull', [0]],
    'put' => ['put', [0, 'x']],
    'forget' => ['forget', [0]],
    'splice' => ['splice', [0]],
    'transform' => ['transform', [fn ($v) => $v]],
])->throws(LogicException::class, 'SagaTrail is immutable');

it('throws on array-access writes', function () {
    $trail = new SagaTrail(['a', 'b']);

    $trail[0] = 'x';
})->throws(LogicException::class);

it('throws on array-access unset', function () {
    $trail = new SagaTrail(['a', 'b']);

    unset($trail[0]);
})->throws(LogicException::class);
