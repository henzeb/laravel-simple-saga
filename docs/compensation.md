# Compensation

- [Introduction](#introduction)
- [Self-Compensating Steps](#self-compensating-steps)
- [External Compensators](#external-compensators)
- [Steps Without Compensation](#steps-without-compensation)
- [Workflow Validation](#validation)

<a name="introduction"></a>
## Introduction

When a step fails permanently, the saga doesn't simply stop — it rolls back every step
that already completed, in reverse order, by running each one's compensation. What
"undo" means is entirely up to you; the package only guarantees that compensation
runs, not what it does.

A step opts in to being compensated by implementing the `ShouldCompensate` marker
interface, in one of two ways: compensating itself, or naming another job to
compensate it.

<a name="self-compensating-steps"></a>
## Self-Compensating Steps

The simplest approach is a single class that handles both directions. Implement
`ShouldCompensate` and add a `compensate()` method alongside `handle()`:

```php
namespace App\Sagas\Steps;

use App\Facades\PaymentGateway;
use App\Models\Order;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ChargePayment implements ShouldQueue, ShouldCompensate
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithSaga;

    public function handle(): Order
    {
        $order = $this->context()->object(Order::class);

        $order->payment_reference = PaymentGateway::charge($order->customer, $order->total_in_cents);
        $order->save();

        return $order;
    }

    public function compensate(): Order
    {
        $order = $this->context()->object(Order::class);

        PaymentGateway::refund($order->payment_reference);

        return $order;
    }
}
```

`compensate()` is called the same way `handle()` is — through the container, so it may
ask for services by adding them as typed parameters — and it reads context back
through `$this->context()` exactly as `handle()` does. Where `handle()` sees the
context it was *given* (whatever the previous step returned), `compensate()` sees the
context this step's own `handle()` *returned*.

> [!NOTE]
> Because it's the same class dispatched for either direction, `$tries`, `$backoff`,
> and any queue configuration are shared between the forward and compensating
> attempt. If that matters for a particular step, reach for an external compensator
> instead.

<a name="external-compensators"></a>
## External Compensators

If you'd rather keep the forward and rollback logic in separate classes — each with
its own queue configuration — name a compensator for the forward step using the
`#[CompensatedBy]` attribute:

```php
namespace App\Sagas\Steps;

use App\Facades\PaymentGateway;
use App\Models\Order;
use Henzeb\Saga\Attributes\CompensatedBy;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

#[CompensatedBy(RefundPayment::class)]
class ChargePayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithSaga;

    public $tries = 3;

    public function handle(): Order
    {
        $order = $this->context()->object(Order::class);

        $order->payment_reference = PaymentGateway::charge($order->customer, $order->total_in_cents);
        $order->save();

        return $order;
    }
}
```

```php
namespace App\Sagas\Steps;

use App\Facades\PaymentGateway;
use App\Models\Order;
use Henzeb\Saga\Concerns\InteractsWithSaga;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefundPayment implements ShouldQueue, ShouldCompensate
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithSaga;

    public $tries = 5; // refunds are worth retrying harder than the original charge

    public function handle(): Order
    {
        $order = $this->context()->object(Order::class);

        PaymentGateway::refund($order->payment_reference);

        return $order;
    }
}
```

`RefundPayment` only ever runs while `ChargePayment`'s step is compensating, so it
needs no `compensate()` method of its own — its `handle()` alone is the compensation.
It still needs to implement `ShouldCompensate` itself.

> [!WARNING]
> A step may use either approach, but never both. Implementing `ShouldCompensate`
> directly *and* carrying `#[CompensatedBy]` on the same class is rejected when the
> workflow is [validated](#validation), rather than silently picking one.

<a name="steps-without-compensation"></a>
## Steps Without Compensation

A step that implements neither `ShouldCompensate` nor `#[CompensatedBy]` is simply
treated as a no-op during rollback — nothing runs for it, and the saga moves on to
compensate the step before it. This is the right choice for a step that has nothing to
undo, such as one that only reads data — logging that an order was placed, say, rather
than reserving or charging anything.

<a name="validation"></a>
## Workflow Validation

Workflows are validated the first time a saga built from them starts, before anything
is dispatched, so a broken workflow fails immediately and loudly rather than partway
through execution. The check runs once per workflow class per process, and confirms
that:

- every step uses `InteractsWithSaga`;
- no step both implements `ShouldCompensate` and carries `#[CompensatedBy]`; and
- a `#[CompensatedBy]` attribute's target actually implements `ShouldCompensate`.

A violation raises an `InvalidWorkflowException` listing every problem found, so you
can fix them all at once rather than one failed saga at a time.
