# Modules

Phunk adds a thin module layer over namespaces.

## Quick look

```php
// src/app/ledger/transfer.php
//
// A module is one namespace. Every file under
// app\ledger belongs to the same ledger module.
namespace app\ledger;

//
// Import the namespace, not the symbol:
// the origin stays visible at each call site.
//
use app\store;

//
// Since PHP doesn't have module level state, we
// choose to emulate it like this. Inside any
// function in this module, you can access it via
// module::$state. You may of course add more
// properties if you deem it to be necessary.
//
final class module
{
    static int $state = 0;
}

readonly class AccountId
{
    public function __construct(
        public int $value
    ) {}
}

class Account
{
    public function __construct(
        public AccountId $id,

        #![internal]
        public int $balance_cents,
        #![internal]
        public bool $frozen = false,
    ) {}
}

enum AccountError
{
    case NotFound;
    case Frozen;
    case InvalidAmount;
    case InsufficientFunds;
    case Unavailable;
}

readonly class LoadResult
{
    public function __construct(
        public ?Account      $account = null,
        public ?AccountError $error   = null,
    ) {}
}

readonly class DebitResult
{
    public function __construct(
        public ?Account      $account = null,
        public ?AccountError $error   = null,
    ) {}
}

readonly class TransferResult
{
    public function __construct(
        public bool          $settled = false,
        public ?AccountError $error   = null,
    ) {}
}

enum LoadError
{
    case NotFound;
    case Frozen;
    case Unavailable;
}

function account_load(store\Db $db, AccountId $id): LoadResult
{
    $row = store\account_load($db, $id->value);
    if ($row->error !== null) {
        return new LoadResult(error: error_from_store($row->error));
    }

    $account = new Account($id, $row->balance_cents, $row->frozen);
    return new LoadResult(account: $account);
}

#![internal]
function error_from_store(store\LoadError $error): AccountError
{
    return match ($error) {
        store\LoadError::Missing => AccountError::NotFound,
        store\LoadError::Locked  => AccountError::Frozen,
        store\LoadError::Down    => AccountError::Unavailable,
    };
}
```

## Definition

A module is one namespace. It owns a family of related types and the operations over them. It is a subsystem, not a single type.

## Naming

Name a module for the prefix it becomes.

- One short noun, `snake_case`: `ledger`, `store`, `http`, `cpu`.
- The subsystem, not a type: `ledger`, not `account`.
- Reads as a prefix: `store\account_load()`, `store\Account`.
- No stutter: not `app\ledger_module`, not `ledger\ledger_transfer`.

The module names the namespace segment only. Types and operations within it follow their own naming.

## Layout

A module is a directory of files in one namespace. Nesting expresses sub-modules.

```
src/
  app/
    http/
      request.php
      response.php
      router.php
    ledger/
      account.php
      transfer.php
    store/
      account_store.php
      order_store.php
    payment/
      stripe/
        charge.php
      paypal/
        charge.php
    util/
      clamp.php
      uuid.php
```

## Visibility

| Marker | May be referenced by |
| --- | --- |
| (unmarked) | any module |
| `#![internal]` | the declaring module only |
| `#![local]` | the declaring file only |

Visibility applies to free functions and to types (class, enum, interface, trait) alike.

## Imports

Import the namespace, not the symbol. The prefix names the origin and stays
greppable. Import the full name only where it reads better.

```php
use app\util;

use app\{
    config,
    env,
    http,
};
```

## Dependency graph

Modules form a directed acyclic graph.

## Boundaries

Where a boundary falls, three questions:

- Name. One noun? If it needs an "and", it is two modules.
- Change. One reason to change? Two reasons, two modules.
- Dependency. Downward only? If two candidates reference each other, they are one module.

An honest boundary is:

- Self-contained. Everything external arrives through a signature.
- One-directional. Dependencies point down the graph only.
- Stable. The public surface changes only when the contract does.

If you cannot say what a module does in one line, it owns more than one noun.