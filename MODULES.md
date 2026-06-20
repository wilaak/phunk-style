# Code organization

The unit of modularity is the namespace, not the class. A module is a namespace
plus the data it operates on: structs of public typed fields, behavior as free
functions taking the struct first. Call sites read as a verb on data:
`cpu\step($cpu)`, `bus\read8($bus, $addr)`.

This covers how code is wired: modules, files, visibility, the loader, the DAG,
the environment. The assembly-line thinking it rests on, and local naming
conventions, are in [README.md](README.md); hot-path rules in
[OPTIMIZATIONS.md](OPTIMIZATIONS.md).

## Rules at a glance

- A module is a namespace: one flat word under a vendor root, flat by default.
  The directory mirrors it.
- Data is public typed fields; behavior is free functions taking the struct
  first, prefixed with its type name.
- Import the namespace, not the symbol; keep the prefix at the call site.
- Mark internals `#[Internal]`, file-locals `#[Internal(Scope::File)]`; unmarked
  is public.
- Pure functions take values and no `$env`; effectful code holds `$env` at the
  edge.
- Concrete types over interfaces until two implementations exist.
- Wire dependencies by hand at the composition root; no container, no autowiring.
- Load every file at startup with one recursive loader. No PSR-4.

## Modules

A module is the namespace that owns a family of data types and the operations over
them. The data is its identity and the functions follow: `ledger` owns `Account`
and `TransferStatus`, and `account_debit` lives there because `Account` does. A few
modules own operations rather than types, a cohesive set of functions over
primitives or foreign types (`util`, an `http` edge owning only its mapping);
modules too, by the same tests.

Err coarse. A module is a cohesive subsystem, not a single type: `ledger` holds
`Account`, `Transfer`, and `TransferStatus` across several files, not a namespace
each. A namespace per class is the over-fragmentation to avoid, since it breeds the
wiring sprawl a container exists to manage. As coarse as stays one cohesive
subsystem; split only when a genuinely independent one emerges.

Name a module for its role as the prefix on every call into it (`ledger\transfer`):

- One short noun, `snake_case`, the subsystem: `ledger`, `store`, `http`, `cpu`.
  It prefixes hundreds of call sites; a long name is repeated noise.
- Name the subsystem, not a type: `ledger`, not `account`, though it owns
  `Account`. The module prefix carries the subsystem, the function prefix the type
  (`account_debit`); they need not match.
- It must read as a prefix: `store\account_load()`, `http\request_parse()`.
  Awkward pairing means the name is wrong.
- No dumping grounds (`common`, `shared`, `helpers`, `misc`, `core`); `util` is the
  one tolerated operation-module name.
- No stutter or vendor echo: not `app\ledger_module`, not `ledger\ledger_transfer`.

Three questions decide a boundary, each answerable without taste:

- Name. One noun, the type family it owns? If the name needs an `and`, two modules.
- Change. One reason to change? Two reasons, two modules.
- Dependency. One node in the DAG, depending downward only? If two candidates
  reference each other, they are one module, or the shared types belong below both.

The grains, smallest to largest:

- File: a cut inside a module, not a wall. Functions move freely between files.
- Module: a namespace owning one type family or operation set. The unit of the DAG.
- Group: a directory clustering three or more sibling modules of one kind. No
  code, no semantics.
- Package: one or more modules that lift out together with their own config and
  deps. The unit of reuse.

A module is a namespace under a vendor root, `vendor\module`; the vendor root
avoids collisions with third-party code. Each module is one flat word below it:
`app\cpu`, `app\ledger`. Past `vendor\module`, another `\` means two modules, not a
submodule, unless it is the grouping tier. The directory mirrors the namespace
(`app\cpu` in `app/cpu/`); the loader requires files recursively, so the folder
matches the module name.

A module is a flat directory of files, all in the same namespace:

```
src/
  loader.php            // requires every file in src/ recursively
  app/
    cpu/
      cpu.php           // namespace app\cpu;  the Cpu struct, cpu_new()
      decode.php        // namespace app\cpu;  cpu_decode()
      execute.php       // namespace app\cpu;  cpu_execute()
    bus/
      bus.php           // namespace app\bus;
    ledger/
      account.php       // namespace app\ledger;
      transfer.php      // namespace app\ledger;
