<!-- derived from ../docs/MODULES.md — terse rules only; edit the source, then regenerate -->

## quick-rules

- Define a module as one flat namespace word under a vendor root; mirror it on disk.
- Import the namespace, not the symbol; keep the prefix at the call site.
- Mark internals `#[Internal]`, file-locals `#[Local]`; leave public unmarked.
- Load every module/file at startup; do not use PSR-4 lazy-loading.

## what-a-module-owns

- Own a family of types and the functions over them in one module.
- Err coarse: make a module a subsystem, not a single type; split only when a real subsystem emerges.

## naming

- Name the module for the prefix it becomes: one short noun, `snake_case` (`ledger`, `store`, `http`, `cpu`).
- Name the subsystem, not a type: `ledger`, not `account`.
- Ensure it reads as a prefix: `store\account_load()`.
- Forbid dumping grounds (`common`, `shared`, `helpers`, `misc`, `core`); allow `util` as the one exception.
- Avoid stutter: not `app\ledger_module`, not `ledger\ledger_transfer`.

## layout-on-disk

- Place a module as `vendor\module`, one flat word below the root; mirror the namespace as the directory.
- Treat another `\` as another module, not a submodule, unless it is a group tier.
- Keep modules flat; add a directory only when a real module emerges.
- Call sibling files in one module unqualified.
- Group three or more modules of the same kind one tier deep: `app\payment\stripe`.
- Treat the group as a directory, not a module: no behavior, no shared visibility across siblings.
- Keep group call sites flat: `use app\payment\stripe;` then `stripe\charge()`.

## imports

- Import the namespace: `use app\util;` then `util\clamp()`.
- Forbid `use function` and `use const`.
- Allow class imports: `use app\ledger\Account;`.
- Alias only to break a collision, keeping the origin: `use acme\json as acme_json;`.
- Do not alias to a short token (`use app\ledger as l;`).
- Treat `#[Internal]` as the default for module-private symbols, `#[Local]` for file-private, unmarked as public.

## module-boundaries

- Split into two modules when the name needs an `and`, or when there are two reasons to change.
- Merge two candidates that reference each other into one module.
- Point dependencies down the DAG only.
- Pass everything from outside through a signature: data as arguments, capabilities through `$env`; no reaching into internals, no globals.
- Change the public surface only when the contract does.
