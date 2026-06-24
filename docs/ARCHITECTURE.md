# Architecture

How modules compose into a running application: the core/shell split, the
dependency DAG, and where the graph is wired. For the module unit itself
(namespaces, files, imports, visibility) see [MODULES.md](MODULES.md).

## Rules at a glance

- Split into a pure core and a thin effectful shell; the shell holds `$env`, the
  core takes values.
- Modules form a DAG; depend downward only, siblings never import each other.
- Concrete types over interfaces until two implementations exist.
- Wire dependencies by hand at the composition root; no container, no autowiring.

## Functional core, imperative shell

The core decides, the shell acts. The core is the leaf modules: values in, values
out, no `$env`, no I/O, deterministic and testable. The shell is the edge
(handlers, workers, CLI) that reads the world, calls the core, and writes the
result.

Signal the world through `$env`, not naming: a pure function never receives `$env`;
its presence marks the shell. Between the two sits a function that mutates its
arguments and nothing else — not pure, but the effect is in the signature
(`account_deposit`, the out-parameter pattern).

The shape is gather, decide, commit: read every input up front, hand plain values
to the core, take the decision back, then write. No I/O interleaved with logic.

```php
// shell: reads inputs, calls the pure core, writes outputs. Holds $env.
function transfer_handle(env\Env $env, transfer\Request $request): transfer\Result
{
    $from = store\account_load($env->database, $request->from_id);   // gather
    $to   = store\account_load($env->database, $request->to_id);

    $status = ledger\transfer($from, $to, $request->amount_cents);   // decide (pure)
    if ($status !== ledger\TransferStatus::Ok) {
        return transfer\reject($status);
    }

    store\account_save($env->database, $from);   // commit
    store\account_save($env->database, $to);
    return transfer\accept();
}
```

A pure decision is testable without a database and keeps irreversible writes in one
auditable place. No globals; shared infrastructure travels through `$env`. Pass a
function only the data it needs.

Prefer commands to return `void` and queries to be pure. A command-query that both
mutates and reports is welcome when every effect is in the signature
(`ledger\transfer` mutates two accounts and returns a `TransferStatus`). What this
bans is a hidden effect behind something shaped like a query.

Modules form a DAG: shell sits above core and calls down. Two siblings never import
each other; an operation needing both belongs in the layer above.

## Cross-container operations

One operation coordinating two containers from the same module (a transfer between
two accounts) gets a function taking both as explicit arguments, in the module that
owns the types. An operation spanning two modules belongs in the layer above both,
never inside either.

## Fans and chains

Gather/decide/commit says read inputs up front — but when the inputs are themselves
the product of I/O, how do you read them? Batch by dependency, not by kind. Two
reads being the same operation (both queries) is not a reason to batch; being
independent of each other is.

A **fan** is a set of reads that do not depend on each other: you know every key up
front. Issue them together and await once, so the gather stays a single auditable
edge and the cost is the slowest read, not their sum. The decide step then sees the
whole batch.

```php
// fan: every id is known up front, so gather them in one await
$accounts = co\map($ids, fn ($id) => store\account_load($env->database, $id));

$totals = slice\map($accounts, ledger\account_total);   // decide, over the batch
store\account_save_all($env->database, $accounts);       // commit
```

Spreading those awaits inline (`load, use, load, use`) costs the sum of every
latency and smears the I/O boundary across the function.

A **chain** is a read whose key comes from the previous read: you cannot know step
N+1 until step N resolves. A chain cannot be batched, so do not try. The boring
linear form is the most readable form here; forcing it into stages only hides the
dependency:

```php
// chain: each read names the next; sequential is correct, not a smell
$manifest = store\manifest_load($env->database, $root_id);
$head     = store\account_load($env->database, $manifest->head_id);
$settled  = store\account_load($env->database, $head->settled_into_id);
return ledger\reconcile($settled);
```