```

Many files in one directory is the goal. A tree of near-empty directories hides the
module and adds path noise; add a directory only when a genuinely independent
module emerges.

Flat is the default, not absolute. Three or more independent modules of the same
kind (codecs, hashes, backends) may share one grouping tier: `app\encoding\json`,
`app\encoding\csv`. The group is a directory, not a module, with no functions,
classes, or constants, no behavior, and sharing it grants no visibility
(`app\encoding\json` cannot reach `app\encoding\csv`'s `#[Internal]` symbols). Call
sites stay flat: `use app\encoding\json;` then `json\decode()`, never an
`encoding\json\` chain. Group horizontal families (one noun in variations); keep
vertical layers flat: `app\api`, `app\ledger`, `app\store` stack in the DAG, not
the path, never `app\domain\ledger`. One tier only. If you cannot name the family
as N variations on one noun, it is not a group.

Files in one module call each other unqualified. The file boundary is where you
cut, not a wall: a growing file becomes more files in the same namespace, never a
new module. Reserve a new namespace for genuinely independent code.

This is Odin's package model in PHP. A module is Odin's package: a directory
sharing one `namespace`, imported by path, referenced by prefix, default-public
with `#[Internal]`/`Scope::File` mirroring `@(private)`/`@(private="file")`; free
functions over structs and the flat layout are the same. PHP forces three
differences. Import and loading are decoupled: `use` only aliases, so a separate
loader does what Odin's compiler does from `import`. Visibility is not
language-enforced; the attribute is inert until a linter checks it. A PHP "struct"
is a heap class, not a value type, so the dense layouts in
[OPTIMIZATIONS.md](OPTIMIZATIONS.md) recover by hand what Odin gives natively. We
add the no-cycle DAG (Odin permits cycles), type-prefixed function names, and an
explicit `$env` where Odin threads an implicit `context`.

## The loader

The application runs long-lived, so loading is a one-time startup cost. No PSR-4,
no lazy per-class loading. One file requires every source file once, recursively:

```php
<?php

// Requires every PHP file under this directory, recursively. Used in favor of
// PSR-4: dependencies resolved once at startup, not lazily per class on a hot path.

$directory = __DIR__;

$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if ($file->getExtension() === 'php' && $file->getRealPath() !== __FILE__) {
        require_once $file->getRealPath();
    }
}
```

Load order is irrelevant: module files only declare symbols, none execute or read
another module at require time, so a file may reference a symbol declared later.
Two things would break this, both already disallowed:

