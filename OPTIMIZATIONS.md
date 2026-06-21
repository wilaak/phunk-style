# Hot-path rules

As usual you should measure first. For optimizing code your typical subjects will be memory access and locality. Most of this will revolve around that in tandem with JIT specialization.

## Bulk data: do not use objects

Each object element costs a 56-byte allocation (40-byte header plus 16-byte ZVAL)
reached by a pointer. Iterating a collection of them is a dependent-load chain that
defeats the prefetcher, and the per-iteration refcount write dirties a cache line
per element.

Store bulk data as scalars in a packed array, or as a string used as a byte buffer
for compact storage with no per-element ZVAL. See [layout](#layout-aos-vs-soa) and
[bit packing](#bit-packing) for the dense forms.

## Returns: avoid per-call allocation

Returning a literal array allocates on every call; at high call counts that
allocator traffic dominates the callee. Two cheaper shapes, in order of preference:

- Scalar return: a scalar lives inline in the returned ZVAL and can stay in a
  register under the JIT, so nothing is allocated. Use it whenever the callee
  yields one value.
- Out-parameters by reference: the reference slot is bound once at frame build, so
  several values write through existing slots with no per-call tuple allocation.

```php
function router_lookup(Router $router, string $path, mixed &$handler = null, array &$out = []): bool
```

For a call in a tight loop that would otherwise return a fresh array each time,
pass a reusable buffer in and refill it, an arena in miniature. Keep the fill a
plain function on a by-ref out-param, decoupled from the buffer; let a thin caller
own the buffer, taking sole ownership first (detaching the array from the struct)
so the fill mutates in place instead of copying on write:

```php
class Buf
{
    /** @var list<int> reused across calls; refilled, never returned by value */
    public array $items = [];
    public int   $count = 0;
}

/**
 * Fill: a plain out-param, knows nothing about Buf. Reusable and testable on its
 * own; never writes through a property in the loop.
 */
function squares_into(array &$out, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $out[$i] = $i * $i;
    }
}

/**
 * Caller: owns the arena. Detaches the buffer, fills it, hands it back.
 */
function buf_squares(Buf $buf, int $n): void
{
    // Detach: take the buffer out of the property.
    $items = $buf->items;

    // Drop the property's reference; an empty array is a shared immortal, so this
    // is near-free.
    $buf->items = [];

    // Fill the sole-owned local in place: no realloc, keeps capacity.
    squares_into($items, $n);

    // Hand the filled buffer back.
    $buf->items = $items;
    $buf->count = $n;
}
```

The win is real but modest and JIT-dependent. Returning 1024 ints per call, 200k
calls, PHP 8.5:

```
                        no JIT      JIT (tracing)
fresh array per call     1.45 s       0.44 s
reused buffer            1.10 s       0.37 s
                         ~1.3x        ~1.2x
```

Without the JIT, allocation dominates and reuse pays. With it, plain allocation is
already cheap, so reuse pays only where allocation dominates. Measure first. Two
cautions: the buffer is reused mutable state, so it cannot cross a coroutine or
outlive the call; and pass the detached local by reference only for the one call,
never as a long-lived `&$buf->items`, since a persistently referenced (`is_ref`)
array defeats the JIT's array specialization and can be slower than allocating.

## Mutate copy-on-write values in place

A large string or array passed by value is copied on first write under COW. In a
loop the copy, not the work, dominates. Take it by reference and mutate in place:

```php
function process(string &$buffer): void { $buffer .= '-x'; }
```

## Layout: AoS vs SoA

Array of Structs interleaves fields at a stride equal to the field count: best when
most fields are read together. Struct of Arrays keeps one array per field at a
shared index: best when a hot loop touches one or two fields and wants those reads
contiguous.

## Bit packing

Pack small values into one integer when they fit. The operations compile to native
integer instructions with no ZVAL boxing, and one ZVAL replaces many.

This is a fixed-width-field struct done by hand. Where C, Rust, or Zig declare
`struct { u16 x; u16 y; }` and the compiler lays the fields out contiguously, PHP
has only the 64-bit `int`, so you carve the fields out of one integer: each region
is a field of a chosen width, its mask selects it, its shift positions it. One
packed int is the dense struct those languages get for free, without the per-field
ZVAL an array or object adds.

Wrap the shift-and-mask behind small named accessors, the field getters and
setter of that hand-built struct. The twiddling then lives in one named place and
call sites read plainly; the JIT inlines functions this small, so the hot loop pays
nothing for the clarity.

```php
class Cell
{
    /** x: bits 0..15 (u16) */
    const X_SHIFT = 0;

    /** y: bits 16..31 (u16) */
    const Y_SHIFT = 16;

    /** 16-bit field mask */
    const MASK = 0xFFFF;
}

function cell_pack(int $x, int $y): int
{
    return ($x & Cell::MASK) << Cell::X_SHIFT
         | ($y & Cell::MASK) << Cell::Y_SHIFT;
}

function cell_x(int $cell): int
{
    return ($cell >> Cell::X_SHIFT) & Cell::MASK;
}

function cell_y(int $cell): int
{
    return ($cell >> Cell::Y_SHIFT) & Cell::MASK;
}

// call sites read like field access, not bit soup
$cell = cell_pack($x, $y);
$y    = cell_y($cell);
```

A packed cell is one scalar, so it drops into a packed array. Store many the two
ways the layout section describes. As a stride (AoS), one flat array with several
ints per cell, the packed position and a weight interleaved:

```php
class Grid
{
    /** packed (x, y) */
    const POS    = 0;
    /** weight for this cell */
    const WEIGHT = 1;
    /** ints per cell */
    const STRIDE = 2;
}

$slot_count = \count($grid);
for ($i = 0; $i < $slot_count; $i += Grid::STRIDE) {
    $cell   = (int) $grid[$i + Grid::POS];
    $weight = (int) $grid[$i + Grid::WEIGHT];

    $x = cell_x($cell);
    $y = cell_y($cell);
    // ... use $x, $y, $weight
}
```

Or parallel (SoA): every field its own array, sharing one index. This pays off only
when the fields are unrelated and read separately, in distinct passes each touching
one column, so each scan runs down contiguous memory and drags nothing else through
cache. When a record's fields are used together, keep them in one slot (AoS or
packed); SoA would scatter the record across arrays. So `x` and `y`, read as a
pair, are a bad SoA fit; independent columns of a record are a good one:

```php
$amount_cents = [1200, 800, 4300];
$created_at   = [1718, 1701, 1726];   // unrelated column, parallel by index

// One pass touches only $amount_cents, contiguous; $created_at never loaded.
$total = 0;
$count = \count($amount_cents);
for ($i = 0; $i < $count; $i++) {
    $total += (int) $amount_cents[$i];
}

// A separate, rarer pass touches only $created_at.
$newest = 0;
for ($i = 0; $i < $count; $i++) {
    $ts = (int) $created_at[$i];
    if ($ts > $newest) {
        $newest = $ts;
    }
}
```

## Sentinel-delimited runs

For variable-length runs, mark boundaries with an in-band sentinel (e.g. NAN)
rather than storing separate lengths. The buffer stays flat and a single pass walks
it. Cast each field on read.

## Bind global symbols explicitly

Inside a namespace, prefix global functions with `\`. Without it the compiler
cannot prove the call targets the internal function rather than a same-named one
defined later in the namespace, so it emits a runtime-resolved call and cannot bind
to the specialized or inlined handler.

```php
namespace your_space;

$length = strlen($demo);   // runtime-resolved
$length = \strlen($demo);  // bound to the internal function
```

## Wrap constants in a class container

Define constants as class constants, not namespace `const` or `define()`. A class
constant is resolved at compile time and inlined into the opcode by opcache, so the
use site carries the literal with no lookup. A namespaced `const` or `define()`
constant is resolved at runtime through a hash lookup, and the indirection persists
where the JIT cannot fold it.

Group related constants in a `final` class acting as a namespace, with no fields,
no methods, never instantiated:

```php
namespace app\cpu;

class Limit
{
    const STRIDE    = 8;
    const MASK      = 0xFFFF;
    const Y_SHIFT   = 16;
    const MAX_CYCLE = 1_000_000;
}

// inlined to the literals at compile time
$cell = ($x & Limit::MASK) | (($y & Limit::MASK) << Limit::Y_SHIFT);
```

This is the one place a class earns its keep without holding state: a compile-time
inlining boundary, not an object, and it keeps the constant greppable by its owning
type. Module-wide constants go in a container too, never loose namespace `const`,
named for what they configure: one container per cohesive set (`Cell`, `Limit`),
or a single per-module container when there are few. There is no second rule:
constants live in a container, class constants throughout. Never use `define()`.

## Cast scalars read from packed arrays

A packed element is a runtime-tagged ZVAL of unknown static type. In SSA an
unknown-typed read forces each consumer to guard its operand, carry a deopt exit,
and produce another unknown result; the uncertainty propagates through the whole
computation and nothing stays in a typed register.

A `(float)`, `(int)`, or `(bool)` cast pins the type at the read, so the JIT
specializes consumers into native typed opcodes with no guards. The cast is
near-free: elided when the type already matches, a conversion owed anyway when not.
Cast once into a local where the value leaves the array, then compute on the local.

```php
class Point
{
    const LAT    = 0;
    const LNG    = 1;
    const STRIDE = 2;
}

$point_count = \count($points);
for ($i = 0; $i < $point_count; $i += Point::STRIDE) {
    $lat = (float) $points[$i + Point::LAT];
    $lng = (float) $points[$i + Point::LNG];
}
```

Index and count to `int`, measurements to `float`, flags to `bool`.

## Dispatch by match, not by callback

PHP has no function type, and a callback is the slowest way to vary behavior on a
hot path: a `\Closure` or `callable` call is an indirect call the JIT cannot inline,
and each closure may allocate. Pass a small integer tag and `match` on it instead;
every arm is concrete code with a known target the JIT inlines, and literals in an
arm constant-fold. Name the tags as class constants.

When the tag is loop-invariant, hoist the `match` out of the loop, so each arm is
its own specialized loop:

```php
class Scan
{
    const CSV = 0;
    const TSV = 1;
}

function scan(string $buffer, int $mode): array
{
    return match ($mode) {
        Scan::CSV => scan_delim($buffer, \ord(',')),    // delimiter constant-folds
        Scan::TSV => scan_delim($buffer, \ord("\t")),   // into each inlined arm
    };
}
```

When it varies per element, as in a parser on token kind, the `match` sits in the
loop, but every arm is still a static, inlinable target:

```php
for ($i = 0; $i < $token_count; $i++) {
    $kind = (int) $kinds[$i];
    $nodes[] = match ($kind) {
        Tok::NUM => num_read($buffer, $i),
        Tok::STR => str_read($buffer, $i),
        Tok::SYM => sym_read($buffer, $i),
    };
}
```

This trades extensibility for speed: the set is closed, so a new behavior is a new
arm, not a new function. Worth it only on a measured hot path.

## Jump with goto in a hot state machine

A function call per step pays frame setup and teardown each time; a state machine
built as one handler function per state pays it per character. A `goto` between
labels transitions with neither. For a genuine multi-state machine such as a lexer
or a byte-protocol parser, each state is a label and each transition a `goto`:

```php
class St
{
    const SPACE = 0;
    const WORD  = 1;
}

$tokens = 0;
$i      = 0;

SPACE:
    if ($i >= $n) {
        goto DONE;
    }
    if ($s[$i] !== ' ') {   // word begins
        $tokens++;
        $i++;
        goto WORD;
    }
    $i++;
    goto SPACE;

WORD:
    if ($i >= $n) {
        goto DONE;
    }
    if ($s[$i] === ' ') {
        $i++;
        goto SPACE;
    }
    $i++;
    goto WORD;

DONE:
```

Counting word tokens, 180k bytes, 2000 reps, PHP 8.5:

```
                        no JIT      JIT (tracing)
function per state       1.00x       1.00x
goto labels              2.40x       ~2.6x
inline loop + branch     1.74x       ~2.5x
```

The decisive cost is the per-element call, not the loop construct: `goto` and a
plain inlined loop both eliminate it. Under the JIT an inlined loop comes within a
few percent of `goto`, so `goto` is warranted only where transitions among many
states resist a single loop with `match`. PHP restricts `goto` to jumps within a
function; confine it to such hot state machines.

## Inlining and dispatch

The JIT inlines small functions called with known types on a predictable path. Keep
hot bodies short with scalar arguments, and keep interface and abstract dispatch out
of hot paths so the call target stays statically known.
