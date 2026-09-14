<?php

use Henzeb\Saga\DTO\SagaContext;
use Henzeb\Saga\Exceptions\UnexpectedContextTypeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SagaContextTestModel extends Model
{
    public $timestamps = false;
    protected $table = 'saga_context_test_models';
    protected $guarded = [];
}

enum SagaContextTestStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
}

beforeEach(function () {
    Schema::create('saga_context_test_models', function ($table) {
        $table->id();
        $table->string('name');
    });
});

it('passes an array payload through unchanged', function () {
    $context = new SagaContext(['orderId' => 'abc']);

    expect($context->raw())->toBe(['orderId' => 'abc'])
        ->and($context->array())->toBe(['orderId' => 'abc']);
});

it('passes a scalar payload through unchanged', function () {
    $context = new SagaContext('a-scalar-value');

    expect($context->raw())->toBe('a-scalar-value')
        ->and($context->string()->value())->toBe('a-scalar-value');
});

it('exposes integer, float and boolean scalars through their matching accessor', function () {
    expect((new SagaContext(42))->integer())->toBe(42)
        ->and((new SagaContext(4.2))->float())->toBe(4.2)
        ->and((new SagaContext(true))->boolean())->toBeTrue();
});

it('exposes an array payload as a Collection via collect()', function () {
    $context = new SagaContext(['orderId' => 'abc']);

    expect($context->collect())->toBeInstanceOf(Collection::class)
        ->and($context->collect()->all())->toBe(['orderId' => 'abc']);
});

it('asserts the shape when collect() does not match the context', function () {
    (new SagaContext('not an array'))->collect();
})->throws(UnexpectedContextTypeException::class);

it('asserts the shape when a scalar accessor does not match the context', function () {
    (new SagaContext('not an array'))->array();
})->throws(UnexpectedContextTypeException::class);

it('asserts the shape when string() does not match the context', function () {
    (new SagaContext(42))->string();
})->throws(UnexpectedContextTypeException::class);

it('returns null from every typed accessor when the context itself is null', function () {
    $context = new SagaContext(null);

    expect($context->raw())->toBeNull()
        ->and($context->object(SagaContextTestModel::class))->toBeNull()
        ->and($context->array())->toBeNull()
        ->and($context->collect())->toBeNull()
        ->and($context->string())->toBeNull()
        ->and($context->integer())->toBeNull()
        ->and($context->float())->toBeNull()
        ->and($context->boolean())->toBeNull()
        ->and($context->enum(SagaContextTestStatus::class))->toBeNull();
});

it('resolves a backing scalar payload into the matching enum case via enum()', function () {
    $context = new SagaContext('completed');

    expect($context->enum(SagaContextTestStatus::class))->toBe(SagaContextTestStatus::Completed);
});

it('returns an already-resolved enum instance as-is via enum()', function () {
    $context = new SagaContext(SagaContextTestStatus::Pending);

    expect($context->enum(SagaContextTestStatus::class))->toBe(SagaContextTestStatus::Pending);
});

it('asserts the value matches a valid case when narrowing via enum()', function () {
    $context = new SagaContext('not-a-real-case');

    $context->enum(SagaContextTestStatus::class);
})->throws(UnexpectedContextTypeException::class);

it('resolves a model identifier payload back into the real model', function () {
    $model = SagaContextTestModel::create(['name' => 'foo']);

    $serializer = new class {
        use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;

        public function serialize(mixed $value): mixed
        {
            return $this->getSerializedPropertyValue($value);
        }
    };

    $context = new SagaContext($serializer->serialize($model));

    $restored = $context->object(SagaContextTestModel::class);

    expect($restored)->toBeInstanceOf(SagaContextTestModel::class)
        ->and($restored->is($model))->toBeTrue();
});

it('only resolves the payload once', function () {
    $model = SagaContextTestModel::create(['name' => 'foo']);
    $context = new SagaContext($model);

    $first = $context->raw();
    $second = $context->raw();

    expect($first)->toBe($second);
});

it('asserts the object type when narrowing via object()', function () {
    $context = new SagaContext('not a model');

    $context->object(SagaContextTestModel::class);
})->throws(UnexpectedContextTypeException::class);
