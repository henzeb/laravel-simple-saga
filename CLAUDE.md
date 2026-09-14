# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`henzeb/laravel-simple-saga` — a Laravel package implementing the saga pattern: a `Workflow` defines an ordered
list of step jobs, and the package drives them forward one at a time, persisting progress after each step so it
can resume after a crash, retry a stale step, or walk backward through compensation when one fails.

## Commands

```bash
composer install            # install dependencies (requires resolving a Laravel/testbench version — see below)

composer test                # vendor/bin/pest
composer test-parallel       # vendor/bin/pest --parallel
composer test-coverage       # XDEBUG_MODE=coverage vendor/bin/pest --coverage-html coverage
composer test-coverage-txt   # vendor/bin/pest --coverage
composer test-dox            # vendor/bin/pest --testdox
composer test-mutate         # XDEBUG_MODE=coverage vendor/bin/pest --mutate

vendor/bin/pest tests/Unit/SagaCoordinatorTest.php   # run a single test file
vendor/bin/phpstan analyse    # static analysis (level 9, see phpstan.neon)
```

`phpstan.neon` is gitignored (present locally but not committed) — matching the sibling package this repo was
bootstrapped from.

## Architecture

**Driving a saga forward** is split across three layers:

- `SagaManager` (a Laravel `Manager`) resolves the configured driver (`saga.default`: `database` or `cache`) and
  builds a `SagaCoordinator` around it. The `Saga` facade and the `'saga'` container alias both point here.
  `SagaManager` itself only exposes `workflow()` (the entry point for everything scoped to one saga — `start()`,
  `get()`, `signal()`, `compensate()`, `delete()`, `retry()`, `retryCompensation()`, all on the `SagaWorkflow` it
  returns — every one of them but `start()` throws a `LogicException` up front if no `sagaId` was ever bound),
  `coordinator()` (the escape hatch for a bare `sagaId` with no workflow known at all, as in `saga:show`), and
  bulk/admin operations that all route through `coordinator()` in turn: `active()`, `retryable()`, `prune()`
  (each optionally scoped with a `?string $workflow`), `due()` (a read-only query for signal waits past their
  deadline) and `sweepSignals()` (the action that fails them — `SagaCoordinator::failDueSignals()` is the
  low-level primitive both `sweepSignals()` and direct callers with an already-fetched batch can use). Deleting one
  specific saga by a bare `sagaId` goes through the same two paths as every other single-saga operation:
  `$saga->coordinator()->delete($sagaId)` or `Saga::workflow($workflow, $sagaId)->delete()`.
- `SagaCoordinator` is the actual orchestration engine — starting a saga, marking a step running/completed/failed,
  cascading compensation, and dispatching the next step's job. It never touches storage directly; it goes through
  a `Contracts\Driver` (`DatabaseDriver` or `CacheDriver`), which persists an append-only trail of
  `DTO\SagaStepRecord` (one row per state transition, keyed by `sagaId` + step index). Current state — status,
  context, whether a step is stale — is always derived from the latest record(s) in that trail, never cached
  separately.
- `Middleware\ProcessesSaga` is attached to every step job by the coordinator at dispatch time (`$job->middleware
  = [new ProcessesSaga()]`, not something a workflow author wires up). It's the piece that actually decides what
  happens after a job runs: skip already-completed steps, fail stale ones per `Workflow::onStaleRunning()` (or the
  `saga.on_stale_running` config default), call `compensate()` instead of `handle()` while rolling back, and after
  a run either advance to the next step, complete the saga, or re-run the current step (see Iteration below).

**Steps** are plain Laravel jobs — no interface to implement. They only need the `Concerns\InteractsWithSaga`
trait (which the coordinator uses reflectively to confirm a class is saga-aware before dispatching it), giving
them `sagaId`/`workflow`/`sagaStepIndex`/`context` properties and a `context()` accessor that wraps the raw
context in `DTO\SagaContext` for typed access (`->object()`, `->array()`, `->enum()`, etc.). `SagaStep` is an
optional convenience base class bundling that trait with the usual queued-job traits.