Push the fan down into one gather, keep the chain local and linear. Most real
gathers are a short chain of fans — resolve one set, use it to compute the next set
of keys, fan those.

### Chains across a loop

A chain inside one request handler is just sequential code. A chain inside a
long-running loop, where blocking on a slow read stalls every other item, is
different: there you rotate the chain onto the loop's time axis. Instead of one
suspended call stack per item parked at `read → wait → use`, each item carries a
flag for the stage it is on, and every tick advances the whole population one stage.
The conveyor belt becomes literal — each belt segment is a set, each tick moves
items between segments, every item at a segment is processed in bulk.

The working sets are the loop's own mutable state, so they live in a `Loop` value
the loop owns, never on the `readonly` `$env`. `$env` carries capabilities; the
`Loop` carries the population moving through the belt.

```php
// each tick: advance every item one stage, in bulk per stage.
function tick(env\Env $env, Loop $loop): void
{
    // segment 1 -> 2: items whose read has landed move to the ready set
    foreach ($loop->awaiting_read as $id => $item) {
        if (!store\read_ready($env->database, $id)) {
            continue;
        }
        $item->data = store\read_take($env->database, $id);
        $loop->ready[$id] = $item;
        unset($loop->awaiting_read[$id]);
    }

    // segment 2 -> done: decide over the whole ready set at once
    foreach ($loop->ready as $id => $item) {
        $item->result = ledger\settle($item->data);   // pure decide
    }
    $loop->ready = [];
}
```

This is why the loop scales state: never a backlog of N parked stacks, only a
handful of sets and one pass that advances all of them. Reach for it only when
latency forbids blocking — a short chain in a request handler stays plain
sequential code.

## No premature abstraction

Do not extract an interface or base class until a concrete limitation forces it.
One implementation needs none; the JIT devirtualizes a call it proves
single-target. Add an interface only when two implementations must be
interchangeable, or a boundary needs mocking in tests.

Function-typed parameters cut the other way. PHP has no type for a function
signature (`\Closure` and `callable` accept any arity and return), so a callback's
contract escapes the type system. A single-method interface *is* that signature,
named and checkable:

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

Reserve `\Closure` for a local callback whose signature is obvious and not a
published contract, with a `\Closure(...)` docblock so PHPStan checks it. `callable`
is weakest (it also accepts strings and arrays); avoid it.

## Application environment

For application-wide dependencies (database, logger, clock, config), use a
`readonly` class built once at startup: one instance, never iterated.

```php
namespace app\env;

use app\clock\Clock;

readonly class Env
{
    public function __construct(
        public \PDO                     $database,
        public \Psr\Log\LoggerInterface $logger,
        public Clock                    $clock,   // now(): \DateTimeImmutable; stubbed in tests
        public Config                   $config,
    ) {}
}
```

`$clock` is a one-method `Clock` interface, not a `\Closure`: it carries a real
signature and stubs in tests. Build `$env` at the composition root and pass it to
the outermost layer that needs it; inner functions receive only what they need.

### Past one application

The single `$env` is right for one application. As packages multiply, do not grow
it into a god object; each package exports its own narrow pair:

- `Config`: pure data, serializable, loadable from a file. URLs, ports, timeouts,
  flags.
- `Deps`: capabilities, constructed in code. A database handle, a clock, an HTTP
  client.

When wiring grows long, give each package one `pkg\boot(Config): Deps`, so the root
stays a linear list of `$x = pkg\boot($config)` calls.

The split is at the **package** boundary — a cluster of modules that lifts out as
its own reusable, separately-bootable unit (see [MODULES.md](MODULES.md)) — never
per-module. A `Deps` per module inside one app trades the god object for wiring
sprawl: most modules share the same handful of capabilities, so minting one env
each is ceremony with no payoff. Two cheaper tools handle the common case first:

- **Narrow the call, not the env.** Below the shell edge, functions take the one
  capability they use (`\PDO $database`, `Clock $clock`), not the whole `$env`. The
  env exists at the edge; deep in the core, nothing carries it.