- Class inheritance across files: `class B extends A` fails if `A` is not yet
  loaded. The style prefers flat data and composition (see "No premature
  abstraction").
- Top-level executable code: modules declare, they do not run. Ordered bootstrap
  belongs in the entry point.

Require the loader once at the entry point, then wire and run.

## Imports

Import the namespace, not the symbol: `use app\util;` then `util\clamp()`. The
prefix names the origin and keeps the API greppable; `use function` and `use const`
strip it and are forbidden. Class imports (`use app\ledger\Account;`) are fine,
as the class name still carries meaning.

```php
// bad: symbol import strips the origin
use function app\util\clamp;
$v = clamp($x, $lo, $hi);

// good: namespace import keeps the prefix
use app\util;
$v = util\clamp($x, $lo, $hi);
```

Group imports sharing a vendor in one multi-line `use`, one per line, trailing
comma:

```php
use app\{
    config,
    env,
    api,
    http,
};
```

Reserve the group form for two or more from one vendor; a single import stays one
line.

Alias only to resolve a collision, never to shorten; a vanity alias hides the
origin the prefix exists to show. Two cases warrant `as`:

- Leaf-name collision: `use acme\json as acme_json;`, keeping the origin in the
  alias, then `acme_json\decode()`.
- Class-name collision: `use app\ledger\Account as LedgerAccount;`.

Alias inside a group the same way, origin-revealing so it stays greppable:

```php
use app\{
    ledger,
    store as ledger_store,
};
```

Never alias to a short arbitrary token (`use app\ledger as l;`); that strips
context like `use function`.

PHP has no module-private visibility, so one attribute adds it with a `Scope` enum:

```php
namespace app;

enum Scope
{
    case Module;   // visible to every file in the namespace, invisible outside
    case File;     // visible only in the declaring file
}
```

- `#[Internal]`: module-private, the default, visible to every file in the
  namespace, invisible outside.
- `#[Internal(Scope::File)]`: file-private. Rare.

`Scope::Module`, not `Scope::Package`: a module is one namespace, the unit this
scopes to; a package (the liftable cluster of modules) is a different grain.
Unmarked is public API. One attribute models visibility as a single ordered axis:
the linter reads the scope and `match`es, and a new level later is one more case.
The attribute is a token-linter marker, never reflected, so it needs no backing
class; the linter fails the build on a boundary-crossing call at zero runtime cost.

## Module boundaries

A module advertises how to use it. The public surface is small by construction:
the unmarked symbols, everything else `#[Internal]`. The primary file (named after
the module) opens with a terse header:

- Purpose: the one noun it owns, in a sentence.
- Public API: the unmarked entry points, grouped by type.
- Dependencies: the modules below it in the DAG. None for a leaf.

```php
namespace app\ledger;

//
// Ledger: accounts and transfers between them.
//
// Public:
//   account_new, account_debit, account_credit, account_can_debit
//   transfer            move funds between two accounts (pure)
// Depends on: nothing (leaf, functional core)
//
```

The header is the contract; the `#[Internal]` attributes enforce it, and the linter
is the source of truth when they drift. Keep it a map, not prose.

Three checkable properties make a boundary honest:

- Self-contained. Everything from outside arrives through a signature: data as
  arguments, capabilities through `$env`. No reach into another module's
  `#[Internal]` symbols, no global mutable state.
- One direction. The dependency list points down the DAG only; a name in two
  modules' lists pointing at each other is a misplaced boundary.
- Stable surface. The public list changes only when the contract does; refactoring
  internals never touches the header.

A header you cannot write in three lines means the module owns more than one noun.

## Data as structs

PHP has no struct type, so a `final class` of public typed fields stands in for
one. It is a record, not an object: just fields passed by reference, the way the
languages this style draws from use structs (see [README.md](README.md), "Why not
just use classes?"). The class only names a record type the IDE understands.

PHP passes object handles, so this needs no ceremony: a struct given to a function
is the caller's instance, and mutating its fields is visible to the caller, like
passing a struct by reference, with no `&` needed (reserve `&` for reassigning the
variable or for scalars and arrays; see [OPTIMIZATIONS.md](OPTIMIZATIONS.md)). Pass
the struct first instead of binding behavior into methods.

This is for the few module-level instances with identity and a lifecycle, not bulk
data in hot loops; for that a class is the wrong tool, so pack fields into arrays or
a byte buffer ([OPTIMIZATIONS.md](OPTIMIZATIONS.md)).

```php
namespace app\ledger;

#[Internal]
final class Account
{
    public int $id            = 0;
    public int $balance_cents = 0;
}

function account_new(int $id): Account
{
    $account     = new Account();
    $account->id = $id;
    return $account;
}

function account_deposit(Account $account, int $amount_cents): void
{
    assert($amount_cents > 0);
    $account->balance_cents += $amount_cents;
}
```

Prefix functions with the type name so grepping `account` returns the API. Never
bind behavior into methods or hide fields behind accessors:

```php
// bad: behavior in methods, state behind getters and setters
final class Account
{
    private int $balance_cents = 0;
    public function deposit(int $cents): void { $this->balance_cents += $cents; }
    public function balance(): int { return $this->balance_cents; }
}

// good: public typed fields, free function in the module namespace
final class Account
{
    public int $balance_cents = 0;
}
function account_deposit(Account $account, int $amount_cents): void
{
    $account->balance_cents += $amount_cents;
}
```

Prefer free functions for behavior over your own data, a preference, not a
prohibition. PHP is an object language; do not fight that, least of all at the
boundary. Reach for a method where it is the idiom or reads better:

- Framework/library integration: a Swoole server, a PSR interface, where you call
  `$server->on(...)` because that is the API.
- Genuinely object-like state: a connection, a buffer, an open file, an iterator,
  a few instances with a lifecycle, not bulk data.
- Small value objects, where one or two natural methods carry no hidden state.

The rule targets where domain behavior lives (free functions over structs, not
getters and setters), not the `->` operator. A class of public fields with the
occasional method is fine; one that hides fields and scatters logic across methods
is the thing to avoid. Convenience sometimes wins; spend the exception where it pays.

## Functional core, imperative shell

Split the program into a pure core and a thin effectful shell. The core decides,
the shell acts. The core is the leaf modules: values in, values out, no `$env`, no
I/O, deterministic and testable. The shell is the edge (handlers, workers, CLI)
that reads the world, calls the core, and writes the result.

Signal the world through `$env`, not naming: a pure function never receives `$env`;
its presence marks the shell. Between the two sits a function that mutates its
arguments and nothing else: not pure, but the effect is in the signature
(`account_deposit`, the out-parameter pattern).

The shape is gather, decide, commit: read every input up front, hand plain values
to the core, take the decision back, then write. No I/O interleaved with logic.

```php
// shell: reads inputs, calls the pure core, writes outputs. Holds $env.
function transfer_handle(env\Env $env, transfer\Request $request): transfer\Result
{
    $from = store\account_load($env->db, $request->from_id);   // gather
    $to   = store\account_load($env->db, $request->to_id);

    $status = ledger\transfer($from, $to, $request->amount_cents);   // decide (pure)
    if ($status !== ledger\TransferStatus::Ok) {
        return transfer\reject($status);
    }

    store\account_save($env->db, $from);   // commit
    store\account_save($env->db, $to);
    return transfer\accept();
}
```

Keeping the decision pure makes it testable without a database and keeps
irreversible writes in one auditable place. No globals; shared infrastructure
travels through `$env`. Pass a function only the data it needs.

A recommendation, not a rule: prefer commands to return `void` and queries to be
pure. The exception stays welcome: an operation that both mutates and reports, a
command-query. `ledger\transfer` mutates two accounts and returns a
`TransferStatus`; `account_save` writes and returns whether it committed. Each is
honest because every effect is in the signature. What it bans is the dishonest
case: a hidden effect behind something shaped like a query.

Modules form a DAG: shell modules sit above core and call down. Two siblings never
import each other; an operation needing both belongs in the layer above.

## Application environment

For application-wide dependencies (database, logger, clock, config), use a
`readonly` class built once at startup, the justified exception to avoid-objects:
one instance, never iterated.

```php
namespace app\env;

use app\clock\Clock;

final readonly class Env
{
    public function __construct(
        public \PDO                     $db,
        public \Psr\Log\LoggerInterface $logger,
        public Clock                    $clock,   // now(): \DateTimeImmutable; stubbed in tests
        public Config                   $config,
    ) {}
}
```

`$clock` is a one-method `Clock` interface, not a `\Closure`: it carries a real
signature and stubs in tests (see "No premature abstraction"). Build `$env` at the
composition root and pass it to the outermost layer that needs it; inner functions
receive only what they need, extracted at the call site.

## Cross-container operations

When one operation coordinates two containers from the same module (a transfer
between two accounts), give it a function taking both as explicit arguments, in the
module that owns the types. An operation spanning two modules belongs in the layer
above both, never inside either.

## No premature abstraction

Do not extract an interface or base class over data or a service until a concrete
limitation forces it. One implementation needs none; the JIT devirtualizes a call
it proves single-target. Add an interface only when two implementations must be
interchangeable, or a boundary needs mocking in tests.

Function-typed parameters cut the other way. PHP has no type for a function
signature (`\Closure` and `callable` accept any arity and return), so a
callback's contract escapes the type system, the IDE, and the analyzer. A
single-method interface *is* that signature, named and checkable; for a contract
callback it is the only way to type it:

```php
// bad: the parameter types carry no signature; the contract lives in a comment
function schedule(\Closure $clock, callable $on_due): void { /* ... */ }

// good: single-method interfaces type what \Closure cannot, and stub in tests
interface Clock
{
    public function now(): \DateTimeImmutable;
}
interface DuePolicy
{
    public function __invoke(Account $account, int $now_unix): void;
}
function schedule(Clock $clock, DuePolicy $on_due): void { /* ... */ }
```

A one-method interface is also right for a mockable capability such as a clock or
an HTTP client, the "boundary needs mocking" case.

Reserve `\Closure` for a local callback whose signature is obvious and not a
published contract, with a `\Closure(...)` docblock so PHPStan checks it. `callable`
is weakest (it also accepts strings and arrays); avoid it. On a hot path the choice
is moot: drop the per-element callback and inline the work
([OPTIMIZATIONS.md](OPTIMIZATIONS.md)).

## Composition root

The composition root is the `main()` of an executable: the one impure function, in
one file, where the module graph is assembled by hand. It alone reads the
environment, calls `new`, and chooses implementations. Everything below receives
what it needs through signatures. One root per executable.

Because the root *is* the dependency graph written out, it doubles as the map you
explore from. Two load-bearing conventions:

- One canonical entry file: `server.php` at the root, `bin/<name>` per worker. A
  reader knows where to start.
- DAG order, top to bottom: config, then leaf capabilities, then `$env`, then the
  shell. Reading top-down reads the dependency layering.

Two more: the `use` block is the index of what the app is composed of; no logic in
the root, only construction and wiring. With the module-boundary headers, the root
shows the graph top-down and each header its deps bottom-up, so you walk the app with
no container, no runtime resolution.

Parse config once, here: read the raw environment (`getenv()`, `$_ENV`, a file) and
validate into a typed `Config`, so a bad value fails at startup, not mid-request.

The whole wiring is the entry file, read top to bottom:

```php
require __DIR__ . '/src/loader.php';

use app\{
    config,
    clock,
    env,
    api,
    http,
};

// Composition root. Runs once, at process start.

// 1. Parse config once; a bad value fails the process here, not mid-request.
$config = config\from_env();

// 2. Build the long-lived capabilities, the "singletons": plain variables,
//    constructed once, held for the life of the process.
$db        = new \PDO($config->dsn, $config->db_user, $config->db_pass, [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);
$logger    = new \Monolog\Logger('app');
$the_clock = new clock\SystemClock();   // a test wires clock\FixedClock instead

// 3. Assemble the app-wide environment, shared across every request.
$env = new env\Env($db, $logger, $the_clock, $config);

// 4. Serve. $server is a framework object; call its method API as given. Keep the
//    closure a thin adapter; the logic lives in a named free function.
$server->on('request', static fn ($req, $res) => api\serve($env, $req, $res));
```

The handler is an ordinary shell function: gather the per-request value, call the
core, commit. The shared `$env` is passed down explicitly; per-request state is
built here and never stored on `$env`.

```php
namespace app\api;

use app\{
    env,
    http,
};

// Shell handler. The framework closure above is just the adapter into this.
function serve(env\Env $env, \Swoole\Http\Request $req, \Swoole\Http\Response $res): void
{
    $request  = http\request_parse($req);     // edge: framework type -> our Request
    $response = handle($env, $request);        // assembly line: Request -> Response
    http\response_write($res, $response);      // edge: our Response -> framework type
}
```

`$server->on('request', ...)` is inversion of control: the framework owns the loop.
Swoole's HTTP server gives no other shape, so keep the callback a one-line adapter
and the inside an explicit value assembly line. `serve` parses the framework request
into our `Request`, runs it to a `Response`, writes back, with `\Swoole\Http\*`
confined to its signature; below `serve` everything sees `Request` in, `Response`
out: `response = handle(request)`. When you own the loop instead, write a loop, not
a callback, and for a stateful service make it a batched tick. That architecture,
and the monitoring and rate limiting that fall out of it, is in
[README.md](README.md), "A loop at the center".

Selecting an implementation is constructing a different value at step 2: a real
`\PDO` or a fake, `clock\FixedClock` instead of `clock\SystemClock`. The handler is
unchanged, since it took the capability through its signature (the `Clock`
interface), so swapping the value swaps the behavior. No registry, no reflection.

One hazard the long-lived process adds: shared mutable state across requests. `$env`
is built once and shared by every request (and every concurrent coroutine), so it
holds only capabilities, never request data. Per-request values (parsed input, a
request id, a transaction) are built in the handler and threaded down. Keep `$env`
`readonly` so the compiler enforces it: a singleton you cannot mutate cannot carry
per-request state.

### Past one application

The single `$env` is right for one application. As packages multiply, do not grow
it into a god object; each package exports its own narrow pair:

- `Config`: pure data, serializable, loadable from a file. URLs, ports, timeouts,
  flags.
- `Deps`: capabilities, constructed in code. A database handle, a clock, an HTTP
  client.

A package is self-contained when extractable: no reach into another's internals, no
global state, everything arriving through its signatures. When wiring grows long,
give each package one `pkg\boot(Config): Deps`, so the root stays a linear list of
`$x = pkg\boot($config)` calls, still plain code, never a container.

## Replacing the DI container

A container does three jobs in a request-per-process world: build the object graph
(autowiring), manage lifetimes (singleton, scoped, transient), resolve by interface
(service location). The composition root covers all three with plain code, no
reflection:

| Container concept              | Here                                                  |
| ------------------------------ | ----------------------------------------------------- |
| Autowiring / graph construction| Plain `new` at the composition root, top to bottom    |
| Singleton lifetime             | A variable built once at boot, held for process life  |
| Scoped (per-request) lifetime  | A value built in the request handler, passed down     |
| Transient lifetime             | Call the constructor where you need it                |
| Resolve by interface           | Construct the chosen concrete value at the root       |
| Lazy instantiation             | Eager; a long-lived process pays construction once    |
| Service location               | Pass the value through the signature                  |

The insight a container works around is that a request-per-process runtime rebuilds
the world each request, so it needs lifetime rules and lazy graphs to amortize
that. A long-running process does not: build the graph once, hold it for the
process life, and "singleton" collapses into an ordinary variable. Nothing to
amortize, nothing to resolve at runtime.

Deeper, a container treats a symptom. The wiring it automates grows with the object
count, and that count is a design choice. The module-as-namespace style keeps it
low: a fat struct with free functions is one node where idiomatic OOP has a dozen
objects, so the graph wires in plain code at the root, with no container to hide the
sprawl. Why fine-grained objects cost more than they save is in
[README.md](README.md), "Why not just use classes?".
