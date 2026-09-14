# Writing Steps

- [Introduction](#introduction)
- [The `InteractsWithSaga` Concern](#interacts-with-saga)
- [Queued or Synchronous, Your Choice](#queued-or-synchronous)
- [Reading the Context](#reading-the-context)
- [Returning the Next Context](#returning-the-next-context)
- [Iterating a Step](#iterating-a-step)
- [Awaiting a Signal](#awaiting-a-signal)
- [What a Step Should Not Do](#what-a-step-should-not-do)

<a name="introduction"></a>
## Introduction

A saga step is any class using the `InteractsWithSaga` concern — a plain class, an
action, or an ordinary Laravel job if you want it queued. There's no interface to
implement and no method to rename — you write `handle()` the same way you would for a
job, and the package takes care of resolving the step, capturing its return value, and
deciding what runs next.

<a name="interacts-with-saga"></a>
## The `InteractsWithSaga` Concern

A step needs the `InteractsWithSaga` concern so the coordinator has somewhere to put the
saga's bookkeeping — which saga this is, which step, and the context to work with.
That's the only requirement — a step is any class using this concern, resolved out of
the container and run in the same request, nothing job-specific about it:

```php
namespace App\Sagas\Steps;

use App\Facades\PaymentGateway;
use App\Models\Order;
use Henzeb\Saga\Concerns\InteractsWithSaga;

class ChargePayment
{
    use InteractsWithSaga;

    public function handle(): Order
    {
        $order = $this->context()->object(Order::class);

        PaymentGateway::charge($order->customer, $order->total_in_cents);

        return $order;
    }
}
```

The coordinator resolves the step through the container (`app($stepClass)`), so
constructor injection works exactly as it would for a controller or listener, and the
step runs synchronously as part of dispatching the saga forward — no worker involved.

<a name="queued-or-synchronous"></a>
## Queued or Synchronous, Your Choice

Implement `ShouldQueue` and add the usual queue traits to have a step run on the queue
instead, with whatever `$tries`, `$backoff`, `$queue`, or `middleware()` you give it
respected exactly as they would be outside a saga — the package never overrides these,
it only adds its own middleware alongside yours:

```php
namespace App\Sagas\Steps;

use App\Facades\PaymentGateway;
use App\Models\Order;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ChargePayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithSaga;

    public $tries = 3;
    public $backoff = 10;

    public function handle(): Order
    {
        $order = $this->context()->object(Order::class);

        PaymentGateway::charge($order->customer, $order->total_in_cents);

        return $order;
    }
}
```

This is the same set of traits `make:job` gives you today, with the `InteractsWithSaga`
concern added to the list — a job you already have can become a saga step without
changing anything else about it.

> [!TIP]
> If you'd rather not list `Dispatchable`, `InteractsWithQueue`, `Queueable`, and
> `SerializesModels` yourself every time, the package ships an optional `SagaStep`
> base class that already combines them with `InteractsWithSaga`. Extending it is
> never required — it exists purely so an IDE and static analysis recognize
> `context()` and the saga properties without you redeclaring them, and the
> coordinator treats a step built either way identically.

<a name="reading-the-context"></a>
## Reading the Context

Every step receives the value the previous step returned — or, for the first step,
the value passed to `start()`. That value could be an array, a scalar, or a whole
object such as an Eloquent model, so rather than exposing it as a raw property, the
concern wraps it in a `SagaContext` behind a single `context()` method:

```php
public function handle(): Order
{
    $order = $this->context()->object(Order::class);

    PaymentGateway::charge($order->customer, $order->total_in_cents);

    return $order;
}
```

`SagaContext` doesn't treat the context as a bag of fields to pull a key out of — a
saga's context is whatever single value the previous step returned, not necessarily a
keyed array — so each accessor asserts the whole value is that shape and hands it
back, the same way `object()` already asserts an instance and returns it typed:

```php
$this->context()->object(Order::class);       // context is a whole object — assert and return it typed
$this->context()->array();                    // context is an array — assert and return it as one
$this->context()->collect();                  // context is an array — assert and wrap it as a Collection
$this->context()->string();                   // context is a string — assert and wrap it as a Stringable
$this->context()->integer();                  // context is an int — assert and return it
$this->context()->float();                    // context is a float — assert and return it
$this->context()->boolean();                  // context is a bool — assert and return it
$this->context()->enum(FulfillmentStatus::class); // context is that enum, or its backing value — assert and return it typed
$this->context()->raw();                      // the value exactly as the previous step returned it, untouched, no assertion
```

Every one of these, `raw()` aside, returns `null` if the context itself is `null` — a
step that legitimately has no context to work with sees `null`, not a failed
assertion. Calling the accessor for the wrong non-null shape (`string()` on an array
context, say) throws `UnexpectedContextTypeException`. If a previous step returned an array and
yours only needs one field out of it, read it from `array()` like any other array:

```php
$warehouseId = $this->context()->array()['warehouse_id'];
```

> [!NOTE]
> If the previous step returned an Eloquent model, the context is stored as a model
> identifier rather than a serialized object, and re-fetched fresh from the database
> the first time you call one of the accessors above — the same restoration Laravel
> already performs for queued jobs via `SerializesModels`. A step is never handed a
> stale snapshot, even one that sat on the queue for hours before running.

<a name="returning-the-next-context"></a>
## Returning the Next Context

Whatever `handle()` returns becomes the context for the following step. Return the
value the next step actually needs — often the same object, sometimes something
smaller or entirely different:

```php
namespace App\Sagas\Steps;

use App\Models\Order;
use App\Services\Warehouse;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReserveStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithSaga;

    public function handle(Warehouse $warehouse): Order
    {
        $order = $this->context()->object(Order::class);

        $order->reservation_id = $warehouse->reserve($order->lines);
        $order->save();

        return $order; // ChargePayment, next in the workflow, receives this same $order
    }
}
```

The last step's return value becomes the saga's final context, retrievable later
through `current()->context()` — see [Inspecting Sagas](inspecting-sagas.md).

<a name="iterating-a-step"></a>
## Iterating a Step

Some steps aren't done in one shot — polling a payment gateway for a status change, or
paging through a supplier's API. Add the `IterableSagaStep` concern and implement
`hasNext()` to have the step run again instead of completing:

```php
namespace App\Sagas\Steps;

use App\Facades\PaymentGateway;
use App\Models\Order;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Concerns\IterableSagaStep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AwaitBankTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithSaga, IterableSagaStep;

    protected bool $settled = false;

    public function handle(): mixed
    {
        $order = $this->context()->object(Order::class);

        $this->settled = PaymentGateway::transferSettled($order->payment_reference);

        return $this->settled ? $order : null;
    }

    protected function hasNext(): bool
    {
        return ! $this->settled;
    }
}
```

After `handle()` (or `compensate()`) runs, the coordinator asks `hasNext()` whether
there's another attempt to make. As long as it returns `true`, the step is dispatched
again at the same index instead of completing — `handle()`'s return value is ignored
in that case. Once it returns `false`, the step completes normally with whatever
`handle()` last returned.

`next()` runs right before the step is redispatched, and does nothing by default, so
the context stays exactly what it already was between attempts — what you want for
plain polling. Override it to change what the next attempt sees, such as a page
cursor when you're paging through an API:

```php
namespace App\Sagas\Steps;

use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Concerns\IterableSagaStep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SyncSupplierCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithSaga, IterableSagaStep;

    protected bool $hasMorePages = false;

    public function handle(): mixed
    {
        $page = $this->context()->array()['page'];

        $response = Http::get('https://supplier.example/api/catalog', ['page' => $page]);

        $this->hasMorePages = $response->json('has_more');

        SupplierProduct::upsertFromApi($response->json('data'));

        return $this->context()->array();
    }

    protected function hasNext(): bool
    {
        return $this->hasMorePages;
    }

    protected function next(): void
    {
        $this->context = ['page' => $this->context()->array()['page'] + 1];
    }
}
```

If the context is an object you're free to mutate and persist yourself — an Eloquent
model, say, as in the `AwaitBankTransfer` example above — the default `next()` already
picks up whatever you did to it: it resolves to the same identifier, so the next
attempt re-fetches it fresh from the database, seeing any changes you saved.

An iterated step is dispatched exactly like a normal one — the same `$tries` and
`$backoff` apply to the fresh attempt, and a queued step goes back through the queue
rather than looping in place.

> [!WARNING]
> There is no built-in cap on how many times a step may iterate. If a step needs to
> give up after some number of attempts, track that yourself — typically against
> something outside the saga's own context, such as a cache key or a column on the
> model you're polling.

Iterating doesn't advance the saga or change its status beyond marking the step
`Pending` (or `CompensationPending`, while compensating) again for the next attempt;
it fires a [`SagaStepIterated`](events.md) event rather than `SagaStepCompleted`.

<a name="awaiting-a-signal"></a>
## Awaiting a Signal

Some steps can't finish on their own — they need a decision or a payload from outside
the saga entirely: a manager approving a request, a webhook confirming a bank
transfer settled. `$this->waitFor($name)` is that wait: it returns the context coming
from the signal — wrapped in a `SagaContext`, the same as `context()` — once `$name`
has been delivered, and otherwise throws right there, ending the method and parking
the step. A step whose whole job is waiting is just:

```php
namespace App\Sagas\Steps;

use Henzeb\Saga\SagaStep;

class AwaitApproval extends SagaStep
{
    public function handle(): bool
    {
        $approved = $this->waitFor('approval')->boolean(); // once delivered

        return $approved;
    }
}
```

Anything that needs to happen *before* the wait — notifying the approver, say — is a
separate, earlier step in the workflow, exactly like any other step:

```php
$steps = [
    NotifyApprover::class,
    AwaitApproval::class,
    // ...
];
```

`NotifyApprover` runs and completes once, handing its result forward as `AwaitApproval`'s
context, the normal way a saga passes context between steps — no branching needed in
either step to tell a first run from a resumed one.

The first time `AwaitApproval` runs, `waitFor()` throws `AwaitingSignalException` —
caught by the middleware, which records the step as waiting instead of completed.
Nothing is dispatched again until the signal arrives, so the step spends no further
queue attempts sitting idle.

Deliver the signal once you have the outcome. With the workflow bound, use
`SagaWorkflow::signal()`:

```php
use App\Sagas\PlaceOrder;
use Henzeb\Saga\Facades\Saga;

Saga::workflow(new PlaceOrder(), $sagaId)->signal('approval', true);
```

Without a workflow in hand — a webhook controller that only carries the raw
`sagaId`, say — go through the coordinator directly instead; the saga's own trail
already knows which workflow to resolve:

```php
Saga::coordinator()->signal($sagaId, 'approval', true);
```

That payload is what `waitFor()` returns, wrapped in its own `SagaContext`. Passing
one is optional: signaling with just the name on its own delivers a `SagaContext`
wrapping `null`.

The step is dispatched again from the top once signaled; this time `waitFor('approval')`
returns the `SagaContext` wrapping the delivered payload instead of throwing, so
`handle()` runs straight through to completion.

`signal()` throws `SagaNotAwaitingSignalException` if the saga isn't currently waiting
on a signal by that exact name — including a saga that already moved on, or one that
never called `waitFor()` at all. A step that awaits a signal can also be a
compensator: the same rules apply, just against `CompensationWaiting` instead of
`Waiting`.

> [!WARNING]
> `AwaitingSignalException` is a control-flow exception, the same idea as
> `IterableSagaStep`'s redispatch — it's meant to unwind straight out of
> `handle()`/`compensate()`, not to be caught inside a step. If you do catch
> `Throwable` broadly there, rethrow it, the same as you would any exception you
> don't recognize.

A wait left unanswered stays `Waiting` forever unless you give it a deadline.
Override `signalTimeout()` on the workflow to cap how long any of its steps may wait,
in seconds:

```php
use Henzeb\Saga\Workflow;

class PlaceOrder extends Workflow
{
    public function signalTimeout(): ?int
    {
        return 86400; // one day
    }
}
```

`config('saga.signal_timeout')` sets the default for workflows that don't override
it; both default to `null`, meaning no deadline at all. A deadline doesn't enforce
itself — something has to sweep for it. Schedule the bundled command to do that:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('saga:sweep-signals')->everyMinute();
```

Each pass fails every `Waiting`/`CompensationWaiting` step whose deadline has passed
with a `SignalTimeoutException`, the same way any other failure would — cascading
compensation for a forward step, or halting at `CompensationFailed` for a
compensator. Call it directly instead of scheduling it if you'd rather drive the
sweep yourself:

```php
Saga::sweepSignals();                    // sweeps now, returns how many timed out
Saga::sweepSignals(now()->subHour());    // sweeps against an earlier cutoff
```

A step stuck `Running`/`Compensating` — a worker that crashed without the queue ever
redelivering the job — has the same problem and the same fix. Override
`runningTimeout()` on the workflow:

```php
use Henzeb\Saga\Workflow;

class PlaceOrder extends Workflow
{
    public function runningTimeout(): ?int
    {
        return 3600; // one hour
    }
}
```

`config('saga.running_timeout')` sets the default for workflows that don't override
it; both default to `null`, meaning no deadline. Schedule `saga:sweep-stale`
alongside `saga:sweep-signals` to enforce it:

```php
Schedule::command('saga:sweep-stale')->everyMinute();
```

Each pass fails every `Running`/`Compensating` step whose deadline has passed with a
`StaleSagaStepException`, cascading exactly like `saga:sweep-signals` does. Call it
directly the same way, too:

```php
Saga::sweepStale();                    // sweeps now, returns how many timed out
Saga::sweepStale(now()->subHour());    // sweeps against an earlier cutoff
```

<a name="what-a-step-should-not-do"></a>
## What a Step Should Not Do

A step shouldn't accept context through its constructor, and shouldn't store anything
it needs across attempts as a constructor property. The coordinator sets `sagaId`,
`workflow`, `sagaStepIndex`, and `context` directly on the instance right before
dispatch — every dispatch it makes itself (the next step, an iterated attempt, a step
resumed after a signal) builds a completely fresh instance this way, so any other
constructor-stored state from an earlier instance is gone. A queue's own redelivery of
an attempt reuses that same instance instead — including a retry that isn't yet final,
and a stale step retried under [`RetryWhenStale`](failure-handling.md#stale-steps) —
see [Idempotency](failure-handling.md#idempotency).
