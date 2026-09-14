# Defining Workflows

- [Introduction](#introduction)
- [Starting a Saga](#starting-a-saga)
- [Starting Idempotently](#starting-idempotently)
- [Running a Saga Synchronously](#running-a-saga-synchronously)
- [Retrying Stale Steps](#retrying-stale-steps)

<a name="introduction"></a>
## Introduction

A workflow describes a saga as an ordered list of steps. You define one by extending
the abstract `Workflow` class and returning the step classes, in the order they should
run, from `steps()`:

```php
namespace App\Sagas;

use App\Sagas\Steps\ChargePayment;
use App\Sagas\Steps\ReserveStock;
use App\Sagas\Steps\ShipOrder;
use Henzeb\Saga\Workflow;

class PlaceOrder extends Workflow
{
    public function steps(): array
    {
        return [
            ReserveStock::class,
            ChargePayment::class,
            ShipOrder::class,
        ];
    }
}
```

Notice that `steps()` returns the step classes themselves, not instances or a
configuration array. Each entry is a real job class that the coordinator resolves out
of the container and dispatches directly — see [Writing Steps](steps.md) for what such
a class looks like. An entry can also be a group of steps run at the same time — see
[Running Steps in Parallel](parallel.md).

<a name="starting-a-saga"></a>
## Starting a Saga

You start a saga through `Saga::workflow($workflow)`, which returns a `SagaWorkflow`
bound to that workflow, then calling `start()` on it with whatever context the first
step needs. Either an instance of the workflow or its class name will do — the class
name is convenient when you're starting a saga from somewhere that only knows the
class, such as a config-driven dispatch table:

```php
use App\Sagas\PlaceOrder;
use Henzeb\Saga\Facades\Saga;

$state = Saga::workflow(new PlaceOrder())->start($order);

$state = Saga::workflow(PlaceOrder::class)->start($order); // equivalent
```

The context you pass here — an Eloquent model, a DTO, a plain array, or a scalar — is
handed to the first step and threaded forward from there: whatever a step's `handle()`
method returns becomes the context the following step receives. See [Reading the
Context](steps.md#reading-the-context) for how a step reads it back out.

`start()` returns a `SagaState` describing what happened. Under normal, queued
operation this is simply the freshly created saga in its `Pending` state. See
[Inspecting Sagas](inspecting-sagas.md) for
everything you can read off a `SagaState`, and the next section for the case where the
whole outcome is already known by the time `start()` returns.

The same `SagaWorkflow` also carries `current()`, `label()`, `signal()`,
`compensate()`, `retry()`, and `retryCompensation()` — see [Inspecting
Sagas](inspecting-sagas.md), [Awaiting a Signal](steps.md#awaiting-a-signal), and
[Retrying a Saga](retrying-sagas.md).

<a name="starting-idempotently"></a>
## Starting Idempotently

Pass a second argument to `Saga::workflow()` to avoid starting the same saga twice —
an incoming webhook's event ID, say, so a redelivery doesn't replay the whole
workflow:

```php
$state = Saga::workflow(new PlaceOrder(), $event->id)->start($order);
```

The key is scoped to the workflow, so the same key used with a different workflow
starts a separate saga. Calling `start()` again with a key already used returns the
existing saga's current state instead of starting a new one — `$context` is ignored on
that repeat call, since the saga it refers to already has its own. Without a key, every
call starts a fresh saga, as before.

The sagaId this produces is derived from the workflow and the key — shaped exactly
like a regular `Str::ulid()` (26-character Crockford base32) — so it always comes out
the same for the same pair, instead of the fresh, random id a saga normally gets. Pass
an already-existing, real sagaId instead of a business key — one you already have from
`saga:list` or a previous `id()` call, say — and it's used as-is, with no hashing: any
value that's already a valid Ulid short-circuits the derivation.

`Saga::workflow($workflow, $key)` binds that second argument once, so `start()`
doesn't need it repeated — and every other call on that same `SagaWorkflow`
(`current()`, `signal()`, `compensate()`) targets that exact saga too:

```php
$workflow = Saga::workflow(new PlaceOrder(), $event->id);

$state = $workflow->start($event->order);

// ...later, anywhere else you'd rebuild the same binding...
$state = Saga::workflow(new PlaceOrder(), $event->id)->current();
```

`$workflow->id()` returns the sagaId `start()` is going to use — with a bound key,
that's the same deterministic id every other call on this `SagaWorkflow` resolves it
to as well (so you can look an already-started saga up via `$workflow->current()`
before deciding whether to `start()` at all); without one, it's a freshly generated
ulid.
Either way, it's resolved once and reused on every later call, including `start()`
itself:

```php
$workflow = Saga::workflow(new PlaceOrder());

$sagaId = $workflow->id();

// ...hand $sagaId back to the caller, log it, whatever needs it up front...

$workflow->start($order);
```

<a name="running-a-saga-synchronously"></a>
## Running a Saga Synchronously

Passing `sync: true` runs every step of the saga — and any compensation a failure
triggers — inline, within the current request or command, without a queue worker
needing to be running:

```php
use Henzeb\Saga\Enums\SagaStepStatus;

$state = Saga::workflow(new PlaceOrder())->start($order, sync: true);

if ($state->status === SagaStepStatus::Completed) {
    // Every step succeeded, and $state->context() holds ShipOrder's return value...
}
```

This is convenient for tests, Artisan commands, or anywhere a queue worker isn't
guaranteed to be running. It doesn't change how a step is written — the same job class
works whether it's dispatched to a real queue or run immediately.

> [!WARNING]
> A step still needs to implement `Illuminate\Contracts\Queue\ShouldQueue` for `sync`
> to have any effect on it. A step that was never queued to begin with already runs
> immediately, `sync` or not.

<a name="retrying-stale-steps"></a>
## Retrying Stale Steps

Sometimes a queue redelivers a job whose previous attempt never resolved — the worker
process died mid-execution, say — leaving a step recorded as still `Running` (or
`Compensating`) with nothing written after it. Because it's genuinely unclear whether
the step's side effect happened, the package treats a stale step as a failure by
default, rather than guessing.

You may change that for every step in a workflow by overriding `onStaleRunning()`:

```php
use Henzeb\Saga\Workflow;

class PlaceOrder extends Workflow
{
    public function steps(): array
    {
        // ...
    }

    public function onStaleRunning(): ?string
    {
        return 'retry';
    }
}
```

Return `'retry'` to have a redelivered, stale step simply run again, or `'fail'` to
keep the default behavior explicit. Returning `null` — the default — defers to the
`saga.on_stale_running` configuration value.

> [!NOTE]
> A single step may also opt in to retrying regardless of what its workflow says. See
> [Stale Steps](failure-handling.md#stale-steps) for the full precedence between a
> step, its workflow, and the global configuration.