- **Stay pure deeper still.** Core leaves take values and return values, so the
  question of which env they get never arises.

Reach for a package's own `Config`/`Deps` only when the cluster has an independent
reason to be reused or booted on its own.

## Composition root

The composition root is the `main()` of an executable: the one impure function, in
one file, where the module graph is assembled by hand. It alone reads the
environment, calls `new`, and chooses implementations. Everything below receives
what it needs through signatures. One root per executable.

- One canonical entry file: `server.php` at the root, `bin/<name>` per worker.
- DAG order, top to bottom: config, then leaf capabilities, then `$env`, then the
  shell. Reading top-down reads the dependency layering.
- The `use` block is the index of what the app is composed of; no logic in the
  root, only construction and wiring.

Parse config once, here: read the raw environment (`getenv()`, `$_ENV`, a file) and
validate into a typed `Config`, so a bad value fails at startup, not mid-request.

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
$database = new \PDO($config->dsn, $config->database_user, $config->database_password, [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);
$logger   = new \Monolog\Logger('app');
$clock    = new clock\SystemClock();   // a test wires clock\FixedClock instead

// 3. Assemble the app-wide environment, shared across every request.
$env = new env\Env($database, $logger, $clock, $config);

// 4. Serve. $server is a framework object; call its method API as given. Keep the
//    closure a thin adapter; the logic lives in a named free function.
$server->on('request', static fn ($req, $res) => api\serve($env, $req, $res));
```

The handler is an ordinary shell function: gather the per-request value, call the
core, commit. The shared `$env` is passed down explicitly; per-request state is
built here and never stored on `$env`.

```php
namespace app\api;

use app\env;
use app\http;

// Shell handler. The framework closure above is just the adapter into this.
function serve(env\Env $env, \Swoole\Http\Request $req, \Swoole\Http\Response $res): void
{
    $request  = http\request_parse($req);     // edge: framework type -> our Request
    $response = handle($env, $request);        // assembly line: Request -> Response
    http\response_write($res, $response);      // edge: our Response -> framework type
}
```

`$server->on('request', ...)` is inversion of control: the framework owns the loop.
Keep the callback a one-line adapter and the inside an explicit value assembly line,
with `\Swoole\Http\*` confined to `serve`'s signature; below `serve` everything sees
`Request` in, `Response` out. When you own the loop instead, write a loop, not a
callback.

Selecting an implementation is constructing a different value at step 2: a real
`\PDO` or a fake, `clock\FixedClock` instead of `clock\SystemClock`. The handler is
unchanged, since it took the capability through its signature. No registry, no
reflection.

One hazard the long-lived process adds: shared mutable state across requests. `$env`
is built once and shared by every request (and every concurrent coroutine), so it
holds only capabilities, never request data. Keep `$env` `readonly` so the compiler
enforces it: a singleton you cannot mutate cannot carry per-request state.

## Replacing the DI container

A container builds the object graph, manages lifetimes, and resolves by interface.
The composition root covers all three with plain code, no reflection:

| Container concept               | Here                                                 |
| ------------------------------- | ---------------------------------------------------- |
| Autowiring / graph construction | Plain `new` at the composition root, top to bottom   |
| Singleton lifetime              | A variable built once at boot, held for process life |
| Scoped (per-request) lifetime   | A value built in the request handler, passed down    |
| Transient lifetime              | Call the constructor where you need it               |
| Resolve by interface            | Construct the chosen concrete value at the root      |
| Lazy instantiation              | Eager; a long-lived process pays construction once   |
| Service location                | Pass the value through the signature                 |

A container works around a request-per-process runtime that rebuilds the world each
request, so it needs lifetime rules and lazy graphs to amortize that. A
long-running process does not: build the graph once, hold it for the process life,
and "singleton" collapses into an ordinary variable.
