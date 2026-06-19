<img alt="Phunk" width="150" src="./assets/phunk-text.svg">

---

> *PHP PHUNK coding style guide draft*

*Related docs: [MODULES.md](MODULES.md) for code organization, [OPTIMIZATIONS.md](OPTIMIZATIONS.md) for optimization tips.*

# Introduction

The primary motivation for this guide is to make it more practical to write larger and better performing stateful application servers with low latency and high throughput using PHP.

In this style we will favor free functions, use less abstractions and focus more on how we store and transform our data. We will use namespaces as modules and classes are used more sparingly instead of the default unit of abstraction.

For lack of a better term we will call it a procedural style: programs can be thought of as a set of assembly lines where data is loaded at one end with various stations along the line to manipulate it to the other end.

The data those lines carry is kept flat: plain arrays and records of values laid out together, reached by index or key, rather than a graph of objects wired to one another by references. Flat data is easy to see all at once, easy to move through the line in bulk, and, as it turns out, the layout the machine runs fastest on.

Structuring your code like this will make your computer have a better time understanding it and consequently its not unlikely that you will too.

# It's all about that data

If something is computable, it is ultimately a data transformation. Programs can be broadly categorized by the primary kind of data transformation they perform:

- Servers transform network requests into responses.
- Interactive apps transform state based on user input.
- Simulations and games transform state from input and clock ticks.
- Processing jobs transform arguments and file data into output, then exit.

That is essentially every program ever written, so writing a correct one is mostly a matter of transforming its data correctly. Some of those transformations are trivial; others are fiendishly complex, threading many steps and a great deal of state from input to output.

The assembly line is what keeps even the complex ones tractable: when correct input gives a wrong result, you bisect the line to find the stage at fault. This works recursively, too, since if A, B, and C are right but D is wrong, you bisect D's substages the same way.

