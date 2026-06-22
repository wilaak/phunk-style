<!-- derived from ../docs/CONVENTIONS.md — terse rules only; edit the source, then regenerate -->

## dispatch-closed-set-vs-open-set
Model a closed set of variants as an enum dispatched by one central `match`, one free function per arm.
Use a single-method interface only for variants registered by strangers at runtime.

## sum-type-with-data
Give each data-carrying variant its own record, return a union, and `match` on the type.
Keep every `match` over a union or enum exhaustive so a new variant becomes a static error.

## subject-prefixed-functions
Place free functions in a module namespace and prefix each with its subject type (e.g. `account_can_debit`).

## variable-scope
Declare each variable at the narrowest scope that fits, initialised where declared unless assignment is conditional.

## data-design
Design data before the code that uses it.
Use the most compact encoding; denormalize only with a stated reason.
Prefer a key or index over a pointer or reference.
Recompute a value instead of storing it when feasible.
Flatten hierarchies and graphs into arrays.
Carry data in structs of public typed fields with behavior as free functions taking the struct first.
Avoid arrays of mixed types; use a typed value object or named return struct.
Default to wide public structs grouped by access pattern; group fields read and written together.
Keep wide structs safe by consolidating writes: many readers, few writers.

## handles-not-references
Refer to a record by an integer index into its array, not by passing the object.

## state-and-values
Never duplicate a variable to remember a value; compute or pass it close to use.
Consolidate mutation to the fewest possible write points.
Introduce a fresh named value when a value's role changes (`$slug` -> `$slug_normalized`) rather than mutating one.
Prefer the simplest return type: `void > bool > int > ?int > value-or-exception`.

## off-by-one
Treat index, count, and size as distinct; name them `$node_index`, `$node_count`, `$buffer_size_bytes`.
Comment whether integer division intends truncation, floor, or ceiling.
