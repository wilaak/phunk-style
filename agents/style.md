<!-- derived from ../docs/STYLE.md — terse rules only; edit the source, then regenerate -->

## syntax-casing
Name local variables, properties, and parameters `snake_case`.
Name free functions `snake_case`; name methods `camelCase`.
Name records, classes, interfaces, traits, enums, and enum cases `PascalCase`.
Name namespaces `snake_case`.
Quote string array keys as `snake_case`.
Name constants and goto labels `UPPER_SNAKE_CASE`.
Treat acronyms as words: case first letter per the rule, rest lowercase (`HttpClient`, `parseXmlId()`, `$user_id`).
Keep built-in and third-party identifiers in their source casing.

## naming-things
Avoid abbreviations except loop counters; use one concept per name and never reuse a name for two concepts in one scope.
Keep related names similar in length; append units by descending significance (`$latency_ms_max`, `$size_bytes_total`).

## error-handling
Return expected failures as values (union or `?T`); reserve exceptions for unrecoverable failures that must crash fast.
Always handle errors; never ignore a return.
Release every acquired resource in `finally`, keeping acquire and release adjacent.

## assertions
Assert what must be true; aim for two per function, checking arguments, results, and impossibilities.
Write one assert per line (not `assert($a && $b)`).
Assert bugs only, never input.
Stay bare in hot loops over bulk data.

## comments
Explain why, not what.
Use `//` comments; reserve `/** */` for types PHP cannot express or to comment out sections.
Mark sections with `// TODO:`, `// FIXME:`, `// MARK:`.
Wrap long foldable sections in `// region` / `// endregion`.

## clear-is-clever
Write the boring, decoded-out version; split dense expressions into named steps.
Always say why.

## put-a-limit-on-everything
Bound every loop and queue with a max.

## push-ifs-up-fors-down
Keep branches and state in the parent; make helpers take plain values and return plain values.

## be-a-never-nester
Return early with guards; keep the happy path flat at the bottom.
Split long procedures into helpers; prefix a single-caller helper with the parent name and mark it `#[Local]`.

## state-invariants-positively
State what must hold, not its negation (`if ($index < $count)`, not `if (!($index >= $count))`).

## long-signatures
Keep signatures on one line within 100 columns; otherwise one parameter per line, names aligned, trailing comma, return type on the closing line.

## pass-options-explicitly
Spell out significant options at the call site (`account_search($query, limit: 50, include_closed: false)`).

## batch-work
Process in bulk: gather, process, commit once; lay data out for a single in-order sweep.

## run-at-your-own-pace
Enqueue external events onto a bounded queue and drain them on your own tick; coalesce duplicates before working.

## no-magic
Use real, greppable functions; no hidden dispatch like `__call`.

## money-as-integer
Represent money as integer minor units (cents), never a float.

## few-dependencies
Prefer the standard library and your own code; add a dependency only when it earns its keep and keep it behind your own edge.

## other-misc-stuff
Limit lines to 100 columns; exceed only when breaking hurts more.
Put opening brace on its own line for classes, methods, functions; same line for control structures; always use braces.
Leave one blank line after the namespace, after the `use` block, and between functions.
Use trailing commas in multi-line arrays and argument lists.
