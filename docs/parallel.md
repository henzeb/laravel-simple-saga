# Running Steps in Parallel

- [Introduction](#introduction)
- [Reading the Merged Context](#reading-the-merged-context)
- [Compensating a Group](#compensating-a-group)
- [Failure Policy](#failure-policy)
- [Running Branches on the Queue](#running-branches-on-the-queue)
- [Validation](#validation)

<a name="introduction"></a>
## Introduction

Most stages of a workflow are a single step. When a stage is really several
independent jobs that can run at the same time, group them with `$this->parallel()`
instead of listing them as separate steps:

```php
namespace App\Sagas;

use App\Sagas\Steps\ChargePayment;
use App\Sagas\Steps\NotifyWarehouse;
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
            $this->parallel(NotifyWarehouse::class, ShipOrder::class),
        ];
    }
}
```

Every branch of a `parallel()` group is an ordinary step class — the same
`InteractsWithSaga` concern, the same `handle()`/`context()` you'd write for any other
step (see [Writing Steps](steps.md)). The saga dispatches every branch at once and
waits for all of them before moving on to whatever comes after the group.

<a name="reading-the-merged-context"></a>
## Reading the Merged Context

The step after a `parallel()` group doesn't see one branch's return value — it sees an
array of all of them, keyed by the branch's position in the `parallel()` call:

```php
public function handle(): mixed
{
    $results = $this->context()->array();

    $warehouseReceipt = $results[0]; // NotifyWarehouse::handle()'s return value
    $shipment = $results[1];         // ShipOrder::handle()'s return value

    // ...
}
```

<a name="compensating-a-group"></a>
## Compensating a Group

If a later step fails and the saga rolls back into an already-completed `parallel()`
group, every branch that implements `ShouldCompensate` or carries `#[CompensatedBy]`
is compensated — one dispatch per branch, exactly like compensating any other step
(see [Compensation](compensation.md)). A branch with neither is treated as a no-op,
same as it would be outside a group.

<a name="failure-policy"></a>
## Failure Policy

When one branch of a group fails on its final attempt while the others are still
running, the default behavior is to wait: nothing is compensated until every branch
has finished, one way or another. Once they have, the group either advances (if every
branch completed) or compensates every branch that did complete and rolls back from
there.

Call `->failFast()` on the group to skip that wait — compensation of already-completed
branches starts the moment one branch fails, without waiting for the rest:

```php
$this->parallel(NotifyWarehouse::class, ShipOrder::class)->failFast();
```

A branch still in flight when the group starts failing isn't abandoned — once it
finishes, it's compensated in place of being treated as a completion.

<a name="running-branches-on-the-queue"></a>
## Running Branches on the Queue

A saga run with `sync: true` normally runs every step inline, one at a time — including
the branches of a `parallel()` group, which lose their concurrency there since nothing
is actually running at the same time. Call `->queued()` on a group to dispatch its
branches onto the real queue regardless:

```php
$this->parallel(NotifyWarehouse::class, ShipOrder::class)->queued();
```

> [!NOTE]
> `->queued()` only has an effect on a branch that implements `ShouldQueue` — a branch
> that doesn't always runs inline, the same as it would anywhere else.

> [!WARNING]
> Once a `queued()` group's branches are dispatched, the call that triggered them
> returns without waiting for the group to finish — only for the branches to be handed
> to the queue. The rest of the saga (whether it advances or compensates) resumes once
> a queue worker actually runs them.

<a name="validation"></a>
## Validation

Each branch of a `parallel()` group is validated exactly like a plain step (see
[Workflow Validation](compensation.md#validation)) — it must use `InteractsWithSaga`,
and the same `ShouldCompensate`/`#[CompensatedBy]` rules apply per branch.
