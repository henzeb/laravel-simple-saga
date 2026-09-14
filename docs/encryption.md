# Encrypting Context

- [Introduction](#introduction)
- [What This Protects, and What It Doesn't](#what-this-protects)
- [Scope](#scope)

<a name="introduction"></a>
## Introduction

A saga's context is stored in full at every step — an order, a customer's payment
details, whatever the workflow passes along. If that context carries anything
sensitive, you may encrypt it at rest by overriding `encryptContext()` on the
workflow:

```php
use Henzeb\Saga\Workflow;

class PlaceOrder extends Workflow
{
    public function steps(): array
    {
        // ...
    }

    public function encryptContext(): bool
    {
        return true;
    }
}
```

With this on, every context payload the saga stores — the initial context passed to
`start()`, and whatever each step returns — is encrypted with Laravel's own `Crypt`
facade before it reaches the driver, using the application's configured `APP_KEY`.
Nothing else changes: steps still read context through `context()` exactly as
described in [Reading the Context](steps.md#reading-the-context), and `current()`
still returns a plain, readable `SagaContext` — decryption happens transparently on
the way back out.

<a name="what-this-protects"></a>
## What This Protects, and What It Doesn't

Encryption applies to the context payload only — the value a step returns and the
next step receives. It does not cover `reason` (the exception message recorded on a
`Failed` or `CompensationFailed` step), the saga's status, or its step index.

> [!WARNING]
> Don't put anything sensitive into an exception message you expect a failed step to
> record, encrypted context or not — it's stored as plain text regardless of
> `encryptContext()`.

Turning this on has a real cost worth knowing about before you flip it: an encrypted
`database` row can no longer be queried or filtered by its `payload` column's
contents. Reach for this when the context itself is the sensitive part — not as a
blanket default for every workflow.

<a name="scope"></a>
## Scope

`encryptContext()` is a per-workflow decision, checked once per record write and
read — different workflows in the same application may choose independently, and a
workflow's existing sagas keep behaving the way they were written even if you change
the method later.
