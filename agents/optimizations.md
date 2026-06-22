<!-- derived from ../docs/OPTIMIZATIONS.md — terse rules only; edit the source, then regenerate -->
Measure before optimizing.

## bulk-data-do-not-use-objects
Store bulk data as scalars in a packed array, or as a string byte buffer; never as a collection of objects.

## returns-avoid-per-call-allocation
Return a scalar when the callee yields one value.
Use by-reference out-parameters for several values instead of returning an array.
For a tight loop, pass a reusable buffer by reference and refill it in place.
Detach the buffer from its property first (`$items = $buf->items; $buf->items = [];`) so the fill mutates sole-owned state without COW.
Never hold a long-lived `&$buf->items` reference; a persistent `is_ref` array defeats JIT array specialization.
The reused buffer cannot cross a coroutine or outlive the call.

## mutate-copy-on-write-values-in-place
Take a large string or array by reference and mutate in place rather than copying under COW.

## pass-a-view-not-a-copy
Pass `(buffer, offset, len)` instead of a copied slice: `number_parse(substr(...))` -> `number_parse($buffer, $start, $len)`.

## reuse-a-scratch-buffer-per-tick
Hold one scratch buffer and truncate it (`$scratch = []` on a sole-owned local) each tick; do not reallocate. It cannot cross a coroutine.

## layout-aos-vs-soa
Use AoS (fields interleaved at a stride) when most fields are read together.
Use SoA (one array per field, shared index) when a hot loop touches one or two fields.

## bit-packing
Pack small values into one 64-bit int via per-field shift and mask.
Wrap shift-and-mask behind small named accessors; keep field widths/shifts as class constants.
Store packed scalars in AoS via a STRIDE, or SoA only when fields are unrelated and read in separate passes.

## sentinel-delimited-runs
Mark variable-length run boundaries with an in-band sentinel (e.g. NAN), not stored lengths; cast each field on read.

## bind-global-symbols-explicitly
Prefix global functions with `\` inside a namespace: `strlen($x)` -> `\strlen($x)`.

## wrap-constants-in-a-class-container
Define constants as class constants; never namespace `const` or `define()`.
Group related constants in a `final` class with no fields, no methods, never instantiated.
Use one container per cohesive set, or a single per-module container when few.

## cast-scalars-read-from-packed-arrays
Cast each value read from a packed array once into a local: `(int)`/`(float)`/`(bool)`.
Cast index and count to `int`, measurements to `float`, flags to `bool`.

## dispatch-by-match-not-by-callback
Pass a small integer tag and `match` on it instead of a closure or `callable`; name tags as class constants.
Hoist the `match` out of the loop when the tag is loop-invariant.

## jump-with-goto-in-a-hot-state-machine
Use `goto` between labels for a multi-state machine where transitions resist a single `match` loop; confine `goto` to such hot paths.

## inlining-and-dispatch
Keep hot bodies short with scalar arguments.
Keep interface and abstract dispatch out of hot paths.
