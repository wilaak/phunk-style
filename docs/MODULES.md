# Modules

We jerry-rig namespaces to function as modules. PHP doesn't have any "real" sense of what a module is, so this is what we got.

For more about how to compose an app (core/shell, the DAG, wiring), see [ARCHITECTURE.md](ARCHITECTURE.md).

## Quick rules

- A module is a namespace: one flat word under a vendor root. The directory mirrors it.
- Import the namespace, not the symbol; keep the prefix at the call site.
- Mark internals `#![internal]`, file-locals `#![local]`; unmarked is public.
- Load every module/file at startup with instead of PSR-4 lazy-loading.

## What a module owns

A module owns a family of types and the functions over them. `ledger` owns `Account`, `Transfer`, and `TransferStatus`; `account_debit` lives there because `Account` does.

A few modules own only operations over primitives or foreign types (`util`, an `http` edge).

Err coarse. A module is a subsystem, not a single type. A namespace per class fragments into wiring sprawl. Split only when a real subsystem emerges.

## Naming

Name it for the prefix it becomes (`ledger\transfer`):

- One short noun, `snake_case`: `ledger`, `store`, `http`, `cpu`. It prefixes hundreds of calls; long is noise.
- Name the subsystem, not a type: `ledger`, not `account`. The module prefix carries the subsystem, the function prefix the type.
- It must read as a prefix: `store\account_load()`, `http\request_parse()`.
- No dumping grounds (`common`, `shared`, `helpers`, `misc`, `core`); `util` is the one exception.
- No stutter: not `app\ledger_module`, not `ledger\ledger_transfer`.

## Layout on disk

A module is `vendor\module`, one flat word below the root: `app\catalog`, `app\ledger`. The directory mirrors the namespace, so each directory under `app/` is a module (`app\http`, `app\catalog`).

Another `\` means another module, not a submodule, unless it is a group tier.

```
📁 src/
  📁 app/
    📁 http/
      📄 request.php
      📄 response.php
      📄 router.php
    📁 auth/
      📄 session.php
      📄 password.php
    📁 catalog/
      📄 product.php
      📄 category.php
      📄 search.php
    📁 orders/
      📄 cart.php
      📄 order.php
      📄 checkout.php
    📁 ledger/
      📄 account.php
      📄 transfer.php
    📁 store/
      📄 account_store.php
      📄 order_store.php
    📁 payment/
      📁 stripe/
        📄 charge.php
        📄 refund.php
      📁 paypal/
        📄 charge.php
      📁 klarna/
        📄 charge.php
    📁 util/
      📄 clamp.php
      📄 uuid.php
```

Most modules are flat (`app\catalog`), one word under the root. Many files in one directory is the goal. Near-empty directory trees hide the module and add path noise.

Add a directory only when a real module emerges. Files in one module call each other unqualified.

A group is the one exception, and only one tier deep. Three or more modules of the same kind (payment providers, codecs, hashes) may share a directory: `app\payment\stripe`, `app\payment\paypal`, `app\payment\klarna`.

The group is a directory, not a module: no behavior, and it grants no shared visibility (`app\payment\stripe` cannot reach `app\payment\paypal`'s `#![internal]` symbols).

Call sites stay flat: `use app\payment\stripe;` then `stripe\charge()`. If you cannot name the family as N variations on one noun, it is not a group.

Modules that lift out together with their own config and deps are a package, the unit of reuse. The package — not the individual module — is the boundary at which dependencies split into their own `Config`/`Deps` pair instead of riding the app-wide `$env` (see [ARCHITECTURE.md](ARCHITECTURE.md), *Past one application*).

## Imports

Import the namespace, not the symbol: `use app\util;` then `util\clamp()`.

The prefix names the origin and stays greppable. `use function` and `use const` strip it; forbidden.

Class imports (`use app\ledger\Account;`) are fine.

```php
// avoid: symbol import strips the origin
use function app\util\clamp;
$v = clamp($x, $lo, $hi);

// good: namespace import keeps the prefix
use app\util;
$v = util\clamp($x, $lo, $hi);
```

```php
// a lone import can stay one line
use app\util;

use app\{
    config,
    env,
    api,
    http,
};
```

Alias to break a collision, not to shorten, and keep the origin:

- Leaf collision: `use acme\json as acme_json;` then `acme_json\decode()`.
- Class collision: `use app\ledger\Account as LedgerAccount;`.

Don't alias to a short token (`use app\ledger as l;`); that strips context.

PHP has no module-private visibility, so two attributes add it:

- `#![internal]`: module-private, the default. Visible in the namespace, invisible outside.
- `#![local]`: file-private. Rare.
- Unmarked is public.

## Module boundaries

Three questions decide where a boundary falls:

- Name. One noun? If it needs an `and`, two modules.
- Change. One reason to change? Two reasons, two modules.
- Dependency. Depends downward only? If two candidates reference each other, they are one module.

The public surface is whatever is unmarked; `#![internal]` and `#![local]` hide the rest. The markers are the source of truth, so a linter or tool can list a module's public API on demand. No hand-written header to drift.

Three properties make a boundary honest:

- Self-contained. Everything from outside arrives through a signature: data as arguments, capabilities through `$env`. No reaching into another module's internals, no globals.
- One direction. Dependencies point down the DAG only.
- Stable surface. The public surface changes only when the contract does.

If you cannot say what the module does in a line, it owns more than one noun.