# Style guide

The concrete syntax rules of the PHUNK style.

## Casing

The syntax rules with minimal ambiguity and clear boundaries for easy distinction.

> [!NOTE]   
> Lowercase namespaces are deliberate against the usual PascalCase to avoid the ambiguity with types.

> [!NOTE]   
> Array key casing applies only to keys you define; external keys (DB columns, JSON, headers) keep their source casing.

| Construct                     | Casing             | Example          |
| ----------------------------- | ------------------ | ---------------- |
| Variable, local               | `snake_case`       | `$order_line`    |
| Variable, property            | `snake_case`       | `$created_at`    |
| Variable, parameter           | `snake_case`       | `int $row_count` |
| Function, free                | `snake_case`       | `order_total()`  |
| Function, method              | `camelCase`        | `getTotal()`     |
| Type, class                   | `PascalCase`       | `OrderLine`      |
| Type, interface               | `PascalCase`       | `Comparable`     |
| Type, trait                   | `PascalCase`       | `Timestamps`     |
| Type, enum                    | `PascalCase`       | `Status`         |
| Type, enum case               | `PascalCase`       | `Status::Active` |
| Namespace                     | `snake_case`       | `app\order_book` |
| Array key, string             | `snake_case`       | `'order_line'`   |
| Constant                      | `UPPER_SNAKE_CASE` | `MAX_SIZE`       |
| Goto label                    | `UPPER_SNAKE_CASE` | `PARSE_END`      |

### Acronyms

Treat acronyms as words: first letter cased per the rule, the rest lowercase.

- `HttpClient`, not `HTTPClient`
- `parseXmlId()`, not `parseXMLID()`
- `$user_id`, not `$user_iD`

Built-in and third-party that break this keep their source casing, we can't do much about that.

## Naming Things

Some say it's the most difficult thing in programming. In any case, try to prioritize naming and flow: if code reads naturally, comments are mostly for non-obvious constraints.

### Choosing a name

Try to avoid abbreviations where applicable, exceptions being things like loop counters. As an examle, you should write `$buffer`, not `$buf`. Use a concept per name, and avoid reusing a name for two concepts in one scope.

Long explicit names like `account_import_validate_rows` are doing their job: it says exactly what the function is and stays searchable. Don't always worry much about length, especially for internal use. Even a stupidly long and explicit name can be fine in some cases.

Keep related names at similar length when practical: balanced names are easier to scan side by side. Append units by descending significance so related names group: `$latency_ms_max`, `$size_bytes_total`.

You may ponder this graph of name length discoverability:

![Naming discoverability chart](./assets/naming-things-discoverability.webp)

### Aligning assignments

Aligning `=` within a small group of related assignments is fine when it aids scanning; this is why balanced names matter.

Keep it local to a contiguous block and don't align across blank lines or unrelated statements, and don't let one long outlier force wide padding on its neighbors (rename or split it out instead).

It's a nicety, not a requirement.

### Example

```php
// Good pairings (similar length, easier to scan)
$min_latency_ms = 12;
$max_latency_ms = 47;

$input_bytes_total  = 1024;
$output_bytes_total = 2048;

$read_count_total  = 18;
$write_count_total = 21;

// Avoid mixed-length pairings when practical
$min_latency_ms = 12;
$max_lat        = 47;

$in_bytes_total     = 1024;
$output_bytes_total = 2048;

$reads             = 18;
$write_count_total = 21;
```

## Organizing functions

The casing rules above are syntax: follow them. What follows is a recommendation for how to organize free functions. For the fuller treatment of modules, see [MODULES.md](MODULES.md).

### Splitting into helpers

A function past ~70 lines probably carries more than one responsibility. It can be a good idea to split it into helpers so the parent reads as prose: a short sequence of named steps you follow top to bottom, each step's detail one level down.

The exception can be a hot path where the call itself is the cost. PHP function calls are not free, and in a tight loop the overhead of jumping into a helper can outweigh the clarity it buys.

### Subject-prefixed functions

Place free functions in a module namespace, then prefix each function with its subject type so APIs stay grouped by module and by name.

Helpers from a split follow the same rule: keep a single-caller helper grouped under its parent by prefixing with the parent's name.

### Subject scope tips

