# Failure Handling

- [How a Failure Is Detected](#how-a-failure-is-detected)
- [Idempotency](#idempotency)
- [Stale Steps](#stale-steps)
- [Interrupting a Sync Run](#interrupting-a-sync-run)
- [Compensating Manually](#compensating-manually)

<a name="how-a-failure-is-detected"></a>
## How a Failure Is Detected

A step's failure is detected around the same call that runs `handle()` (or
`compensate()`), regardless of whether the step is queued. For a queued step with
retries configured, the saga is only told about a failure on the *final* attempt —
reporting it on attempt one of three would trigger compensation before the retries you
configured ever got a chance to run. Every earlier attempt still fails and retries
exactly as Laravel's queue would normally handle it; nothing about your `$tries` or
`$backoff` behaves differently inside a saga.

Once a step's final attempt fails, it's recorded as `Failed`, and compensation begins
for every step before it, most recent first, following whichever compensation
approach each step chose — see [Compensation](compensation.md). If a compensator
itself fails on its final attempt, that step is recorded `CompensationFailed` instead,
and the saga halts: no further steps are compensated automatically.

<a name="idempotency"></a>
## Idempotency

Two different situations can cause a step's job to run more than once, and the
package handles each differently.

**Duplicate concurrent dispatch** is prevented outright. Before dispatching any step
— forward or compensating — the coordinator acquires a lock scoped to that exact step
and direction, releasing it again the moment that one dispatch call finishes — it
guards the dispatch itself, not however long the step then takes to run. If something
else already holds it, the duplicate dispatch is dropped silently rather than run
twice.

**Redelivery after a crash** — a step finished, was recorded `Completed`, but the
worker died before acknowledging the queue message, so the same job gets redelivered
— is handled by checking the trail before doing any real work. If the step is already
`Completed`, `handle()` is never called again; the coordinator simply makes sure
whatever should have been dispatched next actually was, and returns.

> [!NOTE]
> This makes it safe to redeliver a step's job without worrying about, say, charging a
> customer twice — provided your own external calls (a payment gateway, say) are
> themselves guarded by an idempotency key. The package can guarantee "`handle()`
> won't run twice for a step already marked `Completed`"; it can't protect against a
> crash *during* `handle()`, after the real side effect happened but before that fact
> was recorded. Closing that gap is on the action itself.

That gap usually means: don't generate a fresh random idempotency key on every attempt
(`Str::uuid()`, say) — a retry after a timeout would then look like a brand new request
to whatever you're calling. `InteractsWithSaga` gives you a stable one instead,
derived from `sagaId`/`sagaStepIndex`/`branch`, so it's identical across every attempt
of the same step:

```php
$reference = $this->idempotencyKey();          // 'saga:01H...:0:0'
$reference = $this->idempotencyKey('charge');  // 'saga:01H...:0:0:charge', for a step
                                                // that needs more than one such key
PaymentGateway::charge($reference, $amount);
```

<a name="stale-steps"></a>
## Stale Steps

A trickier case is a redelivered job that finds its own step still marked `Running`
(or, during rollback, `Compensating`) with nothing written after it. Unlike the
completed case above, it's genuinely ambiguous here whether the previous attempt's
side effect happened — the worker could have died a moment before or after actually
doing the work.

Rather than guess, this is treated as a failure by default. You may change that in
three places, checked in this order:

1. **Per step** — implement the `RetryWhenStale` marker interface on the step class to
   always retry when found stale, regardless of anything else:

   ```php
   use Henzeb\Saga\Contracts\RetryWhenStale;

   class ChargePayment implements ShouldQueue, RetryWhenStale
   {
       // ...
   }
   ```

2. **Per workflow** — override `onStaleRunning()` to set a policy for every step in
   that workflow (see [Retrying Stale Steps](workflows.md#retrying-stale-steps)):

   ```php
   public function onStaleRunning(): ?string
   {
       return 'retry'; // or 'fail'
   }
   ```

3. **Globally** — the `saga.on_stale_running` configuration value, defaulting to
   `'fail'`.

When a stale step isn't retried, it's recorded `Failed` with a
`StaleSagaStepException` as the reason, and compensation proceeds exactly as it would
for any other failure — a stale compensating attempt is recorded `CompensationFailed`
instead, following the same rule as any other failure encountered mid-rollback.

> [!NOTE]
> The `RetryWhenStale`/`onStaleRunning()` policy above only ever fires reactively,
> the moment the queue redelivers the job — a message that's lost entirely and never
> redelivered is your queue's own dead-letter or `failed_jobs` concern, separate from
> anything the saga tracks. If you want a step stuck `Running`/`Compensating` to time
> out even without a redelivery — a crashed sync run, say — override
> `runningTimeout()` on the workflow and schedule `saga:sweep-stale`, the same way a
> step parked on `waitFor()` is timed out by `runningTimeout()`'s sibling,
> `signalTimeout()`, and `saga:sweep-signals` — see [Awaiting a
> Signal](steps.md#awaiting-a-signal). The sweep fails the step outright; it never
> retries it, since there's no redelivered job instance for `RetryWhenStale` to apply
> to.

<a name="interrupting-a-sync-run"></a>
## Interrupting a Sync Run

Running a saga with `sync: true` from the console —
`Saga::workflow($workflow)->start($context, sync: true)`, `php artisan saga:retry
--sync`, and so on — installs a `SIGINT` handler for the
duration of that run when the `pcntl` extension is loaded. Pressing Ctrl+C then
compensates the saga the same way any other failure would, instead of killing the
process mid-step: whichever step is currently running is recorded `Failed`, and
compensation cascades backward from there exactly as described above. The handler is
removed again once the run finishes, whether it finished normally, failed on its own,
or was interrupted.

Outside the console, or without `pcntl`, Ctrl+C is not caught and behaves as it
normally would.

<a name="compensating-manually"></a>
## Compensating Manually

`Saga::workflow($workflow, $key)->compensate()` rolls a saga back from wherever it
currently is, without waiting for a step to fail on its own:

```php
use App\Sagas\PlaceOrder;
use Henzeb\Saga\Facades\Saga;

Saga::workflow(new PlaceOrder(), $event->id)->compensate();
```

If the saga is still mid-run (`Pending`, `Running`, or `Waiting`), the currently
running step is recorded `Failed`, and compensation cascades backward from
there — the same outcome the Ctrl+C handling above produces.

It also works on a saga that already finished successfully (`Completed`): every
step, including the last one, is compensated in turn, exactly as if the whole
thing were being undone after the fact — cancelling an order that already shipped,
say. Calling it on a saga that's already compensating, or that has nothing left to
compensate at all (`RolledBack`, `Failed`, `CompensationFailed`), is a no-op.

Pass `sync: true` to run that compensation synchronously instead of dispatching it
to a queue worker, the same as `start()`, `retry()`, and the rest.

The [`saga:compensate`](console-commands.md#the-saga-compensate-command) Artisan
command does the same thing from the console — for one saga by ID (stuck mid-run or
already `Completed`), or in bulk for every saga stuck mid-run since before a given
cutoff. Bulk compensation never touches a `Completed` saga — that's single-saga only,
on purpose.
