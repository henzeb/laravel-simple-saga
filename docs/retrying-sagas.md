# Retrying a Saga

- [Introduction](#introduction)
- [Restarting a Rolled-Back Saga](#restarting-a-rolled-back-saga)
- [Retrying a Stuck Compensation](#retrying-a-stuck-compensation)
- [The `saga:retry` Command](#the-saga-retry-command)

<a name="introduction"></a>
## Introduction

A `RolledBack` or `CompensationFailed` saga (see [Saga Statuses](inspecting-sagas.md#saga-statuses))
may be retried under its own `sagaId` through `Saga::workflow($workflow, $sagaId)` —
the same `sagaId` you'd already have from [inspecting](inspecting-sagas.md) it or from
`saga:list`:

```php
use Henzeb\Saga\Facades\Saga;

$workflow = Saga::workflow(PlaceOrder::class, $sagaId);
```

<a name="restarting-a-rolled-back-saga"></a>
## Restarting a Rolled-Back Saga

`retry()` restarts a `RolledBack` saga from step 0, under that same `sagaId`:

```php
$state = Saga::workflow(PlaceOrder::class, $sagaId)->retry();
```

```php
public function retry(mixed $context = null, bool $sync = false): SagaState
```

- `$context` — defaults to the context the saga's compensation last left behind. Pass
  a value to use instead.
- `$sync` — runs synchronously, the same as [`start()`](workflows.md#running-a-saga-synchronously).

Throws `SagaNotRetryableException` if the saga's status isn't `RolledBack`, or
`SagaNotFoundException` for an unknown `sagaId`.

<a name="retrying-a-stuck-compensation"></a>
## Retrying a Stuck Compensation

`retryCompensation()` re-dispatches the compensator for a saga's `CompensationFailed`
step. On success, compensation continues backward through the remaining steps exactly
as described in [Compensation](compensation.md).

```php
$state = Saga::workflow(PlaceOrder::class, $sagaId)->retryCompensation();
```

```php
public function retryCompensation(bool $sync = false): SagaState
```

Throws `SagaNotRetryableException` if the saga's status isn't `CompensationFailed`,
or `SagaNotFoundException` for an unknown `sagaId`.

> [!NOTE]
> Both throw a `LogicException` up front if `Saga::workflow()` was called without a
> `sagaId` — there's no saga to recover without one.

<a name="the-saga-retry-command"></a>
## The `saga:retry` Command

```bash
php artisan saga:retry {sagaId}
```

Reads the saga's status and workflow off its own trail, then calls `retry()` or
`retryCompensation()` accordingly. Leave out `{sagaId}` to retry every retryable saga
in bulk instead — see [Console Commands](console-commands.md#the-saga-retry-command)
for the full set of options.
