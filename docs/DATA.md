# Data

Design the data first; the code that transforms it follows.

## Data design

Shape the data on its own terms before the code that uses it. Code shaped first
drags the data into needless relationships and redundancy. Interrogate every
structure:

- Is this the most compact encoding? Denormalize only with a stated reason.
- Are these linkages necessary? Prefer a key or index over a pointer, so the two
  structures change independently.
- Must this be stored, or can it be recomputed when needed?
- Can this hierarchy or graph be flattened into an array?

Flat data is easier to understand, change, and keep mutation under control; linked
structures couple their owners. Carry data in structs of public typed fields, with
behavior as free functions taking the struct first. Avoid arrays of mixed types;
use a typed value object or named return struct (shapes in [MODULES.md](MODULES.md)).

Default to wide, public structs grouped by access pattern, not narrow ones split by
"responsibility": fields read and written together in the same stages belong
together. Going wide lets a cross-cutting pass (logging, validation, serialization,
snapshot) be one function over the whole record instead of something threaded
through every type. A wide struct stays safe only under write consolidation — many
stages reading a field, few writing it. Without that it is a god object, not a fat
struct.

## Handles, not references

Refer to a record by an integer index into its array, not by passing the object
around. An index is small, copyable, serializable, and stable across a save and
load; an object handle is none of those and quietly aliases.

```php
$accounts = [];   // list<Account>, the index is the id

$from = 12;       // a handle, not an object
$to   = 47;
ledger\transfer($accounts, $from, $to, $amount_cents);
```

This is what lets a struct-of-arrays work and keeps two structures independent.

## One source of truth

> Give a man a state and he'll have a bug one day, but teach him to represent state
> in two separate locations that have to be kept in sync and he'll have bugs for a
> lifetime.

Never duplicate a variable to remember a value: two sources of truth diverge.
Compute or pass it, close to where it is used. Consolidate mutation: the fewer
points that can write a value, the easier it is to reason about.

When a value's role changes as it flows, introduce a fresh, honestly named value
(`$slug`, then `$slug_normalized`) rather than reusing one mutated variable.

Declare each variable at the narrowest scope that fits, initialised where you
declare it unless the assignment is conditional, so a reader never meets a name
before its value.

## Simplest return type

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

Name accordingly: `$node_index`, `$node_count`, `$buffer_size_bytes`. When dividing
integers, comment whether you intend truncation, floor, or ceiling.
