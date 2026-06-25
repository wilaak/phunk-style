# Optimizations

As usual, measure first. For optimizing code your typical subjects will be memory access and locality.

## Bulk data: do not use objects

Objects are expensive and each ZVAL is a pointer to a scattered heap.

Each object element costs a 56-byte allocation (40-byte header plus 16-byte ZVAL) reached by a pointer. Iterating a collection of them is a dependent-load chain that defeats the prefetcher, and the per-iteration refcount write dirties a cache line per element.

Store bulk data as scalars in a packed array.

## Returns: avoid per-call allocation

Returning a literal array allocates on every call:

- A scalar lives inline in the returned ZVAL and can stay in a register under the JIT
- Out-parameters by reference and the slot gets bound once at frame build

```php
function router_lookup(Router $router, string $path, mixed &$handler = null, array &$out = []): bool
```

## Mutate copy-on-write values in place

A large string or array passed by value is copied on first write under COW. In a
loop the copy, not the work, dominates. Take it by reference and mutate in place:

```php
function process(string &$buffer): void { $buffer .= '-x'; }
```

## Pass a view, not a copy

To work on part of a buffer, pass the buffer with an offset and length, not a copied slice. The copy allocates and duplicates bytes; the view is three integers.

```php
// bad: substr copies the region before parsing it
$value = number_parse(substr($buffer, $start, $len));

// good: parse in place over (buffer, offset, len)
$value = number_parse($buffer, $start, $len);
```

## Sentinel-delimited runs

For variable-length runs, mark boundaries with an in-band sentinel (e.g. NAN) rather than storing separate lengths. The buffer stays flat and a single pass walks it.

## Bind global symbols explicitly

Without it the compiler cannot prove the call targets the internal function and prevents inlining and specialization.

```php
namespace your_space;

$length = strlen($demo);   // runtime-resolved
$length = \strlen($demo);  // bound to the internal function
```

## Wrap constants in a class container

Only constants defined in a class are inlined by the opcode compiler.

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

## Cast scalars read from packed arrays

Helps the JIT emit better C code for the rest of the loop.

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
## Dispatch by match, not by callback

```php
class Scan
{
    const CSV = 0;
    const TSV = 1;
}

function scan(string $buffer, int $mode): array
{
    return match ($mode) {
        // delimiter constant-folds
        Scan::CSV => scan_delim($buffer, \ord(',')),
        // into each inlined arm
        Scan::TSV => scan_delim($buffer, \ord("\t")),
    };
}
```

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

## Jump with goto in a hot state machine

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

## Inlining and dispatch

The JIT inlines small functions called with known types on a predictable path. Keep hot bodies short with scalar arguments, and keep interface and abstract dispatch out of hot paths so the call target stays statically known.
