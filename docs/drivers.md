# Drivers

- [Introduction](#introduction)
- [The `database` Driver](#the-database-driver)
- [The `cache` Driver](#the-cache-driver)
- [Choosing Between Them](#choosing-between-them)
- [Writing a Custom Driver](#writing-a-custom-driver)

<a name="introduction"></a>
## Introduction

Every record the package writes — one per step per status change — is stored through
a driver. Two ship with the package, and you may register your own.

The active driver is set in `config/saga.php`:

```php
'default' => env('SAGA_DRIVER', 'database'),
```

<a name="the-database-driver"></a>
## The `database` Driver

The default driver appends every record to a single table (`saga_steps` by default),
using the query builder rather than Eloquent — the same approach Laravel's own
database queue and cache drivers take. Configure the connection and table name under
`drivers.database`:

```php
'drivers' => [
    'database' => [
        'connection' => null, // null = the application's default connection
        'table' => 'saga_steps',
    ],
],
```

If you rename the table, publish and adjust the migration to match — the driver and
the migration both read the same `saga.drivers.database.table` configuration value,
so they never drift apart:

```bash
php artisan vendor:publish --tag=saga-migrations
```

<a name="the-cache-driver"></a>
## The `cache` Driver

The `cache` driver stores a saga's whole trail as one value under one cache key,
using your chosen Laravel cache store — a good fit if you'd rather not add a
migration, or if sagas are short-lived enough that a database table feels heavier
than necessary.

```php
'drivers' => [
    'cache' => [
        'store' => null, // null = the application's default cache store
        'ttl' => null,   // null = kept forever
    ],
],
```

You may point `store` at any cache store your application already has configured,
including Laravel's built-in `file` store, if you want on-disk persistence without a
database.

> [!NOTE]
> Every store Laravel ships (`file`, `database`, `array`, `redis`, `memcached`,
> `dynamodb`) supports the atomic locking the driver relies on to keep concurrent
> writes from corrupting a saga's trail, so there's no dedicated `file` driver —
> `cache` configured with the `file` store already covers that case.

Set `ttl` (in seconds) if you'd like finished sagas to expire automatically; leave it
`null` to keep every saga's history until you explicitly [prune or delete
it](console-commands.md).

<a name="choosing-between-them"></a>
## Choosing Between Them

Reach for `database` when you want sagas queryable alongside the rest of your
application's data, or when you expect a meaningful volume of concurrent sagas — an
indexed table scales more predictably than a single cache entry per saga. Reach for
`cache` when you'd rather avoid a migration entirely, or when a cache store you
already run, Redis say, is a more natural fit for your infrastructure than a
database table.

<a name="writing-a-custom-driver"></a>
## Writing a Custom Driver

A driver only needs to implement `Henzeb\Saga\Contracts\Driver`:

```php
interface Driver
{
    public function store(SagaStepRecord $record): void;

    /** @return SagaTrail<int, SagaStepRecord> the full trail for a saga, in order */
    public function get(string $sagaId): SagaTrail;

    public function latest(string $sagaId): ?SagaStepRecord;

    public function latestFor(string $sagaId, int $step): ?SagaStepRecord;

    public function delete(string $sagaId): void;

    /** @return SagaStepRecords<int, SagaStepRecord> the latest record for every saga that's running or has failed */
    public function active(?string $workflow = null): SagaStepRecords;

    /** @return SagaStepRecords<int, SagaStepRecord> the latest record for every saga currently retryable */
    public function retryable(?string $workflow = null): SagaStepRecords;

    public function prune(SagaStepStatus $status, ?DateTimeInterface $before = null, bool $dryRun = false, ?string $workflow = null): int;

    /** @return SagaStepRecords<int, SagaStepRecord> the latest record for every saga whose signal wait has passed its deadline */
    public function dueSignals(?DateTimeInterface $before = null): SagaStepRecords;

    /** @return SagaStepRecords<int, SagaStepRecord> the latest record for every saga whose Running/Compensating step has passed its timeout */
    public function dueRunning(?DateTimeInterface $before = null): SagaStepRecords;
}
```

`get()` returns a `SagaTrail` — a single saga's ordered history. `active()`, `retryable()`,
`dueSignals()`, and `dueRunning()` return a `SagaStepRecords` — the latest record for
each of several different sagas. Both are read-only `Illuminate\Support\Collection`
subclasses.

Every console command (`saga:list`, `saga:retry`, `saga:prune`, `saga:prune-failed`,
`saga:sweep-signals`, `saga:sweep-stale`) relies on the full interface, so a custom
driver must implement all of it.

Register the driver in a service provider's `boot()` method, the same way you'd
extend any other Laravel manager:

```php
use Henzeb\Saga\SagaManager;

$this->app->make(SagaManager::class)->extend('redis-list', function ($app) {
    return new RedisListDriver(/* ... */);
});
```

Then select it as the default:

```php
'default' => env('SAGA_DRIVER', 'redis-list'),
```
