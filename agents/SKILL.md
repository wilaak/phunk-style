---
name: phunk-php-style
description: Use when writing, reviewing, or refactoring PHP in this repo's PHUNK style — triggered by PHUNK, snake_case namespaces imported as prefixes, subject-prefixed free functions, errors-as-values-not-exceptions, packed-array bulk data, and the str/slice/map stdlib wrappers.
---

PHUNK is data-first, procedural PHP: design the data, then write free functions over it in flat `snake_case` modules imported as prefixes. Return failures as values, reserve exceptions for crashes. Keep the happy path flat, say why, bound every loop, and reach for packed scalars over object collections in hot paths.

| topic | rule | file |
| --- | --- | --- |
| syntax-casing | snake_case vars/funcs/namespaces, camelCase methods, PascalCase types, UPPER constants; acronyms as words | agents/style.md#syntax-casing |
| naming-things | one concept per name; units by descending significance | agents/style.md#naming-things |
| error-handling | failures as `?T`/union values; exceptions only for unrecoverable; release in `finally` | agents/style.md#error-handling |
| assertions | ~2/function, one per line, assert bugs not input, bare in hot loops | agents/style.md#assertions |
| comments | `//` explaining why; `/** */` only for inexpressible types | agents/style.md#comments |
| clear-is-clever | boring decoded version, named steps, always say why | agents/style.md#clear-is-clever |
| put-a-limit-on-everything | bound every loop and queue with a max | agents/style.md#put-a-limit-on-everything |
| push-ifs-up-fors-down | branches in parent; helpers take/return plain values | agents/style.md#push-ifs-up-fors-down |
| be-a-never-nester | guard-return, flat happy path; single-caller helpers `#[Local]` | agents/style.md#be-a-never-nester |
| state-invariants-positively | `if ($index < $count)`, not the negation | agents/style.md#state-invariants-positively |
| long-signatures | one line <=100 cols, else one param/line aligned, trailing comma | agents/style.md#long-signatures |
| pass-options-explicitly | name significant options at the call site | agents/style.md#pass-options-explicitly |
| batch-work | gather, process, commit once; single in-order sweep | agents/style.md#batch-work |
| run-at-your-own-pace | bounded queue, drain on your tick, coalesce dupes | agents/style.md#run-at-your-own-pace |
| no-magic | real greppable functions, no `__call` dispatch | agents/style.md#no-magic |
| money-as-integer | money as integer minor units, never float | agents/style.md#money-as-integer |
| few-dependencies | prefer stdlib/own code; deps behind your own edge | agents/style.md#few-dependencies |
| other-misc-stuff | 100 cols, brace placement, blank lines, trailing commas | agents/style.md#other-misc-stuff |
| modules quick-rules | flat namespace word under vendor, import namespace, mark internals, load at startup | agents/modules.md#quick-rules |
| what-a-module-owns | a subsystem of types + functions, err coarse | agents/modules.md#what-a-module-owns |
| module naming | one short noun prefix; name the subsystem; no dumping grounds | agents/modules.md#naming |
| layout-on-disk | `vendor\module` mirrored on disk, flat, group 3+ siblings a tier deep | agents/modules.md#layout-on-disk |
| imports | `use` the namespace; no `use function`/`const`; alias only on collision | agents/modules.md#imports |
| module-boundaries | split on "and"/two change-reasons; deps down the DAG; pass via signature/`$env` | agents/modules.md#module-boundaries |
| dispatch-closed-set | enum + central `match`, free fn per arm; interface only for runtime strangers | agents/conventions.md#dispatch-closed-set-vs-open-set |
| sum-type-with-data | record per variant, return union, exhaustive `match` | agents/conventions.md#sum-type-with-data |
| subject-prefixed-functions | free fns prefixed with subject type (`account_can_debit`) | agents/conventions.md#subject-prefixed-functions |
| variable-scope | declare at narrowest scope, init where declared | agents/conventions.md#variable-scope |
| data-design | design data first; compact, flat arrays; wide public structs, few writers | agents/conventions.md#data-design |
| handles-not-references | refer by integer index into array, not object | agents/conventions.md#handles-not-references |
| state-and-values | never duplicate to remember; consolidate writes; fresh name on role change | agents/conventions.md#state-and-values |
| off-by-one | distinguish index/count/size by name; comment division rounding | agents/conventions.md#off-by-one |
| bulk-data-no-objects | packed scalar array or byte buffer, never object collections | agents/optimizations.md#bulk-data-do-not-use-objects |
| returns-avoid-allocation | scalar return; by-ref out-params; reusable buffer detached from property | agents/optimizations.md#returns-avoid-per-call-allocation |
| mutate-cow-in-place | take large string/array by ref, mutate in place | agents/optimizations.md#mutate-copy-on-write-values-in-place |
| pass-a-view | pass `(buffer, offset, len)` not a copied slice | agents/optimizations.md#pass-a-view-not-a-copy |
| reuse-scratch-buffer | one scratch buffer truncated per tick, no realloc | agents/optimizations.md#reuse-a-scratch-buffer-per-tick |
| layout-aos-vs-soa | AoS when fields read together, SoA when hot loop touches one/two | agents/optimizations.md#layout-aos-vs-soa |
| bit-packing | pack small values into one 64-bit int behind named accessors | agents/optimizations.md#bit-packing |
| sentinel-delimited-runs | mark run boundaries with in-band sentinel, not stored lengths | agents/optimizations.md#sentinel-delimited-runs |
| bind-global-symbols | prefix global functions with `\` inside a namespace | agents/optimizations.md#bind-global-symbols-explicitly |
| constants-in-class | class constants in a `final` container; no namespace `const`/`define()` | agents/optimizations.md#wrap-constants-in-a-class-container |
| cast-packed-reads | cast each packed-array read once into a typed local | agents/optimizations.md#cast-scalars-read-from-packed-arrays |
| dispatch-by-match | pass an int tag and `match`, not a closure; hoist invariant match | agents/optimizations.md#dispatch-by-match-not-by-callback |
| goto-state-machine | `goto` between labels for hot multi-state machines only | agents/optimizations.md#jump-with-goto-in-a-hot-state-machine |
| inlining-and-dispatch | short hot bodies, scalar args, no interface/abstract dispatch in hot paths | agents/optimizations.md#inlining-and-dispatch |
| stdlib str | `str\len/slice/split/trim/contains/replace/join` over PHP string fns | agents/stdlib.md#str |
| stdlib bytes | `bytes\len/slice/to_hex/pack` for byte-level ops | agents/stdlib.md#bytes |
| stdlib slice | `slice\map/filter/reduce/sort_by/contains/take/first` over arrays | agents/stdlib.md#slice |
| stdlib map | `map\get/get_or/has/merge/map_values/invert` for keyed arrays | agents/stdlib.md#map |
| stdlib iter | `iter\map/filter/of/collect/for_each` (eager) | agents/stdlib.md#iter |
| stdlib option-conv-fmt | `option\is_some`/`unwrap_or`, `conv\to_int`, `fmt\int/float/bytes` | agents/stdlib.md#option-conv-fmt |

Read the full doc in ../<NAME>.md only when a task needs rationale or examples.
