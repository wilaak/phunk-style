# Optimizations

As usual, measure first. For optimizing code your typical subjects will be memory access and locality. This is not an in depth guide, just some simple tricks that may or may not suit your situation. 

## Bulk data: avoid many objects

Objects are expensive: every variable referencing it is a ZVAL with a pointer to a scattered heap address. Iterating over a list of objects will make the machinery in your CPU that makes it go fast stop working. You will have sub optimial performance.

Each object costs a 56-byte allocation (40-byte header plus 16-byte ZVAL) reached by a pointer. Iterating a collection of them is a dependent-load chain that defeats the prefetcher, and the per-iteration refcount write dirties a cache line per element.

Store bulk data as scalars in a packed array instead.

## Returns: avoid per-call allocation

Returning a literal array allocates on every call. Out-parameters by reference and the slot gets bound once at frame build

```php
function router_lookup(
    Router $router,
    string $path,
    mixed &$handler = null,
    array &$out = []
): bool {}
```

## Append to a buffer

If a string only has a single reference to it you can append data to mutate in place.

```php
function process(string &$buffer): void { $buffer .= '-x'; }
```

## Sentinel-delimited runs

For variable-length runs, mark boundaries with an in-band sentinel (e.g. NAN, -1, etc) rather than storing separate lengths. The buffer stays flat and a single pass walks it.

## Bind global symbols explicitly

Without it the compiler cannot prove the call targets the internal function and prevents inlining and specialization.

```php
namespace app;

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

Helps the JIT emit better machine code for the rest of the loop by specializing on the type.

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

## Jump with goto in a hot state machine

Can be useful as function call overhead still largely dominates in the PHP engine, and the JIT has limited escape analysis to cover for it.

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