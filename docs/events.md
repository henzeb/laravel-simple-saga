# Events

- [Introduction](#introduction)
- [Available Events](#available-events)
- [A Complete Run, in Order](#a-complete-run-in-order)
- [A Note on Delivery](#a-note-on-delivery)

<a name="introduction"></a>
## Introduction

The package fires an event at every meaningful transition a saga goes through, so you
may hook into a saga's lifecycle — logging, notifications, metrics — without touching
the workflow or its steps. Listen for them exactly the way you would any other Laravel
event:

```php
use Henzeb\Saga\Events\SagaCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (SagaCompleted $event) {
    Log::info("Saga {$event->state->sagaId} completed.");
});
```

<a name="available-events"></a>
## Available Events

| Event | Fired When |
| --- | --- |
| `SagaStarted` | `start()` records the first step and dispatches it. |
| `SagaStepStarted` | A forward step is about to run `handle()`. Never fires for a compensator — see `SagaStepCompensating` below for that. |
| `SagaStepCompleted` | A forward step finishes successfully. |
| `SagaStepIterated` | A step's `hasNext()` returns `true` and it is dispatched again, instead of completing. See [Iterating a Step](steps.md#iterating-a-step). |
| `SagaStepAwaitingSignal` | A step calls `waitFor()` and is recorded as waiting instead of completing. See [Awaiting a Signal](steps.md#awaiting-a-signal). |
| `SagaStepFailed` | A forward step fails on its final attempt, before compensation begins. |
| `SagaCompensating` | A forward failure has something to compensate — the whole saga's compensation cascade is starting. Fires once per cascade, before the first `SagaStepCompensating`. |
| `SagaStepCompensating` | One step's compensator is about to run `compensate()` — the compensating counterpart to `SagaStepStarted`. Does not fire for a step with no compensator at all — that step is just skipped on the way back, with no event either side. |
| `SagaStepCompensated` | One step's compensator actually ran and finished. Same exception as above: a step with no compensator produces no event. |
| `SagaCompensationFailed` | A compensator itself fails on its final attempt. The saga halts here. |
| `SagaCompleted` | The last step in the workflow completes — the saga finished successfully. |
| `SagaCompensated` | Compensation has walked back past the first step — every completed step has now been compensated. Fires once per cascade, immediately before `SagaRolledBack`. |
| `SagaRolledBack` | Same moment as `SagaCompensated` above, carrying the same state — this one reflects the persisted `RolledBack` status specifically. |

Every event carries a `state` property — the same [`SagaState`](inspecting-sagas.md#the-saga-state)
`current()` returns, snapshotting the record that was just written: `sagaId`,
`workflow`, `status`, `step`, `context`, `reason`, `signal`, and `signalExpiresAt`.
`SagaStepFailed` and `SagaCompensationFailed` carry the actual `?Throwable` too,
alongside `state` — `state->reason` only has the string form of it.

`SagaStepStarted` and `SagaStepCompensating` both fire on every attempt a queued step
or compensator makes, not only the first — the same job redelivered after a failed
attempt marks `Running` (or `Compensating`) again each time, and the matching event
follows right along with it.

<a name="a-complete-run-in-order"></a>
## A Complete Run, in Order

For a two-step workflow where both steps succeed:

```
SagaStarted
SagaStepStarted     (step 0)
SagaStepCompleted   (step 0)
SagaStepStarted     (step 1)
SagaStepCompleted   (step 1)
SagaCompleted
```

For the same workflow where step 1 fails and step 0 can be compensated:

```
SagaStarted
SagaStepStarted           (step 0)
SagaStepCompleted         (step 0)
SagaStepStarted           (step 1)
SagaStepFailed            (step 1)
SagaCompensating
SagaStepCompensating      (step 0)
SagaStepCompensated       (step 0)
SagaCompensated
SagaRolledBack
```

If step 0 had no compensator at all, `SagaStepCompensating` and `SagaStepCompensated`
simply don't fire for it — the cascade goes straight from `SagaCompensating` to
`SagaCompensated`/`SagaRolledBack`.

<a name="a-note-on-delivery"></a>
## A Note on Delivery

These events are dispatched at the same point the corresponding record is written to
the driver — inline with the queue job's own execution, not through a separate
mechanism. A step's own failure handling already only reports on the final attempt
(see [Failure Handling](failure-handling.md)), so a retried step doesn't fire
`SagaStepFailed` for every attempt, only the last one.

> [!WARNING]
> As with the rest of the saga's bookkeeping, a crash between writing the record and
> the process finishing could in principle mean an event fires more than once on
> redelivery — treat a listener the same way you'd treat any other queue-adjacent
> listener: safe to run more than once for the same saga and step.
