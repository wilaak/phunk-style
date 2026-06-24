# Standard Library

> [!WARNING]  
> No thought was put into this

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

## References

Use this shit to see what we can *improve* about the current standard lib.

Below is an AI generated survey:

### Critiques

- **PHP: a fractal of bad design** — eevee, 2012. <https://eev.ee/blog/2012/04/09/php-a-fractal-of-bad-design/>
  The canonical writeup. Its stdlib section names the two headline problems:
  inconsistent argument order (`array_filter($input, $callback)` vs
  `array_map($callback, $input)`; `strpos($haystack, $needle)` vs
  `array_search($needle, $haystack)`) and inconsistent underscores
  (`strpos`/`str_rot13`, `phpversion`/`php_uname`, `urlencode`/`base64_encode`,
  `gettype`/`get_class`).
  **Lesson:** pick ONE argument order and ONE separator rule, never deviate.
- **PHP Sadness** — a per-item catalogue of the same warts. <http://phpsadness.com/>

### PHP RFCs

- **Consistent Function Names** — Yasuo Ohgaki, 2015. Never adopted (stalled in
  *Under Discussion*). <https://wiki.php.net/rfc/consistent_function_names>
  Proposed renaming offending functions with permanent aliases. Most useful for
  its **five-category taxonomy of inconsistency**, a ready-made checklist:
  (1) missing module prefixes, (2) omitted/inconsistent underscores
  (`bcadd` vs `bc_add`), (3) excessive abbreviation (it marks `jf_n_s_i` "Bad"),
  (4) inconsistent needle/haystack order, (5) mixed casing.
  **Lesson:** get the convention right up front — retrofitting via aliases never
  shipped here, and was explicitly rejected in Python (below).
- **str_contains / str_starts_with / str_ends_with** — PHP 8.0, passed 51–4.
  <https://wiki.php.net/rfc/str_contains> ·
  <https://wiki.php.net/rfc/add_str_starts_with_and_ends_with_functions>
  Added because the old `strpos(...) !== false` idiom is "not very intuitive" and
  "easy to get wrong." The new family is haystack-first.
  **Lesson:** ship explicit-intent boolean predicates, not compositions of
  low-level functions against fragile sentinels.
- **Saner string to number comparisons** + **Saner numeric strings** — PHP 8.0,
  ~88% yes. <https://wiki.php.net/rfc/string_to_number_comparison> ·
  <https://wiki.php.net/rfc/saner-numeric-strings>
  Killed `0 == "foobar"` being true; non-numeric coercion now throws.
  **Lesson:** error predictably — throw, don't silently coerce.

### Positive models

- **azjezz/psl** (now `php-standard-library`) — <https://github.com/azjezz/psl>
  Closest existing thing to this sketch; inspired by HHVM's HSL. Goal: "a
  consistent, centralized, well-typed set of APIs ... that error predictably,"
  using typed exceptions and Result/Option instead of `false`/`null`/`-1`.
  Worth studying for its namespaced layout (`Psl\Str`, `Psl\Vec`, `Psl\Dict`,
  `Psl\Iter`). Position our design against it.
- **Symfony String** — <https://symfony.com/doc/current/string.html>
  Unifies the `str_*`/`mb_*` split into one API across bytes / code points /
  graphemes. Real-world precedent for our `str` (UTF-8) vs `bytes` split.
- **Python PEP 8 + PEP 3108** — <https://peps.python.org/pep-0008/> ·
  <https://peps.python.org/pep-3108/>
  One `snake_case` convention from the start; Python 3 used the major-version
  break to rename pre-PEP-8 modules. But PEP 8 concedes "we'll never get this
  completely consistent," and a later mass-aliasing proposal was killed.
  **Lesson:** convention up front beats retrofit; greenfield is the right call.

### Unverified / to chase

- The Rasmus Lerdorf anecdote that early function names were sized to a
  `strlen`-based hash table (the supposed origin of the underscore mess) is
  widely repeated but **no primary source found** — treat as folklore until sourced.
- Go's `std` packages and Rust's `std` are often cited as positive models but
  weren't pinned to a specific PHP-discussion source here — chase if needed.
