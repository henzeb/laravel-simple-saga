# Inspecting Sagas

- [Introduction](#introduction)
- [The `SagaState`](#the-saga-state)
- [Walking the Trail](#walking-the-trail)
- [Saga Statuses](#saga-statuses)

<a name="introduction"></a>
## Introduction

`start()` returns a saga's state right away, but you'll often want to look a saga up
again later — from a controller checking on an order, a scheduled job auditing stuck
sagas, or a support ticket that starts with "my order never shipped." The same
`SagaWorkflow` you started it from does that, via `current()`:

```php
use App\Sagas\PlaceOrder;
use Henzeb\Saga\Facades\Saga;

$workflow = Saga::workflow(new PlaceOrder(), $event->id);

$state = $workflow->current();
```

If no saga exists for that binding, a `SagaNotFoundException` is thrown.

<a name="the-saga-state"></a>
## The `SagaState`

`SagaState` is a read-only snapshot reduced from everything recorded for the saga so
far:

```php
$state->sagaId;          // string
$state->workflow;        // ?string, the Workflow class this saga was started from
$state->status;          // SagaStepStatus enum: Pending, Running, Completed, Failed, ...
$state->step;            // int, the index of the step this status refers to
$state->reason;          // ?string, populated for Failed / CompensationFailed
$state->signal;          // ?string, the signal name being awaited, for Waiting / CompensationWaiting
$state->signalExpiresAt;  // ?DateTimeImmutable, when saga:sweep-signals will fail this wait
$state->recordedAt;       // ?DateTimeImmutable, when this status was recorded
$state->runningExpiresAt; // ?DateTimeImmutable, when saga:sweep-stale will fail a stuck Running/Compensating step
$state->context();       // SagaContext, the payload recorded alongside that status — see below
$state->trail();         // SagaTrail, the full history — see below
$state->label();         // ?string, a human-readable name for this step — see below
```

> [!NOTE]
> `workflow` is an optional field on `DTO\SagaStepRecord` — a
> [`Driver`](drivers.md#writing-a-custom-driver) implementation is free to leave it
> unset, in which case it simply reads back as `null`. [Retrying a
> Saga](retrying-sagas.md) is the main place this matters: it's what lets
> `saga:retry {sagaId}` work without you naming the workflow on the command line.

Because the underlying storage is append-only and a saga only ever does one thing at a
time, the most recent record *is* the current state — so `status`, `step`, and
`reason` are cheap to read, even for a saga with a long history. `context()` and
`trail()` are the two fields that cost a driver read — each is deferred until you
actually call it, and memoized after that first call.

`context()` is wrapped the same way a step's own `context()` is (see [Reading the
Context](steps.md#reading-the-context)), so reading it back out uses the same
accessors:

```php
$state->context()->object(Order::class);  // context is a whole object
$state->context()->array()['orderId'];    // context is an array — read a field out of it
```

`label()` names the step this state is for — the step class's own basename by
default (`'ChargePayment'`), or whatever a `#[StepLabel]` attribute on that step
gives back once it carries one (`'Charge Payment'`). It's `null` when the step
can't be resolved at all — no workflow recorded, or a workflow class that no
longer exists. While the state is a compensating one, this names the
**compensator** instead — the class named in `#[CompensatedBy]`, or the step's own
class again when it self-compensates — with that compensator's own `#[StepLabel]`
if it has one. See [The `saga:show` Command](console-commands.md#the-saga-show-command)
for everything `#[StepLabel]` accepts, including labels computed from the step's
own context. `Saga::workflow($workflow, $sagaId)->label()` reaches the current
label directly, without fetching the `SagaState` yourself first.

`saga:show` is what adds the `'Step 2 — '` numbering in front of this — `label()`
itself never does, so it's safe to use anywhere you don't want that prefix.

<a name="walking-the-trail"></a>
## Walking the Trail

`trail()` gives you every state the saga has passed through, oldest first — useful for
rendering a full audit log, or for diagnosing exactly where and why something failed:

```php
foreach ($state->trail() as $entry) {
    // $entry is itself a SagaState: $entry->step, $entry->status, $entry->reason, $entry->recordedAt
}

$state->trail()->count();
```

Each entry is a full `SagaState` in its own right, including a `context()` and a
`trail()` of its own — but *that* trail only goes back to what preceded *that* entry,
never forward into states that hadn't happened yet at that point. `$state->trail()`
itself is the exception: its trail is the complete history.

> [!NOTE]
> Fetching a trail is a separate, heavier read than the fields above, so it only
> happens the first time you actually call `trail()` — asking for `$state->status`
> alone never pays that cost, and neither does building a trail entry until you call
> `trail()` on that specific entry.

`SagaTrail` is a read-only `Illuminate\Support\Collection` — `map()`, `filter()`,
`first()`, and the rest all work as usual, and return another `SagaTrail` where a
`Collection` normally would.

<a name="saga-statuses"></a>
## Saga Statuses

| Status | Meaning |
| --- | --- |
| `Pending` | Recorded for a step about to run, before it's picked up. |
| `Running` | A step is currently executing. |
| `Waiting` | A step called `waitFor()` and is parked until that signal is delivered. |
| `Completed` | A step finished successfully. |
| `Failed` | A step failed on its final attempt; compensation has begun. |
| `CompensationPending` | Recorded for a compensator about to run, before it's picked up. |
| `Compensating` | A compensator is currently executing. |
| `CompensationWaiting` | A compensator called `waitFor()` and is parked until that signal is delivered. |
| `Compensated` | A step's compensation finished (or the step had none to run). |
| `CompensationFailed` | A compensator itself failed — the saga halts here and needs attention. |
| `RolledBack` | Compensation walked all the way back past step 0 — everything that completed has been undone. |

`CompensationFailed` and `RolledBack` are both terminal — nothing further happens to
the saga automatically. See [Retrying a Saga](retrying-sagas.md) for restarting
either of them without losing the original `sagaId`.
