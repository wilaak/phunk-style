# Standard Library

Super quick sketch of what something like this could look like if you used namespaces as modules

```php
use std\{str, bytes, slice, map, iter, option, conv, fmt};
```

## str

```php
// strlen / mb_strlen
str\len($s)

// substr / mb_substr
str\slice($s, $start, $len)

// str_split / explode
str\split($s, $sep)

// preg_split('/\s+/', ...)
str\fields($s)

// trim
str\trim($s)

// ltrim / rtrim
str\trim_start($s)
str\trim_end($s)

// str_contains
str\contains($s, $sub)

// strpos
str\index_of($s, $sub)

// str_replace
str\replace($s, $old, $new)

// str_starts_with
str\starts_with($s, $prefix)

// str_ends_with
str\ends_with($s, $suffix)

// strtoupper / mb_strtoupper
str\to_upper($s)

// strtolower
str\to_lower($s)

// str_repeat
str\repeat($s, $n)

// str_pad
str\pad_start($s, $w, $c)
str\pad_end($s, $w, $c)

// implode
str\join($parts, $sep)
```

## bytes

```php
// strlen
bytes\len($s)

// substr
bytes\slice($s, $start, $len)

// bin2hex
bytes\to_hex($s)

// hex2bin
bytes\from_hex($s)

// pack / unpack
bytes\pack(...)
bytes\unpack(...)
```

## slice

```php
// array_map($fn, $a)
slice\map($a, $fn)

// array_filter($a, $fn)
slice\filter($a, $pred)

// array_reduce($a, $fn, $init)
slice\reduce($a, $init, $fn)

// array_search
slice\index_of($a, $v)

// in_array($v, $a)
slice\contains($a, $v)

// current(array_filter(...))
slice\find($a, $pred)

// (new)
slice\any($a, $pred)
slice\all($a, $pred)

// sort (mutates, bool)
slice\sort($a)
slice\sorted($a)

// usort($a, $cmp)
slice\sort_by($a, $key_fn)

// array_reverse
slice\reverse($a)

// array_chunk
slice\chunk($a, $size)

// array_merge(...$a)
slice\flatten($a)

// array_slice
slice\take($a, $n)
slice\drop($a, $n)

// reset / end
slice\first($a)
slice\last($a)

// array_sum
slice\sum($a)

// array_unique
slice\unique($a)
```

## map

```php
// array_keys
map\keys($m)

// array_values
map\values($m)

// array_key_exists
map\has($m, $key)

// $m[$k] ?? null
map\get($m, $key)

// $m[$k] ?? $d
map\get_or($m, $key, $d)

// array_merge($a, $b)
map\merge($a, $b)

// array_map (keyed)
map\map_values($m, $fn)

// array_filter (keyed)
map\filter($m, $pred)

// array_flip
map\invert($m)
```

## iter

```php
// eager slice\map
iter\map($it, $fn)

// eager slice\filter
iter\filter($it, $pred)

// (new)
iter\of($a)
iter\collect($it)

// foreach for effect
iter\for_each($it, $fn)
```

## option / conv / fmt

```php
// false / null returns
T|Absent  // option\is_some, option\unwrap_or

// (int) / intval
conv\to_int($s)    // int|ParseError

// (float)
conv\to_float($s)  // float|ParseError

// filter_var(.., BOOLEAN)
conv\to_bool($s)   // bool|ParseError

// dechex / sprintf('%x')
fmt\int($n, base: 16)

// number_format
fmt\float($x, places: 2)

// (new)
fmt\bytes($n)
fmt\duration($d)
```
