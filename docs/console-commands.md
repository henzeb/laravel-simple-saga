# Console Commands

- [Introduction](#introduction)
- [The `saga:list` Command](#the-saga-list-command)
- [The `saga:show` Command](#the-saga-show-command)
- [The `saga:delete` Command](#the-saga-delete-command)
- [The `saga:prune` Command](#the-saga-prune-command)
- [The `saga:prune-failed` Command](#the-saga-prune-failed-command)
- [The `saga:retry` Command](#the-saga-retry-command)
- [The `saga:compensate` Command](#the-saga-compensate-command)
- [The `saga:sweep-signals` Command](#the-saga-sweep-signals-command)
- [The `saga:sweep-stale` Command](#the-saga-sweep-stale-command)
- [Scheduling Cleanup](#scheduling-cleanup)

<a name="introduction"></a>
## Introduction

The package registers nine Artisan commands for inspecting, retrying, compensating,
cleaning up, and timing out sagas.

<a name="the-saga-list-command"></a>
## The `saga:list` Command

Lists every saga that's running or has failed — its saga ID, current step, current
status, and when it started:

```bash
php artisan saga:list
```

```
+----------------------------+------+---------+---------------------+
| Saga ID                    | Step | Status  | Started At          |
+----------------------------+------+---------+---------------------+
| 01HXAMPLE0000000000000000  | 1    | running | 2026-09-14 09:00:01 |
| 01HXAMPLE0000000000000001  | 1    | failed  | 2026-09-14 08:12:03 |
+----------------------------+------+---------+---------------------+
```

`Completed` and `RolledBack` sagas are left out — those are done. Everything else,
including `Failed` and `CompensationFailed`, shows up here.

| Option | Description |
| --- | --- |
| `--workflow=` | Only list sagas recorded under this workflow class. |

<a name="the-saga-show-command"></a>
## The `saga:show` Command

Displays the current status of one saga:

```bash
php artisan saga:show {sagaId}
```

```
Saga ID .......... 01HXAMPLE0000000000000000
Status ........... Failed ●
Step ............. 2 — Charge Payment
```

Add `--trail` (or its shortcut `-t`) to also show its full history, one line per
recorded transition:

```bash
php artisan saga:show {sagaId} --trail
```

```
Saga ID .......... 01HXAMPLE0000000000000000
Status ........... Failed ●
Step ............. 2 — Charge Payment

History
  2026-09-14 09:00:01  Step 1 — Reserve Stock   Completed
  2026-09-14 09:00:04  Step 2 — Charge Payment  Failed
                       ↳ RuntimeException: The payment gateway rejected the charge.
  2026-09-14 09:00:04  Step 1 — Reserve Stock   Compensation Pending
  2026-09-14 09:00:05  Step 1 — Reserve Stock   Compensated
```

The step number is shown 1-indexed — `Step 1` is the workflow's first step
(`steps()[0]` internally). Everything else — `sagaStepIndex` on a job,
`SagaState::step`, compensator resolution — stays 0-indexed; only this display
counts from one. The top-level `Step` line drops the `Step` word itself, since the
label to its left already says it; the trail keeps it, one row per transition with
no label of its own.

Each trail row's status is color-coded (green for completed/compensated, red for
failed, cyan while compensating, yellow while still forward in-flight, magenta for
`RolledBack`) — the `●` next to the top-level `Status` line carries the same color.
A row's `reason`, when it has one, prints on its own indented line underneath,
with the exception class dimmed ahead of its message. The step column names each
step by its class basename, or by a `#[StepLabel]` attribute on the step class if
it carries one:

```php
use Henzeb\Saga\Attributes\StepLabel;
use Henzeb\Saga\SagaStep;

#[StepLabel('Charge Payment')]
class ChargePayment extends SagaStep
{
    // ...
}
```

`#[StepLabel]` accepts three kinds of value:

- **A plain string**, used as-is — `'Charge Payment'` above.
- **An invokable class**, resolved through the container and called with the
  step's own [`SagaState`](inspecting-sagas.md#the-saga-state) for that trail row,
  so the label can pull something out of its context:

```php
use Henzeb\Saga\Attributes\StepLabel;
use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\SagaStep;

class ChargePaymentLabel
{
    public function __invoke(SagaState $state): string
    {
        return 'Charge Payment — order #'.$state->context()->object(Order::class)->id;
    }
}

#[StepLabel(ChargePaymentLabel::class)]
class ChargePayment extends SagaStep
{
    // ...
}
```

- **A class implementing `ProvidesStepLabel`**, the same idea through a named
  `toLabel()` method instead of `__invoke()`:

```php
use Henzeb\Saga\Contracts\ProvidesStepLabel;
use Henzeb\Saga\DTO\SagaState;

class ChargePaymentLabel implements ProvidesStepLabel
{
    public function toLabel(SagaState $state): string
    {
        return 'Charge Payment — order #'.$state->context()->object(Order::class)->id;
    }
}
```

Either way, the class is resolved through the container, so it may ask for services
by adding them as typed constructor parameters — the same as a step's own `handle()`
does.

While a step is compensating, the step column labels it by its **compensator**
instead of the step itself — the external class named in `#[CompensatedBy]`, or the
step's own class again when it self-compensates. A `#[StepLabel]` on that
compensator class is what shows; the step's own `#[StepLabel]` only applies while
it's still running forward. A step with no compensator at all — nothing to relabel
— keeps showing its own label throughout.

| Option | Description |
| --- | --- |
| `--trail` (`-t`) | Also show the full trail, one line per recorded transition. |
| `--limit=` | With `--trail`, how many trail rows to show before paging through `less` instead (default `20`). Only pages against a real interactive terminal — piped or captured output always shows every row. |

<a name="the-saga-delete-command"></a>
## The `saga:delete` Command

Deletes everything stored for one saga, regardless of its current status:

```bash
php artisan saga:delete {sagaId}
```

> [!WARNING]
> Like any destructive command in this package, running it against a production
> environment prompts for confirmation unless you pass `--force`.

<a name="the-saga-prune-command"></a>
## The `saga:prune` Command

Deletes every saga whose most recent status is `Completed`:

```bash
php artisan saga:prune
```

You may pass the following options:

| Option | Description |
| --- | --- |
| `--before=` | Only prune sagas last touched before this date or duration. |
| `--workflow=` | Only prune sagas recorded under this workflow class. |
| `--dry-run` | Report how many sagas would be pruned, without deleting anything. Skips the confirmation prompt too. |
| `--force` | Skip the production confirmation prompt. |
| `--with-rolled-back` (`-r`) | Also prune sagas stuck at `RolledBack`. |

`RolledBack` sagas are excluded unless `--with-rolled-back` is passed.

<a name="the-saga-prune-failed-command"></a>
## The `saga:prune-failed` Command

Deletes every saga whose most recent status is `Failed`:

```bash
php artisan saga:prune-failed
```

It accepts the same `--before`, `--workflow=`, `--dry-run`, and `--force` options as
`saga:prune`, plus:

| Option | Description |
| --- | --- |
| `--with-compensation-failed` (`-c`) | Also prune sagas stuck at `CompensationFailed`. |

`CompensationFailed` sagas are excluded unless `--with-compensation-failed` is passed.

<a name="the-saga-retry-command"></a>
## The `saga:retry` Command

```bash
php artisan saga:retry {sagaId}
```

Retries a saga whose latest status is `RolledBack` or `CompensationFailed`, reading
its workflow class off its own recorded trail — see [Retrying a
Saga](retrying-sagas.md) for what each does.

| Option | Description |
| --- | --- |
| `--sync` | Run synchronously instead of dispatching to a queue worker. |
| `--dry-run` | Report what would be retried, without retrying anything. Skips the confirmation prompt too. |
| `--force` | Skip the production confirmation prompt. |

Leave out `{sagaId}` to retry every retryable saga in bulk instead of one:

```bash
php artisan saga:retry
php artisan saga:retry --workflow=App\\Sagas\\OrderWorkflow
```

Without `--workflow=`, every retryable saga across every workflow is retried. With
it, only sagas recorded under that workflow class are retried. `{sagaId}` and
`--workflow=` can't be combined.

<a name="the-saga-compensate-command"></a>
## The `saga:compensate` Command

Manually compensates one saga by ID — whether it's stuck mid-run (`Pending`,
`Running`, or `Waiting`) or already `Completed`. For a stuck saga, its current step
is failed and compensation cascades back through everything already completed,
exactly as if that step had failed for real. For a `Completed` saga, every step —
including the last one — is compensated in turn instead. See [Compensating
Manually](failure-handling.md#compensating-manually) for what that cascade does.

```bash
php artisan saga:compensate {sagaId}
```

| Option | Description |
| --- | --- |
| `--sync` | Run the resulting compensation synchronously instead of dispatching to a queue worker. |
| `--dry-run` | Report what would be compensated, without compensating anything. Skips the confirmation prompt too. |
| `--force` | Skip the production confirmation prompt. |

Leave out `{sagaId}` to compensate every saga stuck mid-run in bulk instead of one:

```bash
php artisan saga:compensate --since="1 hour ago"
php artisan saga:compensate --since="1 hour ago" --workflow=App\\Sagas\\OrderWorkflow
```

`--since=` scopes this to sagas whose current step has been sitting there since
before that cutoff — without it, every saga currently `Pending`, `Running`, or
`Waiting` is compensated, including ones still legitimately in progress, so passing
it is strongly recommended for the bulk form. `--workflow=` further scopes it to sagas
recorded under that workflow class. `{sagaId}` can't be combined with `--since=` or
`--workflow=`.

The bulk form only ever targets stuck sagas — a `Completed` saga is never swept up
by `--since=`, no matter how old. Compensating one after the fact is always a
deliberate, single-saga action: `php artisan saga:compensate {sagaId}`.

<a name="the-saga-sweep-signals-command"></a>
## The `saga:sweep-signals` Command

Fails every saga whose `waitFor()` deadline has passed — see [Awaiting a
Signal](steps.md#awaiting-a-signal) for how that deadline is set:

```bash
php artisan saga:sweep-signals
```

| Option | Description |
| --- | --- |
| `--before=` | Sweep against this cutoff instead of now. |
| `--sync` | Run the resulting compensation synchronously instead of dispatching to a queue worker. |
| `--dry-run` | Report how many sagas would time out, without failing anything. Skips the confirmation prompt too. |
| `--force` | Skip the production confirmation prompt. |

<a name="the-saga-sweep-stale-command"></a>
## The `saga:sweep-stale` Command

Fails every saga whose `Running`/`Compensating` step has been stuck past its
timeout — see [Awaiting a Signal](steps.md#awaiting-a-signal) for how that deadline
is set (`runningTimeout()` sits right next to `signalTimeout()` there):

```bash
php artisan saga:sweep-stale
```

| Option | Description |
| --- | --- |
| `--before=` | Sweep against this cutoff instead of now. |
| `--sync` | Run the resulting compensation synchronously instead of dispatching to a queue worker. |
| `--dry-run` | Report how many sagas would time out, without failing anything. Skips the confirmation prompt too. |
| `--force` | Skip the production confirmation prompt. |

<a name="scheduling-cleanup"></a>
## Scheduling Cleanup

Since these commands accept `--force`, they're straightforward to run on a schedule:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('saga:prune --before="7 days ago" --force')->daily();
Schedule::command('saga:prune-failed --before="30 days ago" --force')->daily();
Schedule::command('saga:sweep-signals --force')->everyMinute();
Schedule::command('saga:sweep-stale --force')->everyMinute();
```