**Workflows** extend `Workflow` and implement `steps(): array<class-string>`. `WorkflowValidator` checks each
workflow class once per process (memoized) before its first saga starts — every step uses `InteractsWithSaga`, no
step both defines a `compensate()` method and carries `#[CompensatedBy]`, and any `#[CompensatedBy]` target
actually implements `ShouldCompensate` — throwing `InvalidWorkflowException` listing every problem found. Note
that implementing `ShouldCompensate` without a `compensate()` method is fine alongside `#[CompensatedBy]` on the
*same* class — that combination just means the class is only usable as *someone else's* compensator (the marker
makes it a legal `#[CompensatedBy]` target), while its own compensation is still delegated via its own attribute;
`resolveCompensator()` always prefers the attribute over self-compensation, so there's no real ambiguity to block.
This is what lets two steps name each other as their compensator and rely on `handle()` for both directions — see
`CoordinatorTestMutualCompensatorWorkflow` in `tests/Support/CoordinatorTestFixtures.php`.

**Compensation** runs backward through already-completed steps when a forward step fails on its final attempt (or
when a step calls `compensate()` itself). A step either implements `Contracts\ShouldCompensate` and compensates
itself, or is annotated `#[CompensatedBy(SomeOtherStep::class)]` to name an external compensator job — resolved by
`SagaCoordinator::resolveCompensator()`. If the resolved compensator class has no `compensate()` method, `handle()`
doubles as the compensation instead (`ShouldCompensate` is a marker-only interface — see its docblock). A step with
neither `ShouldCompensate` nor `#[CompensatedBy]` is treated as an instantly-compensated no-op, and
compensation keeps cascading to the step before it. Walking past step 0 records a terminal `RolledBack` status
(carrying the last step's context forward) rather than just firing `SagaRolledBack` with nothing stored — a
forward failure at step 0 itself, with nothing to compensate, stays `Failed` and never reaches this branch.

**Retrying** a saga never replays anything — there's no workflow function to re-execute, only independent job
dispatches threaded by `sagaId`. `SagaCoordinator::retry()` re-enters `beginSaga()` (the code `start()` also
calls) under the *same* `sagaId`, but only from a `RolledBack` state, defaulting to that saga's own final context
unless one is passed explicitly. `SagaCoordinator::retryCompensation()` re-enters `beginCompensation()` at a
specific step, but only from `CompensationFailed` — for unsticking one stuck compensator without restarting
anything else. Both require the workflow class explicitly; `Saga::workflow($workflow, $sagaId)` returns a
`SagaWorkflow` that binds `$sagaId` once for every one of its methods. It's resolved through `sagaIdFor()`:
already a valid Ulid (a real, already-existing sagaId), it's used as-is; anything else is hashed into one,
deterministically, for the same workflow — the same mechanism whether the saga is new (`start()`) or existing
(`get()`, `retry()`, and the rest). `SagaWorkflow::id()` resolves and memoizes it once, at construction, and
every method reads that same resolved id from then on. `DTO\SagaStepRecord` carries an optional `workflow` class
name, written by the coordinator at every state transition, so `saga:retry {sagaId}` can resolve which workflow
to use without it being passed on the command line (`--workflow=` overrides it, or fills it in when a record has
none).

**Iteration**: a step that isn't done in one shot (polling, paging) uses `Concerns\IterableSagaStep` and
implements `hasNext(): bool`. Both `hasNext()` and `next()` are `protected` by design — the middleware calls them
via a `Closure` bound to the job instance, not through a public contract — so after a run, if `hasNext()` is true,
`next()` runs (a no-op by default; override it to mutate `$this->context` for the next attempt) and the same step
is redispatched at the same index instead of completing. See `docs/steps.md`.

**Events** (`src/Events/`) fire at every lifecycle transition, dispatched inline with the coordinator method that
causes them (see `docs/events.md` for the full list and firing order).

**Encryption**: a `Workflow` can override `encryptContext(): true` to have its context payload encrypted at rest
(via `Crypt`) in every stored `SagaStepRecord`, transparently decrypted when read back.

**Testing** (`src/Testing/SagaFake.php`, `src/Facades/Saga.php::fake()`): `Saga::fake()` reuses `CacheDriver` over
Laravel's built-in `'array'` cache store as the fake driver — no dedicated test-only driver class — registered via
`SagaManager::extend()` (it's a plain `Illuminate\Support\Manager`), and calls the real `Bus::fake()` so every step
job is intercepted by default, same as any other faked dispatch; `SagaFake::except()` proxies to `BusFake::except()`
to let specific job classes run for real (only actually executes them under `sync: true`, same as outside a test).

**Console commands** (`src/Console/Commands/`, registered by the service provider): `saga:show`, `saga:delete`,
`saga:list`, `saga:prune`, `saga:prune-failed`, `saga:retry`, `saga:sweep-signals`, `saga:sweep-stale` — none of
them touch a driver directly, every one goes through `SagaManager`/`SagaCoordinator`. `saga:list`, `saga:prune`,
and `saga:prune-failed` all accept `--workflow=` to scope to one workflow class; `saga:prune` also accepts
`--with-rolled-back` (`-r`) to also target `RolledBack` sagas, and `saga:prune-failed` accepts
`--with-compensation-failed` (`-c`) the same way for `CompensationFailed`. `saga:retry`, `saga:prune`,
`saga:prune-failed`, `saga:sweep-signals`, and `saga:sweep-stale` all share the same `ConfirmableTrait` +
`--force` pattern for production safety, and the same `--dry-run` convention (report what *would* happen,
skipping the confirmation prompt too, since nothing destructive happens). `saga:retry {sagaId}` reads
`SagaState::status`/`workflow` to decide whether to call `retry()` or `retryCompensation()` (erroring on any
other status), with `--sync` passed straight through; without a `sagaId`, it retries every retryable saga in
bulk instead (via `Saga::retryable()`), optionally scoped with `--workflow=` — `sagaId` and `--workflow=` can't
be combined. `saga:sweep-stale` is `saga:sweep-signals`'s counterpart for a step stuck `Running`/`Compensating`
past `Workflow::runningTimeout()` (or `config('saga.running_timeout')`) instead of a `Waiting`/
`CompensationWaiting` step past `signalTimeout()` — same `dueRunning()`/`failDueRunning()` shape on the
coordinator as `due()`/`failDueSignals()`, reusing `StaleSagaStepException` rather than a dedicated exception,
and firing only reactively-detected-redelivery's opposite: a proactive sweep with no job instance to retry, so
it always fails the step, never retries it.

## Version support

Supports PHP 8.3–8.5 and Laravel 11–13 simultaneously (`composer.json` requires
`illuminate/support: ^11.0|^12.0|^13.0`, `orchestra/testbench: ^9.0|^10.0|^11.0`). When adding code, avoid APIs
that only exist in a subset of that range. CI (`.github/workflows/tests.yml`) verifies only the boundaries of that
range — PHP 8.3/8.5, Laravel 11/13, each × dependency-stability (`prefer-lowest`/`prefer-stable`), 8 jobs total —
on the assumption that the versions in between don't need their own job if both ends pass; the "Set Laravel
version" step pins `illuminate/support` and `orchestra/testbench` to the matrix's Laravel version before
`composer update` resolves the rest.

## Tests

PSR-4 root namespace `Henzeb\Saga\` → `src/`. Test namespace `Tests\` → `tests/`. Tests are split into
`tests/Unit` and `tests/Integration` suites (per `phpunit.xml`), run through Pest (`tests/Pest.php` binds both
suites to `Tests\TestCase`, which extends Orchestra Testbench and registers `SagaServiceProvider` as the only
package provider). `tests/Integration` currently has no tests. Shared fixtures (test step/workflow classes) live
in `tests/Support/CoordinatorTestFixtures.php`, classmapped in `composer.json` rather than PSR-4 autoloaded since
it defines many small classes per file.

