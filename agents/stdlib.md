<!-- derived from ../docs/STDLIB.md — terse rules only; edit the source, then regenerate -->

## str
Use `str\len` for strlen/mb_strlen.
Use `str\slice($s, $start, $len)` for substr/mb_substr.
Use `str\split($s, $sep)` for str_split/explode.
Use `str\fields($s)` for splitting on whitespace.
Use `str\trim`, `str\trim_start`, `str\trim_end` for trim/ltrim/rtrim.
Use `str\contains($s, $sub)` for str_contains.
Use `str\index_of($s, $sub)` for strpos.
Use `str\replace($s, $old, $new)` for str_replace.
Use `str\starts_with($s, $prefix)` and `str\ends_with($s, $suffix)`.
Use `str\to_upper` and `str\to_lower`.
Use `str\repeat($s, $n)` for str_repeat.
Use `str\pad_start($s, $w, $c)` and `str\pad_end($s, $w, $c)`.
Use `str\join($parts, $sep)` for implode.

## bytes
Use `bytes\len`, `bytes\slice($s, $start, $len)` for byte-level strlen/substr.
Use `bytes\to_hex` and `bytes\from_hex` for bin2hex/hex2bin.
Use `bytes\pack` and `bytes\unpack` for pack/unpack.

## slice
Use `slice\map($a, $fn)`, `slice\filter($a, $pred)`, `slice\reduce($a, $init, $fn)`.
Use `slice\index_of($a, $v)` for array_search.
Use `slice\contains($a, $v)` for in_array.
Use `slice\find($a, $pred)`, `slice\any($a, $pred)`, `slice\all($a, $pred)`.
Use `slice\sort` (mutates) and `slice\sorted` (returns copy).
Use `slice\sort_by($a, $key_fn)` for usort.
Use `slice\reverse`, `slice\chunk($a, $size)`, `slice\flatten($a)`.
Use `slice\take($a, $n)` and `slice\drop($a, $n)` for array_slice.
Use `slice\first` and `slice\last`.
Use `slice\sum` and `slice\unique`.

## map
Use `map\keys`, `map\values`, `map\has($m, $key)`.
Use `map\get($m, $key)` for `$m[$k] ?? null`.
Use `map\get_or($m, $key, $d)` for `$m[$k] ?? $d`.
Use `map\merge($a, $b)`.
Use `map\map_values($m, $fn)` and `map\filter($m, $pred)` for keyed map/filter.
Use `map\invert($m)` for array_flip.

## iter
Use `iter\map($it, $fn)` and `iter\filter($it, $pred)` (eager).
Use `iter\of($a)` and `iter\collect($it)`.
Use `iter\for_each($it, $fn)` for side-effect foreach.

## option-conv-fmt
Return `T|Absent`; test with `option\is_some` and `option\unwrap_or`.
Use `conv\to_int($s)` returning `int|ParseError`.
Use `conv\to_float($s)` returning `float|ParseError`.
Use `conv\to_bool($s)` returning `bool|ParseError`.
Use `fmt\int($n, base: 16)` for dechex/sprintf('%x').
Use `fmt\float($x, places: 2)` for number_format.
Use `fmt\bytes($n)` and `fmt\duration($d)`.
