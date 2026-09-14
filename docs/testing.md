# Testing

- [`Saga::fake()`](#saga-fake)
- [Assertions](#assertions)
- [Letting a Step Actually Run](#letting-a-step-actually-run)

<a name="saga-fake"></a>
## `Saga::fake()`

Testing a workflow doesn't need a real database or cache store, and doesn't need its
step jobs to actually run — the same idea as `Bus::fake()`/`Queue::fake()`:

```php
use Henzeb\Saga\Facades\Saga;

Saga::fake();

$workflow = Saga::workflow(PlaceOrder::class);
$workflow->start($context, sync: true);
```

`Saga::fake()` swaps the saga driver for an ephemeral, in-memory one for the rest of
the test, and calls Laravel's own `Bus::fake()` under the hood — every step job is
intercepted rather than actually dispatched, exactly like `Bus::fake()` on its own
would do. `handle()`/`compensate()` never runs, so a saga started this way stays
`Pending` right after `start()` — the same job interception that skips your step's own
side effects (a payment call, an email) also skips the coordinator's own middleware
that would otherwise advance it.

<a name="assertions"></a>
## Assertions

`Saga::fake()` returns a `SagaFake` with a handful of state assertions, reading
straight from the fake driver:

```php
$fake = Saga::fake();

$workflow = Saga::workflow(PlaceOrder::class);
$workflow->start($context, sync: true);

$fake->assertStarted($workflow->id());
$fake->assertStatus($workflow->id(), SagaStepStatus::Pending);
$fake->assertCompleted($workflow->id());          // Completed
$fake->assertFailed($workflow->id());              // Failed
$fake->assertRolledBack($workflow->id());           // RolledBack
$fake->assertCompensationFailed($workflow->id());   // CompensationFailed
```

Since `Saga::fake()` calls the real `Bus::fake()`, ordinary `Bus` assertions work
right alongside these — `Bus::assertDispatched(ChargePayment::class)` still tells you
a step *would* have been dispatched, without it actually running.

<a name="letting-a-step-actually-run"></a>
## Letting a Step Actually Run

`SagaFake::except()` mirrors `BusFake::except()` — name the step (or compensator) job
classes that should run for real instead of being intercepted:

```php
$fake = Saga::fake()->except([ChargePayment::class]);

$workflow = Saga::workflow(PlaceOrder::class);
$workflow->start($context, sync: true);
```

An excepted job only actually runs when it's also dispatched synchronously —
`start(..., sync: true)`, same as outside a test — since a real, non-`sync` dispatch
still goes through the queue connection configured for it, which nothing is running
during a test.
