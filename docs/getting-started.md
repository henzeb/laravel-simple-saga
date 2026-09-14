# Getting Started

- [Introduction](#introduction)
- [Installation](#installation)
- [Your First Saga](#your-first-saga)
- [Next Steps](#next-steps)

<a name="introduction"></a>
## Introduction

Some processes can't be wrapped in a single database transaction because they reach
outside your own database — charging a customer through a payment gateway, reserving
stock in a third-party warehouse system, booking a courier for shipping. If the third
step of such a process fails, you're left holding a payment that was taken but an order
that was never shipped, and your database transaction can't undo either one for you.

The saga pattern solves this by breaking the process into a sequence of steps, each
with a defined way to undo what it did. If a step fails, every step that already
succeeded is rolled back, in reverse order, by running its compensation. Laravel Simple
Saga gives you a small, queue-native way to express that sequence using nothing but
Laravel jobs you already know how to write.

There's no separate worker, no bespoke DSL, and no interface a step is forced to
implement. A step is dispatched exactly the way any other job is; the package's own
queue middleware watches what happens and decides what runs next.

<a name="installation"></a>
## Installation

You may install Laravel Simple Saga using the Composer package manager:

```bash
composer require henzeb/laravel-simple-saga
```

The package's service provider is registered automatically. If you intend to use the
`database` driver — the default — publish and run its migration:

```bash
php artisan vendor:publish --tag=saga-migrations

php artisan migrate
```

If you would like to change the default driver, or any driver's configuration, publish
the configuration file as well:

```bash
php artisan vendor:publish --tag=laravel-simple-saga
```

> [!NOTE]
> If you would rather avoid a migration altogether, the package also ships a `cache`
> driver that stores a saga's history in any Laravel cache store you already have
> configured. See the [Drivers](drivers.md) documentation for a full comparison.

<a name="your-first-saga"></a>
## Your First Saga

Imagine your application takes an `Order` through three stages once a customer checks
out: stock is reserved in your warehouse system, the customer's card is charged, and a
courier is booked to ship the order. If the charge fails after stock has already been
reserved, that reservation needs to be released again — this is exactly the kind of
process a saga is meant to coordinate.

### Defining the Workflow

A saga is described by a `Workflow`, which lists the step classes that should run, in
order:

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

### Writing a Step

Each step is a job, just like the ones you'd generate with `make:job` — though a step
doesn't strictly have to be a queued job; see [Queued or Synchronous, Your Choice](steps.md#queued-or-synchronous).
Add the `InteractsWithSaga` concern to it, and the coordinator will use it to hand the
job its context and record what happens once it runs:

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

> [!TIP]
> Adding the concern to a job you would otherwise write anyway is all a step needs to
> become saga-aware — there's no marker interface and no method to rename. See [Writing
> Steps](steps.md) for everything the concern gives you, including a `SagaStep` base
> class if you'd rather not list the queue traits yourself each time.

### Starting the Saga

With the workflow and its steps in place, start a saga through the `Saga` facade,
passing along whatever context the first step needs:

```php
use App\Sagas\PlaceOrder;
use Henzeb\Saga\Facades\Saga;

$state = Saga::workflow(new PlaceOrder())->start($order);
```

`ReserveStock` runs first and receives `$order` as its context. Whatever it returns
becomes the context `ChargePayment` receives, and so on down the line. If
`ChargePayment` throws on its final attempt, the saga stops moving forward and instead
compensates `ReserveStock` — releasing the stock it reserved — so your application
never ends up with a charge and no shipment, or stock held against an order that was
never paid for. See [Compensation](compensation.md) for how a step describes what
"undo" means.

<a name="next-steps"></a>
## Next Steps

You now have enough to build a saga of your own. From here:

- [Defining Workflows](workflows.md) covers starting a saga synchronously, retrying
  stale steps, and everything else a `Workflow` controls.
- [Writing Steps](steps.md) covers reading and returning context, and iterating a step
  that needs more than one attempt to finish.
- [Compensation](compensation.md) covers rolling a failed saga back.
- [Inspecting Sagas](inspecting-sagas.md), [Failure Handling](failure-handling.md),
  [Events](events.md), [Encrypting Context](encryption.md), [Drivers](drivers.md), and
  [Console Commands](console-commands.md) round out the rest of the package.
