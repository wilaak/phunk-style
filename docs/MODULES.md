# Modules

A module owns a family of types and the functions over them. It is a subsystem, not a single type.

## Naming

Name the module for the prefix it becomes (`ledger\account_load()`):

- One short noun, `snake_case`: `ledger`, `store`, `http`, `cpu`.
- The subsystem, not a type: `ledger`, not `account`.
- Reads as a prefix: `store\account_load()`, `http\request_parse()`.
- No stutter: not `app\ledger_module`, not `ledger\ledger_transfer`.

## Layout example

```
src/
  app/
    http/
      request.php
      response.php
      router.php
    catalog/
      product.php
      category.php
      search.php
    ledger/
      account.php
      transfer.php
    store/
      account_store.php
      order_store.php
    payment/
      stripe/
        charge.php
        refund.php
      paypal/
        charge.php
      klarna/
        charge.php
    util/
      clamp.php
      uuid.php
```

## Module, package, grouping directory

| Unit               | Is the unit of              | Boundary marker                |
| ------------------ | --------------------------- | ------------------------------ |
| Module             | organization and visibility | `#![internal]` surface         |
| Package            | reuse and dependencies      | own env (`Config`/`Deps`, `boot()`) |
| Grouping directory | navigation                  | none, just a folder            |

The package, not the module, is the only boundary where dependencies stop riding the app-wide `$env` and split into `Config`/`Deps`.

Don't mint a pair per module; that trades the god `$env` for wiring sprawl.

### Package-level env

A package that lifts out carries its own env: a narrow `Config` (pure data) and `Deps` (capabilities), built once by `pkg\boot(Config): Deps`. That pair is the custom, package-scoped env, separate from the app-wide `$env`.

Modules do not get their own env inside one app. Below the package boundary a function takes the one capability it needs (`\PDO $database`, `Clock $clock`), never a bespoke env.

## Visibility

PHP has no module-private visibility, so two comment-modifiers add it:

- `#![internal]`: module-private.
- `#![local]`: file-private.
- Unmarked: public.

## Imports

Import the namespace, not the symbol. The prefix names the origin and stays greppable. Exceptions being if it's actually more readable to do import the full name.

```php
use app\util;

use app\{
    config,
    env,
    api,
    http,
};
```

## Core and shell

Split a module into a pure core and a thin shell.

- **Core.** Free functions over data. No I/O, no `$env`, no external mutation.
- **Shell.** The edge that holds `$env`, performs I/O, and calls the core.

The shape is gather, decide, commit. Gather and commit live in the shell; decide is pure. 

Never interleave I/O with logic: read everything up front, compute, then write.

A bug in the core is found by following its values; an effect only happens at the shell, where you can see it.

```php
// shell: holds $env, does the I/O, calls the pure core
function order_settle(env\Env $env, int $order_id): Result
{
    $order  = store\order_load($env->database, $order_id);   // gather
    $priced = ledger\order_price($order);                    // decide (pure)
    store\order_save($env->database, $priced);               // commit
    return Result::Ok;
}
```

## Dependency DAG

Modules form a directed acyclic graph. A module may depend on those below it, never on those above, and never in a cycle. If A uses B, B does not use A. Shared lower-level needs sink into a module both depend on (`util`, `store`).

Two modules that reference each other are not two modules; they are one, or a third module is hiding between them. The bottom of the graph is data and pure functions; capabilities and I/O enter at the top, through the shell.

The app is the root and `util` is a leaf. Build order is a topological sort: a package's `boot()` runs after the things it depends on.

## Boundaries

Where a boundary falls, three questions:

- **Name.** One noun? If it needs an `and`, two modules.
- **Change.** One reason to change? Two reasons, two modules.
- **Dependency.** Downward only? If two candidates reference each other, they are one module.

An honest boundary is:

- **Self-contained.** Everything external arrives through a signature: data as arguments, capabilities through `$env`. No reaching into internals, no globals.
- **One-directional.** Dependencies point down the DAG only.
- **Stable.** The public surface changes only when the contract does.

If you cannot say what a module does in one line, it owns more than one noun.