- Single caller: keep parent prefix (`account_import_validate_rows`) and mark `#[Internal(Scope::File)]`.
- Multiple callers in one module: drop the parent prefix (`account_validate_rows`) and mark `#[Internal]`.
- Callers across modules: make it a public module API and keep a clear subject prefix.

### Example

```php
namespace app\ledger;

// In module app\ledger, account_* reads as one family and stays greppable.
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
    // Scoped to account_import: keep the parent prefix for locality.
    return $rows;
}

#[Internal(Scope::File)]
function account_import_persist_rows(array $rows): int
{
    // Scoped to account_import: keep the parent prefix for locality.
    return count($rows);
}
```

## Control flow and safety

Keep control flow explicit, the same assembly line you can read top to bottom.

### Branching

Reach for `match` over `switch`: it compares strictly, returns a value, and throws on a case you forgot, where `switch` falls through silently. Add a default arm only for a genuine fallback. Split compound conditions into nested branches rather than `&&`/`||` chains, so stepping through shows which test failed, and brace every block. Avoid recursion unless the problem is inherently recursive and its depth is bounded.

### Assertions

Use `assert()` for invariants that must never fail in correct code: bugs, not bad input. For performance you can set (`zend.assertions = 1` in dev and CI, `-1` in production). Never assert on boundary input, since assertions compile out; anything from outside needs a guard that always runs.

### Errors

Handle every error; never discard an exception or ignore a return value. Expected failures (not found, validation failed, insufficient balance) are domain outcomes: put them in the return type so the contract is checked, not commented. One obvious failure is a nullable return (`?Account`); several distinct failures the caller branches on are a result enum and a union return (`Account|AccountError`). Reserve exceptions for the genuinely unexpected (dropped connection, full disk, corrupted state).

A thrown exception here is a panic, not control flow: it unwinds to the top of the request or tick, gets logged, and aborts that one unit of work without taking down the server. Nothing on the path between catches it to recover, so anything a caller is meant to handle must be a value, not a throw.

This is the edge over a `@throws` docblock: a union return type is a real type PHP and the analyzer both read, where `@throws` is an advisory note the language never enforces.

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

When there is more than one failure mode, name them in an enum and return a union, so the failures live in the signature and a `match` over the result stays exhaustive:

```php
enum AccountError
{
    case NotFound;
    case Frozen;
    case InsufficientFunds;
}

// The failure modes are part of the return type: no docblock needed
function account_debit(\PDO $db, int $id, int $amount_cents): Account|AccountError
{
    $account = account_find($db, $id);
    if ($account === null) {
        return AccountError::NotFound;
    }
    if ($account->frozen) {
        return AccountError::Frozen;
    }
    if ($account->balance_cents < $amount_cents) {
        return AccountError::InsufficientFunds;
    }

    $account->balance_cents -= $amount_cents;
    return $account;
}

function account_debit_page(\PDO $db, int $id, int $amount_cents): Response
{
    $result = account_debit($db, $id, $amount_cents);
    return match (true) {
        $result instanceof Account                   => render_balance($result),
        $result === AccountError::NotFound           => http_404(),
        $result === AccountError::Frozen             => http_409('account frozen'),
        $result === AccountError::InsufficientFunds  => http_402(),
    };
}
```

### Variable scope

Declare each variable at the narrowest scope that fits, initialised where you declare it unless the assignment is conditional, so a reader never meets a name before its value.

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

Default to wide, public structs grouped by access pattern, not narrow ones split by
"responsibility": fields read and written together in the same stages belong in the
same struct. Going wide is what lets a cross-cutting pass (logging, validation,
serialization, snapshot) be one function over the whole record instead of something
threaded through every type. A wide struct stays safe only under write
consolidation, many stages reading a field and few writing it; without that it is a
god object, not a fat struct. See [The fat struct](chapters/06-classes.md#the-fat-struct).

## State and values

> Give a man a state and he'll have a bug one day, but teach him to represent state in two separate locations that have to be kept in sync and he'll have bugs for a lifetime.

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

## Misc

- Line length 100 columns recommended; exceed only when breaking hurts more.
- Opening brace on its own line for classes, methods, functions; same line for
  control structures. Braces always.
- One blank line after the namespace and after the `use` block, and between
  functions. Trailing commas in multi-line arrays and argument lists.
