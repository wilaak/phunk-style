# Conventions

## Dispatch: closed set vs open set

A closed set of variants (known when you write the code) is an enum, dispatched by
one central `match`. Polymorphism becomes a value on the belt; dispatch becomes a
switch.

```php
namespace app\notify;

// The "interface" Notifier with send() becomes a tag...
enum Channel
{
    case Email;
    case Sms;
    case Push;
}

// ...and the "implementations" become arms of one central match: no closure, no
// interface, no object, just data in and a free function per arm.
function notify_send(Channel $channel, Message $message): Result
{
    return match ($channel) {
        Channel::Email => notify_send_email($message),
        Channel::Sms   => notify_send_sms($message),
        Channel::Push  => notify_send_push($message),
    };
}
```

| Situation                                         | Use                          |
| ------------------------------------------------- | ---------------------------- |
| Variants known at authoring time (a closed set)   | enum + central `match`       |
| Variants registered by strangers at runtime       | single-method interface      |

## Sum type with data

An enum is a tag with no fields. When a variant carries data, give each variant a record and return a union; `match` on the type.

```php
class Circle    { public float $radius = 0.0; }
class Rectangle { public float $w = 0.0; public float $h = 0.0; }

function shape_area(Circle|Rectangle $shape): float
{
    return match (true) {
        $shape instanceof Circle    => 3.14159 * $shape->radius ** 2,
        $shape instanceof Rectangle => $shape->w * $shape->h,
    };
}
```

Adding a variant to the union turns every non-exhaustive `match` into a static error, same as adding an enum case.

### Subject-prefixed functions

Place free functions in a module namespace, then prefix each function with its subject type so APIs stay grouped by module and by name.

Helpers from a split follow the same rule: keep a single-caller helper grouped under its parent by prefixing with the parent's name.

### Subject scope tips

- Single caller: keep parent prefix (`account_import_validate_rows`) and mark `#[Local]`.
- Multiple callers in one module: drop the parent prefix (`account_validate_rows`) and mark `#[Internal]`.
- Callers across modules: make it a public module API and keep a clear subject prefix.

### Example

```php
namespace app\ledger;

// In module app\ledger, account_* reads as one family and stays greppable.
class Account
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

#[Local]
function account_import_validate_rows(array $rows): array
{
    // Scoped to account_import: keep the parent prefix for locality.
    return $rows;
}

#[Local]
function account_import_persist_rows(array $rows): int
{
    // Scoped to account_import: keep the parent prefix for locality.
    return count($rows);
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

### Handles, not references

Refer to a record by an integer index into its array, not by passing the object around. An index is small, copyable, serializable, and stable across a save and load; an object handle is none of those and quietly aliases.

```php
$accounts = [];   // list<Account>, the index is the id

$from = 12;       // a handle, not an object
$to   = 47;
ledger\transfer($accounts, $from, $to, $amount_cents);
```

This is what lets a struct-of-arrays work and keeps two structures independent.

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
