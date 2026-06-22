---
name: phunk-php-style
description: >
  Use when writing, reviewing, or refactoring PHP in this repo's PHUNK style —
  procedural and data-oriented, with snake_case namespaces, values-not-exceptions
  error handling, subject-prefixed free functions, and packed-array hot paths.
---

# PHUNK PHP style

Procedural, data-oriented PHP: programs are conveyor belts transforming data
through staged free functions. Carry data in structs of public typed fields;
put behavior in free functions that take the struct first. Expected failures are
values, not exceptions. Everything is greppable — no magic.

Navigate by the table below. Each `agents/*.md` is the terse rule list (load
first); read the full `*.md` source only when a task needs rationale or examples.

| topic | one-line rule | rules file |
| --- | --- | --- |
| casing | snake_case values/functions/namespaces, PascalCase types | [agents/style.md#syntax-casing](../../../agents/style.md#syntax-casing) |
| naming | one concept per name; units by descending significance | [agents/style.md#naming-things](../../../agents/style.md#naming-things) |
| errors | return failures as values; crash fast only when unrecoverable | [agents/style.md#error-handling](../../../agents/style.md#error-handling) |
| assertions | assert bugs not input; one per line; two per function | [agents/style.md#assertions](../../../agents/style.md#assertions) |
| comments | `//` for prose, `/** */` only for types PHP can't state | [agents/style.md#comments](../../../agents/style.md#comments) |
| control flow | guard early, never nest; push ifs up, fors down | [agents/style.md#be-a-never-nester](../../../agents/style.md#be-a-never-nester) |
| limits | bound every loop and queue | [agents/style.md#put-a-limit-on-everything](../../../agents/style.md#put-a-limit-on-everything) |
| signatures | one line under 100 cols, else one param per line aligned | [agents/style.md#long-signatures](../../../agents/style.md#long-signatures) |
| money | integer minor units, never float | [agents/style.md#money-as-integer](../../../agents/style.md#money-as-integer) |
| modules | one flat namespace word; import the namespace, keep the prefix | [agents/modules.md#quick-rules](../../../agents/modules.md#quick-rules) |
| module naming | name the subsystem as a prefix; no dumping grounds | [agents/modules.md#naming](../../../agents/modules.md#naming) |
| imports | namespace imports only; no `use function`/`use const` | [agents/modules.md#imports](../../../agents/modules.md#imports) |
| boundaries | dependencies point down the DAG; pass via signature/`$env` | [agents/modules.md#module-boundaries](../../../agents/modules.md#module-boundaries) |
| dispatch | enum + central `match` for closed sets; interface for open | [agents/conventions.md#dispatch-closed-set-vs-open-set](../../../agents/conventions.md#dispatch-closed-set-vs-open-set) |
| sum types | record per variant, return union, exhaustive `match` | [agents/conventions.md#sum-type-with-data](../../../agents/conventions.md#sum-type-with-data) |
| data design | flat, compact, key-not-pointer; wide structs by access | [agents/conventions.md#data-design](../../../agents/conventions.md#data-design) |
| handles | refer to records by integer index, not object | [agents/conventions.md#handles-not-references](../../../agents/conventions.md#handles-not-references) |
| return types | prefer simplest: `void > bool > int > ?int > value-or-exception` | [agents/conventions.md#state-and-values](../../../agents/conventions.md#state-and-values) |
| off-by-one | distinguish index/count/size; name them | [agents/conventions.md#off-by-one](../../../agents/conventions.md#off-by-one) |
| bulk data | packed arrays/byte buffers, never object collections | [agents/optimizations.md#bulk-data-do-not-use-objects](../../../agents/optimizations.md#bulk-data-do-not-use-objects) |
| allocation | scalar returns, by-ref out-params, reusable buffers | [agents/optimizations.md#returns-avoid-per-call-allocation](../../../agents/optimizations.md#returns-avoid-per-call-allocation) |
| layout | AoS when fields read together, SoA for one-field hot loops | [agents/optimizations.md#layout-aos-vs-soa](../../../agents/optimizations.md#layout-aos-vs-soa) |
| bit-packing | pack into 64-bit ints behind named accessors | [agents/optimizations.md#bit-packing](../../../agents/optimizations.md#bit-packing) |
| constants | class-constant containers; never `define()`/namespace const | [agents/optimizations.md#wrap-constants-in-a-class-container](../../../agents/optimizations.md#wrap-constants-in-a-class-container) |
| stdlib | `use std\str;` etc.; subject first, closures last | [agents/stdlib.md#str](../../../agents/stdlib.md#str) |

Measure before optimizing — the optimization rules apply to proven hot paths only.