It pays off twice over. Moving data through the line in bulk is also the shape the hardware runs fastest, since flat data processed in sequence beats scattered objects chased one at a time (see [Working with the machine](#working-with-the-machine)).

## Pipeline pitfalls

Nothing is a silver bullet, of course. The pipeline brings recurring pitfalls of its own, here are some:

- [Scattered state](#scattered-state)
- [Leaky functions](#leaky-functions)
- [Tangled data](#tangled-data)
- [Stretched context](#stretched-context)

### Scattered state

> [!TIP]
> Consolidate each value's mutations into as few stages as possible.

The more stages that can change a value, the more places you must search when it comes out wrong, and the more hidden purposes it quietly picks up as its meaning shifts from stage to stage.

Often this is just a matter of reordering the logic so the writes sit together. When they genuinely cannot, copy rather than mutate in place: a `slug` whose meaning drifts mid-pipeline becomes `slug` and a transient `slug_normalized`, two honest names instead of one overloaded one.

That is an extra name to track, but it tells the truth about what the value is.

### Leaky functions

> [!TIP]
> A function should touch only the state passed to it.

Reaching outside its arguments, to globals, I/O, or data shared by reference, makes its dependencies and effects invisible from the call site. For any function, take stock of whether it:

- reads or writes globals,
- reads from or writes to I/O,
- reads or writes data passed by reference.

For each, ask whether it is truly necessary: could the global be passed in, the file write be deferred to a later stage, the by-reference mutation be a returned value instead?

Full purity is a large demand with costs of its own. You gain most of the benefit one step down from it:

- A function accesses only what is passed to it: no globals.
- A function is passed only what it needs: an item or a field, not a whole collection, when the smaller thing will do.
- Core logic and I/O stay apart: logic does no I/O, and I/O carries no non-trivial logic.

 Loggers and allocators are stateful in the strict sense, but not in ways that corrupt your logic, so reaching for them as globals is fine.

### Tangled data

> [!TIP]
> Design the data on its own terms, before the code.

Data more complex than the problem requires is harder to understand, harder to change, and harder to keep mutation consolidated: if type A references type B, every touch of A drags in B, so you cannot manage them independently.

Designing data around code (see [Why not just use classes?](#why-not-just-use-classes)) is a major source of these needless links, fractures, and redundancies.

Working procedurally, you can interrogate a design before any code exists:

- Is this the most compact encoding of the information required? If not, is there a reason to denormalize?
- Are these linkages necessary? If so, can a key or index stand in for a pointer?
- Does this need to be stored at all, or can it be recomputed when needed?
- Can this hierarchy or graph be flattened into an array?

### Stretched context

> [!TIP]
> Some logic lives across runs, not inside one.

A pipeline reasons cleanly about a single run, but a program's hardest parts often span many: a long-running async task that blocks some interactions until it finishes, a scripted game sequence that plays out over many frames. This state fits in no single event handler or tick, so it needs an explicit home and careful thought.

It is also harder to test. A step debugger sees only one run; debugging across many needs recorded input you can replay, which few web frameworks or game engines offer out of the box.

Both pressures point the same way: holding state across runs, and replaying it to debug, both want a single place where the state lives and one point where it advances. A loop gives you exactly that.

## A loop at the center

> [!TIP]
> Run the pipeline on a fixed cadence, advancing all state in one place.

Think of the loop as the data pipeline given a heartbeat. A single run is stateless: data in, result out, nothing kept. Run it over and over against state you hold between passes, and you have a program that lives in time instead of forgetting itself after every request.

PHP's default is the opposite: a callback fires per request and forgets everything. That is fine when requests are independent, but a stateful server is not, and threading shared state through scattered callbacks is the smeared, hard-to-follow control flow the pipeline was meant to avoid.

Game engines settled this long ago with one loop at the center: each tick, gather the inputs that arrived, advance the whole state by one step, flush the outputs, repeat at a fixed rate. A server fits the same shape. Connections and messages buffer as they arrive, and the per-event callback shrinks to a thin adapter that only appends (see [MODULES.md](MODULES.md), "When you own the loop, write a loop").

The tick is then the gather, decide, commit pipeline a request handler uses, only batched and repeating:

```php
namespace app\world;

use app\{
    env,
    net,
};

//
// One tick: drain the input buffered since the last tick, advance the whole
// state by one step, flush the output. I/O only at the edges; the step is pure.
//
function world_tick(env\Env $env, World $world, float $dt): void
{
    $events = net\drain($env->net);    // gather: everything buffered since last tick

    world_step($world, $events, $dt);  // decide: advance the whole batch in one pass

    net\flush($env->net, $world);      // commit: write results back
}
```

The composition root owns the loop instead of handing it to the framework. The per-message callback only buffers; a fixed-rate timer runs the tick:

```php
// The per-event callback is a thin adapter: it appends, it never touches state.
$server->on('message', static fn ($conn, $msg) => net\buffer($env->net, $conn, $msg));

// 20 ticks per second. State advances here and nowhere else.
$tick_ms = 50;
Timer::tick($tick_ms, static fn () => world\world_tick($env, $world, $tick_ms / 1000));
```

Advancing all the state in one timed place pays off three ways.

It is easier to reason about: what happens to the state, and when, has one answer instead of being smeared across every handler. A tick is a pure step from old state plus this tick's inputs to new state, so it is testable without a live socket and replayable from recorded input, the record-and-replay [Stretched context](#stretched-context) asked for, falling straight out of the shape.

It pushes data the right way: inputs collect into a batch before anything touches them, so a tick processes many items in one pass instead of one per callback. That is the bulk, cache-friendly shape the layout rules reward (see [OPTIMIZATIONS.md](OPTIMIZATIONS.md)): stages over flat arrays, not a walk over an object graph.

And it gives one place to measure and bound the work: time a tick for latency, count its batch for throughput, both just counters. Rate limiting is a cap on work per tick, backpressure a bounded input buffer, statistics a tally per step. Monitoring, limiting, and metrics fall out of the structure instead of being bolted on.

This is one shape among several, not a mandate: a stateless request and response service is fine left reactive. Reach for the loop when state lives across events and the timing or the throughput is something you need to see and control, the real-time and simulation end of the four categories above.

## Working with the machine

A short aside on hardware, because it is the reason flat data and batches keep coming up. You do not need any of it to follow the rules, but it makes them feel less arbitrary.

Start with one fact: a CPU is fast and main memory is slow. Fetching a value from RAM can cost hundreds of times more than an arithmetic operation, so for a lot of real work the processor is not busy computing, it is waiting for data to arrive. Going fast is mostly a matter of keeping it fed.

To soften that gap, the CPU keeps a small, very fast scratch space close by: the cache. Think of it as your desk. What you are actively using sits on the desk where you can grab it instantly; everything else lives in a warehouse down the hall, which is main memory, and walking there to fetch something takes a while. The whole game is to keep what you need next already on the desk.

Two things help with that. First, memory does not arrive one value at a time. It comes in fixed chunks of around 64 bytes, called cache lines, so when you read one value its immediate neighbours come along for free. Data laid out next to data you will touch next is effectively fetched ahead of time.

Second, the CPU watches how you walk through memory. Read it in order and it notices, running ahead to pull the next lines onto the desk before you ask. This is the prefetcher, and it only works on predictable, sequential access. Jump around unpredictably and it gives up, and you pay the full warehouse trip on every miss.

Flat data plays to both. An array of like values sits contiguous in memory, streams through the cache, and lets the prefetcher run the whole way, and processing it as a batch keeps the CPU fed instead of stalling. A web of scattered objects does the opposite: to reach the next one you follow a pointer to wherever it happens to live, which nothing can predict, so each hop risks a cache miss. Working with the grain of the machine like this is what people mean by mechanical sympathy, and it is why this guide reaches for flat structures and bulk passes by default. The hot-path specifics live in [OPTIMIZATIONS.md](OPTIMIZATIONS.md).

## Why not just use classes?

> *Typical issues with the PHP status quo way of packaging things*

So far the case has been for what to do: think in pipelines, give a stateful program a loop. This section is the other half, why the PHP default works against both. PHP makes the class the default unit of nearly everything: the thing you model, the file you put on disk, the unit the autoloader pulls in. For short-lived request work that is fine. For a long-lived, high-throughput server it quietly works against you on two fronts: it scatters the structure of the program, and it scatters the data in memory.

Splitting a system into small, encapsulated units is good at some scale, but the idea gets pushed until everything is a unit and smaller is always assumed better. The premise is that a smaller unit is easier to get right, which is true on its own. What it forgets is that the correctness of a system lives in how its units fit together, not in each one alone. Concentrated complexity gets traded for scattered complexity, which is harder to follow, so the total goes up rather than down.

Conflating data types with modules makes this worse. When every type has to be its own unit, data and the code over it fracture across odd boundaries: a field is moved to another class because it does not fit the supposed responsibility of the first. The boundaries serve the rule, not the problem.

Object modeling also assumes the world comes pre-divided into neat types, and it rarely does. Real concepts overlap and shift, so you spend energy on where a method belongs and what to name the class that owns it, and the answer keeps moving as the program grows. When a file holds a single class, attention goes to the shape of the abstraction instead of the shape of the data, which is the part that matters: a program is a pipeline that transforms data. Designing the hierarchy first means committing to boundaries you cannot judge yet, since the real limits only show up once you build. The up-front structure is usually guesswork you later pay to undo. It is easier to let structure follow the data once you can actually see it.

The second problem is the drift away from sequential code and flat data. Small objects can do little alone, so they collect references to other objects, and getting anything done becomes a method calling a method calling a method. Data ends up cross-referenced into a graph with no fixed order to read. Code is easiest to follow as an assembly line: data in one end, a sequence of stages, a result out the other. When the wrong result comes out, you bisect the stages. A graph of objects calling each other has no such order to bisect.

Encapsulation can make this harder still. Hiding state inside objects is meant to protect it, but it also hides what any operation actually touches: work happens by sending a message to an object, which sends a message to another, so the real behavior is smeared across the graph instead of sitting in one place you can read. Concerns that cut across the object boundaries, like logging, validation, or transactions, have nowhere natural to live, so they get scattered or bolted on through more layers of indirection. A lot of methods end up doing no real work of their own, only passing the call further along.

### It fights the hardware

The structural cost is what you feel first, but there is a performance cost underneath it, and it is exactly the one [Working with the machine](#working-with-the-machine) described. A stateful server holds a lot of live data at once, and modelling each piece as an object with references to others spreads it across a graph of separate allocations, reached by pointer, one item at a time. That is the access pattern the hardware punishes: the prefetcher cannot follow it, every hop risks a cache miss, and each refcounted reference dirties a cache line just to be read or passed.

Keeping like data together flips all of it. The working set sits in flat structures you can inspect and pass over in bulk, so it both reads clearly and streams through the cache. The state ends up easier to reason about and faster to process, for the same underlying reason. A uniform batch is easier to split across cores too, and cross-cutting work like logging or validation becomes one stage over the whole set rather than something threaded through every object.

### How autoloading locked it in

If this object-first habit is so costly for long-lived work, why is it the PHP default? Partly history, partly the way code is loaded. PHP has, for most of its life, been shaped by its HTTP one request per process model, so the language was rarely pushed past short-lived work, where a class sits comfortably as both the thing being modeled (an `Order`, a `User`) and the unit that organizes and loads code.

PHP gained a real object model in PHP 5, just as object-oriented design was the dominant idea in the industry, and the conventions that grew up around it followed suit. PSR-0 and then PSR-4 standardized autoloading by mapping a fully-qualified class name to a file path: one class per file. Composer made that mapping the basis of the whole package ecosystem, so a dependency is shipped and loaded as a tree of classes.

This was useful, but it quietly fixed the class as the unit of code. Autoloading rewards one class per file, one file per class rewards many small classes, and the tooling assumes that shape from end to end. So fine-grained OOP persists in public PHP not because it wins on its merits, but because the defaults make anything else swim upstream.

The rest of the field settled elsewhere. Go, Rust, Zig, and Odin organize code by package or module: a directory of related code, built together, with plain data and free functions rather than a type per file. The unit is the namespace, not the class. None of them have objects in the OOP sense at all; behavior is a function that takes a struct by reference, and they get along without classes entirely. PHP can work the same way: treat a namespace as a module, load the source once at startup, and drop per-class autoloading entirely (see [MODULES.md](MODULES.md)).

Once you account for memory and lifetime like this, PHP handles work it was never thought suited for: persistent services, real-time systems, game servers. The aim is simply to make that practical, through better organization and the performance that follows.

# Actual Style Guide

## Naming things

Prioritize naming and flow: if code reads naturally, comments are mostly for non-obvious constraints.

### Casing

The casing rules have minimal ambiguity with clear boundaries for easier distinction.

| Construct                          | Casing            | Example              |
| ---------------------------------- | ----------------- | -------------------- |
| Variable                           | `snake_case`      | `$order_line`        |
| Property                           | `snake_case`      | `$created_at`        |
| Parameter                          | `snake_case`      | `int $row_count`     |
| Namespace                          | `snake_case`      | `app\order_book`     |
| Class, interface, trait, enum      | `PascalCase`      | `OrderLine`          |
| Enum case                          | `PascalCase`      | `Status::Active`     |
| Method                             | `camelCase`       | `getTotal()`         |
| Free function                      | `snake_case`      | `order_total()`      |
| Constants                          | `UPPER_SNAKE_CASE`| `MAX_SIZE`           |
| Goto label                         | `UPPER_SNAKE_CASE`| `PARSE_END`          |

### Subject-prefixed functions

Place free functions in a module namespace, then prefix each function with its subject type so APIs stay grouped by module and by name.

If splitting a large function into more readable parts; keep single-caller helpers grouped under their parent caller by prefixing with the parent name.

**Function Scope Tips**:

- Single caller: keep parent prefix (`account_import_validate_rows`) and mark `#[Internal(Scope::File)]`.
- Multiple callers in one module: drop the parent prefix (`account_validate_rows`) and mark `#[Internal]`.
- Callers across modules: make it a public module API and keep a clear subject prefix.

For more about modules, see [MODULES.md](MODULES.md).

```php
namespace app\ledger;

//
// In module app\ledger, account_* reads as one family and stays greppable.
//
final class Account
{
    public int $id = 0;
}

function account_can_debit(Account $account, int $amount_cents): bool
{
    return $amount_cents >= 0;
}

function account_import(array $rows): int
{
    $rows_validated = account_import_validate_rows($rows);
    return account_import_persist_rows($rows_validated);
}

#[Internal(Scope::File)]
function account_import_validate_rows(array $rows): array
{
    //
    // Scoped to account_import: keep the parent prefix for locality.
    //
    return $rows;
}

#[Internal(Scope::File)]
function account_import_persist_rows(array $rows): int
{
    //
    // Scoped to account_import: keep the parent prefix for locality.
    //
    return count($rows);
}
```

### Choosing a name

Try to avoid abbreviations where applicable, exceptions being loop counter. For examle, write `$buffer`, not `$buf`. Use a concept per name, and avoid reusing a name for two concepts in one scope.

Keep related names at similar length when practical: balanced names are easier to scan side by side. Append units by descending significance so related names group: `$latency_ms_max`, `$size_bytes_total`.

```php
//
// Good pairings (similar length, easier to scan)
//
$min_latency_ms = 12;
$max_latency_ms = 47;

$input_bytes_total  = 1024;
$output_bytes_total = 2048;

$read_count_total  = 18;
$write_count_total = 21;

//
// Avoid mixed-length pairings when practical
//
$min_latency_ms = 12;
$max_lat        = 47;

$in_bytes_total     = 1024;
$output_bytes_total = 2048;

$reads             = 18;
$write_count_total = 21;
```

### Acronyms

Treat acronyms as words: first letter cased per the rule, the rest lowercase.

- `HttpClient`, not `HTTPClient`
- `parseXmlId()`, not `parseXMLID()`
- `$user_id`, not `$user_iD`

In a constant an acronym may stay uppercase: `MAX_HTTP_RETRIES`.

This governs names you define. Built-in and third-party names keep their source
casing, the same as external array keys: write `\PDO`, `\PDOStatement`, and PSR
interfaces as their authors spell them, even where the casing breaks this rule.

### Array keys

String keys you define use `snake_case`: `['order_line' => $x]`. This covers only
keys the codebase owns (config, internal shapes, return contracts). Keys from an
external source (database columns, JSON/API payloads, `$_SERVER`, HTTP headers)
keep the source's casing.

### Aligning assignments

Aligning `=` within a small group of related assignments is fine when it aids
scanning; this is why balanced names matter. Keep it local to a contiguous
block; don't align across blank lines or unrelated statements, and don't let one
long outlier force wide padding on its neighbors (rename or split it out
instead). It's a nicety, not a requirement; never block a change over it.

## Control flow and safety

Simple, explicit control flow. No recursion unless the problem is inherently
recursive and depth is limited.

Prefer `match` over `switch`: strict comparison, returns a value, throws on an
unhandled case. Add a default arm only for a genuine fallback. Split compound
conditions into nested branches, not `&&`/`||` chains. Brace every block.

Use `assert()` for invariants that must never fail in correct code: bugs, not
errors (`zend.assertions = 1` in dev and CI, `-1` in production). Never assert
boundary input; that needs guards that always run.

Handle every error; never discard an exception or ignore a return value. Expected
failures (not found, validation failed, insufficient balance) are domain outcomes:
return `null`, a typed result, or a `[value, error]` pair. Unexpected failures
(dropped connection, disk full, corrupted state) throw.

```php
// bad: throws for an expected outcome, forcing callers into try/catch for control flow
function account_find(\PDO $db, int $id): Account
{
    $row = account_row($db, $id);
    if ($row === null) {
        throw new NotFoundException();
    }
    return account_of_row($row);
}

// good: return null; the caller branches on it
function account_find(\PDO $db, int $id): ?Account
{
    $row = account_row($db, $id);
    if ($row === null) {
        return null;
    }
    return account_of_row($row);
}
```

A function past ~70 lines probably carries more than one responsibility; extract
helpers. Declare variables at the narrowest scope, initialized at declaration
unless the assignment is conditional.

## Data design

Design the data on its own terms before the code that uses it; code shaped first
drags the data into needless relationships and redundancy. Interrogate every
structure:

- Is this the most compact encoding? Denormalize only with a stated reason.
- Are these linkages necessary? Prefer a key or index over a pointer or reference,
  so the two structures change independently.
- Must this be stored, or can it be recomputed when needed?
- Can this hierarchy or graph be flattened into an array?

Flat data is easier to understand, change, and keep mutation under control; linked
structures couple their owners. Carry data in structs of public typed fields with
behavior as free functions taking the struct first. Avoid arrays of mixed types;
use a typed value object or named return struct (shapes in [MODULES.md](MODULES.md)).

## State and values

Never duplicate a variable to remember a value: two sources of truth diverge.
Compute or pass it, close to where it is used. Consolidate mutation: the fewer
points that can write a value, the easier it is to reason about. When a value's
role changes as it flows, introduce a fresh, honestly named value (`$slug`, then
`$slug_normalized`) rather than reusing one mutated variable.

Prefer the simplest return type that expresses the outcome:

```
void > bool > int > ?int > value-or-exception
```

## Off-by-one

Index, count, and size are distinct:

| Concept | Meaning              | Conversion                          |
|---------|----------------------|-------------------------------------|
| Index   | Zero-based offset    | index + 1 = count for a single item |
| Count   | Number of items      | count * unit_bytes = size_bytes     |
| Size    | Byte or unit measure |                                     |

Name accordingly: `$node_index`, `$node_count`, `$buffer_size_bytes`. When
dividing integers, comment whether you intend truncation, floor, or ceiling.

## Comments

Comments explain why, not what; well-named identifiers say what. Comment a hidden
constraint, a non-obvious invariant, a workaround, or surprising behavior. Keep
them terse.

Documentation that belongs to a symbol goes in a `/** */` docblock, so the IDE
surfaces it on hover and PHPStan or Psalm check it. A function earns one when its
contract is not visible in its signature: a precondition, an invariant, or an order
it must run in:

```php
/**
 * Reads the version without rechecking it. Callers must hold the account lock;
 * a concurrent write would otherwise be lost. Cheap, since the row is already pinned.
 */
function account_version(Account $account): int
{
    return $account->version;
}
```

The other case is a type PHP cannot state: an array's element layout, a packed
buffer's stride, or a local closure's signature. The docblock carries it in
PHPStan/Psalm notation, which the analyzer and IDE read:

```php
/**
 * @param list<float>         $points  Flat [lat, lng, lat, lng, ...]; stride 2.
 * @param \Closure(int): bool $keep    Local predicate over a point index.
 */
function points_filter(array $points, \Closure $keep): array
```

A `\Closure` is fine for a local callback; a callback that is a published contract
should be a single-method interface, which types the signature natively (see
[MODULES.md](MODULES.md), No premature abstraction).

Document non-obvious properties the same way. A struct-of-arrays whose columns are
flat-packed with a stride earns a `@var` per field carrying the type PHP cannot
express and the layout:

```php
/**
 * Particle pool, struct-of-arrays. Every column is indexed by slot 0..count-1;
 * the i-th particle is the i-th element of each. Scalars only, no per-particle
 * object, so iteration stays a packed sequential read (OPTIMIZATIONS.md).
 */
final class ParticlePool
{
    public int $count = 0;

    /** @var list<float> Flat [x, y, x, y, ...]; stride 2, two floats per particle. */
    public array $position = [];

    /** @var list<int> One packed RGBA per particle: (r << 24) | (g << 16) | (b << 8) | a. */
    public array $color = [];
}
```

Use `//` for the rest: the why behind a line, end-of-line notes, section markers.
Prose after `//` takes a space, a capital, a full stop; end-of-line notes may be
short phrases without punctuation. Mark sections with a bordered block:

```php
//
// Helpers
//
```

No `/* */` for inline notes. Use standard keyboard characters only: `->` not an
arrow glyph, straight quotes, no em-dashes. Where a sentence wants an em-dash,
rephrase it with a colon, a comma, or two sentences. No markdown outside docblocks.

## Formatting

Defer to PER Coding Style. Key points:

- 4 spaces per indent. No tabs.
- Line length 100 columns recommended; exceed only when breaking hurts more.
- One class, interface, trait, or enum per file.
- Opening brace on its own line for classes, methods, functions; same line for
  control structures. Braces always.
- `declare(strict_types=1);` at the top of every file.
- One blank line after the namespace and after the `use` block, and between
  functions. Trailing commas in multi-line arrays and argument lists.

## Dependencies

Prefer none. Every dependency is a supply-chain risk, an upgrade burden, and a
surprise; before adding one, ask whether you can own the functionality directly.
When one is necessary, pin the version.

## Enforcement

PHP-CS-Fixer or PHP_CodeSniffer (`PER`/`PSR12`) for formatting. Identifier casing
here is project policy, not covered by the standards, so add custom sniffs if you
want it enforced.
