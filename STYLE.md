# Style is design 

Our design goals are safety, performance, and developer experience. In that order. All three are important. Good style advances these goals. Does the code make for more or less safety, performance or developer experience? That is why we need style.

We draw inspiration from the excellent [TigerStyle](https://github.com/tigerbeetle/tigerbeetle/blob/main/docs/TIGER_STYLE.md) where applicable to PHP. Much of the below stems from that.

## Syntax casing

> [!NOTE]   
> snake_case namespaces are deliberately against the usual PascalCase to avoid the confusion with types.
> Built-in and third-party that break this keep their source casing, we can't do much about that.

| Construct                     | Casing             | Example                    |
| ----------------------------- | ------------------ | ----------------           |
| Variable, local               | `snake_case`       | `$order_line`              |
| Variable, property            | `snake_case`       | `$created_at`              |
| Variable, parameter           | `snake_case`       | `int $row_count`           |
| Function, free                | `snake_case`       | `order_total()`            |
| Function, method              | `camelCase`        | `getTotal()`               |
| Type, record (struct)         | `PascalCase`       | `OrderLine`                |
| Type, class (object)          | `PascalCase`       | `HttpClient`               |
| Type, interface               | `PascalCase`       | `Comparable`               |
| Type, trait                   | `PascalCase`       | `Timestamps`               |
| Type, enum                    | `PascalCase`       | `OrderStatus`              |
| Type, enum case               | `PascalCase`       | `OrderStatus::InTransit`   |
| Namespace                     | `snake_case`       | `app\order_book`           |
| Array key, string             | `snake_case`       | `'order_line'`             |
| Constant                      | `UPPER_SNAKE_CASE` | `MAX_SIZE`                 |
| Goto label                    | `UPPER_SNAKE_CASE` | `PARSE_END`                |

Treat acronyms as words: first letter cased per the rule, the rest lowercase.

- `HttpClient`, not `HTTPClient`
- `parseXmlId()`, not `parseXMLID()`
- `$user_id`, not `$user_iD`


## Prioritize naming and flow

Prioritize naming and flow: if code reads naturally, comments are mostly for non-obvious constraints.

### Choosing a name

Avoid excessive abbreviations, exceptions being things like loop counters or similar. Example: write `$buffer`, not `$buf`. Use a concept per name, and avoid reusing a name for two concepts in one scope.

Try keeping related names at similar length and balance as they are easier to scan side by side. Append units by descending significance so related names group: `$latency_ms_max`, `$size_bytes_total`.

Longer names can be fine and if they are doing their job by explicit about what the thing is or does. If it is going to be used heavily and in many places it could be wise to think about lenght as it can be derived from context.

You may refer this very scientific graph below.

![Naming discoverability chart](./assets/naming-things-discoverability.webp)

## Handle all errors

Expected failures are values, not exceptions. Reserve exceptions for cases that are genuinely exceptional. Throwing is a panic, it unwinds to the top of the request, gets logged or killed, not the server. Always handle errors and never ignore a return.

```PHP
enum AccountError
{
    case NotFound;
    case Frozen;
    case InsufficientFunds;
}

function account_debit(
    Account $account,
    int     $amount_cents,
): Account|AccountError
{
    if ($account->frozen) {
        return AccountError::Frozen;
    }
    if ($account->balance_cents < $amount_cents) {
        return AccountError::InsufficientFunds;
    }

    $account->balance_cents -= $amount_cents;
    return $account;
}

function account_debit_page(
    Account $account,
    int     $amount_cents,
): Response
{
    $result = account_debit($account, $amount_cents);
    if ($result instanceof Account) {
        return http_200(render_balance($result));
    }

    return match ($result) {
        AccountError::Frozen            => http_409('frozen'),
        AccountError::InsufficientFunds => http_402(),
        AccountError::NotFound          => http_404(),
    };
}
```

## Explain why, not what

Comments explain why, not what; well-named identifiers say what. Comment a hidden constraint, a non-obvious invariant, a workaround, or surprising behavior.

Documentation that belongs to a symbol goes in a `/** */` docblock, so the IDE surfaces it on hover static analysis check it.

A record or object earns one too, on the type itself.

```php
// =============================================================================
// Avoid: type doc as a banner the IDE never sees; notes to the right run wide
// =============================================================================

//
// Ring buffer (bounded FIFO). Storage is reused in a circle; slot is
// counter & (capacity - 1), so capacity MUST be a power of two.
//
class RingBuffer
{
    public int $head = 0;     // total pushed
    public int $tail = 0;     // total popped
    public int $capacity = 0; // power of two; the AND wrap depends on it
}

// =============================================================================
// Good: type doc on the type; property notes above, so lines stay narrow
// =============================================================================

/**
 * Ring buffer (bounded FIFO). Storage is reused in a circle; slot is
 * counter & (capacity - 1), so capacity MUST be a power of two.
 */
class RingBuffer
{
    /**
     * Total pushed.
     */
    public int $head = 0;

    /**
     * Total popped.
     */
    public int $tail = 0;

    /**
     * Power of two; the AND wrap depends on it.
     */
    public int $capacity = 0;
}

// =============================================================================
// Document a type PHP cannot state: an array's element layout,
// a packed buffer's stride, or a local closure's signature.
// =============================================================================

/**
 * @param list<float>         $points  Flat [lat, lng, lat, lng, ...]; stride 2.
 * @param \Closure(int): bool $keep    Local predicate over a point index.
 */
function points_filter(array $points, \Closure $keep): array
```

You can use ablock with a fixed 80 columns.

```php
// =============================================================================
// Helpers
// =============================================================================
```

Keep the label within the rule; if it runs long, wrap it across lines rather than
letting it flow past the rule:

```php
// bad: label flows wider than the rule

// =============================================================================
// Account import: validate every row, then persist the whole batch in one transaction
// =============================================================================

// good: wrap the label so it stays within the rule

// =============================================================================
// Account import:
// validate every row, then persist the whole batch in one transaction
// =============================================================================
```

## Clear is clever

Write the boring version. The reader should not have to decode. Simple is hard, not the first version you write. It is the last one, after you have understood the problem well enough to make it look easy. Take the time.

Always say why.

```PHP
// clever: decode it line by line
$b = $items[($h = crc32($k)) % $n];

// simple: just read it
$hash = crc32($k);
$slot = $hash % $n;
$b    = $items[$slot];
```

## Assertions

Assert what must be true. A wrong belief should crash here, not corrupt data later. Aim for two per function. Check arguments, results, and what must never happen.

```PHP
function account_debit(Account $account, int $amount_cents): void
{
    // expect
    assert($amount_cents > 0);
    // must never happen
    assert(!$account->frozen);

    $account->balance_cents -= $amount_cents;

    // result holds
    assert($account->balance_cents >= 0); 
}
```

One assert per line, so you know which one fired.

```PHP
assert($a);
assert($b); 
// not: assert($a && $b);
```

Asserts are for bugs, never for input. 

### Control vs data planes

For control planes: setup, routing, decisions. Rare, assert everything. For data planes: the hot loop over bulk data, keep asserts and branches out.

```PHP
// control plane: cheap to check, check hard
function scan_start(string $buffer, int $mode): array
{
    assert($buffer !== '');
    assert($mode === Scan::CSV || $mode === Scan::TSV);
    return scan_run($buffer, $mode);
}

// data plane: no asserts, no allocation, just the work
function scan_run(string $buffer, int $mode): array { /* tight loop */ }
```

Assert freely where it's cheap, stay bare where it's hot.

## Put a limit on everything

Every loop and queue has a max. Unbounded means one bad input takes the server down.

```PHP
// bad: grows forever
while (ring_pop($ring, $x)) {
    $batch[] = $x;
}

// good: drain at most a batch
$max = 1024;
for ($i = 0; $i < $max && ring_pop($ring, $x); $i++) {
    $batch[] = $x;
}
```

## Push ifs up, fors down

The parent decides. The helpers do.

Branches and state live in the parent. Helpers take plain values and return plain values, no questions about who called them, no writing back.

```PHP
// parent: owns the branch and the state
function account_import(array $rows): int
{
    $valid = account_rows_valid($rows);
    if (!$valid) {
        return 0;
    }
    return account_rows_persist($rows);
}

// leaf: pure, decides nothing, just answers
function account_rows_valid(array $rows): bool
{
    foreach ($rows as $row) {
        if ($row['amount'] < 0) {
            return false;
        }
    }
    return true;
}
```

Pure leaves test alone and read top to bottom.

## Never nest

Deep nesting hides the path. Return early, handle the bad case, get out. Each level should be the happy path going down, not a staircase.

```PHP
// bad: the real work is buried three levels deep
function account_debit(Account $account, int $amount_cents): bool
{
    if (!$account->frozen) {
        if ($amount_cents > 0) {
            if ($account->balance_cents >= $amount_cents) {
                $account->balance_cents -= $amount_cents;
                return true;
            }
        }
    }
    return false;
}

// good: guards out, work flat at the bottom
function account_debit(Account $account, int $amount_cents): bool
{
    if ($account->frozen) {
        return false;
    }
    if ($amount_cents <= 0) {
        return false;
    }
    if ($account->balance_cents < $amount_cents) {
        return false;
    }

    $account->balance_cents -= $amount_cents;
    return true;
}
```

## State invariants positively

Say what should be true, not what shouldn't. A positive test reads straight and the boundary is obvious.

```PHP
// harder: a negation, and the boundary is fuzzy
if (!($index >= $count)) { ... }

// clear: the thing that must hold
if ($index < $count) { ... }
```

## Long procedures 

Ask if it's doing one thing or several. Often it's several things, you can pull those out so the parent reads as steps.

```PHP
// the parent reads like a table of contents
function account_import(array $rows): int
{
    $clean = account_rows_clean($rows);
    $valid = account_rows_valid($clean);
    return account_rows_persist($valid);
}
```

## Pass options explicitly

Spell out the options that matter at the call site. A default that changes under you is a silent bug.

```PHP
// hidden: the defaults decide; change one and every caller shifts silently
$rows = account_search($query);

// explicit: the call states what it wants
$rows = account_search($query, limit: 50, include_closed: false);
```

## Batch work and let the CPU sprint

Big straight runs are much faster than ping ponging between tasks, so amortize it: gather, process, commit in bulk.

```PHP
// bad: a round-trip per row
foreach ($orders as $order) {
    order_save($db, $order);
}

// good: one sweep, one round-trip
order_save_many($db, $orders);
```

Lay data out so a pass reads it in order, then sweep it once. Cache-efficient chunking comes first.

## Run at your own pace

Don't react to each external event the moment it lands. Take it onto a queue and work it on your own loop. A steady tick is predictable; an event storm can't knock you over.

Owning the loop buys a lot:

A tick holds many items at once, so you batch. One query, one flush, Batching is efficient and cache friendly: the same work over a packed run of data, read in order. The CPU loves that style most of all.

You can also coalesce while you are there, collapsing ten "dirty" events into one redraw before doing any work.

The queue smooths the world. A bounded queue gives you backpressure, so under load you shed or reject instead of falling over, and bursts flatten into a steady rate with predictable latency.

And the statistics practically fall out from it. Queue depth is load, items per tick is throughput, time per tick is latency.

```PHP
// bad: handle each event inline, at the sender's pace
function on_message(Message $message): void
{
    message_process($message);   // a burst floods you
}

// good: enqueue now, drain on the tick, bounded
function on_message(RingBuffer $inbox, Message $message): void
{
    ring_push($inbox, $message);
}

function tick(RingBuffer $inbox): void
{
    $max = 1024;
    for ($i = 0; $i < $max && ring_pop($inbox, $message); $i++) {
        message_process($message);
    }
}
```

## Few dependencies

Every dependency is code you did not write running in your process: supply-chain risk, version churn, and surface you cannot see.

Prefer the standard library and a few lines of your own over a package. Add one only when it clearly earns its keep, and keep it behind your own edge so you can swap it.

## Other

- Line length 100 columns recommended; exceed only when breaking hurts more.
- Opening brace on its own line for classes, methods, functions; same line for control structures. Braces always.
- One blank line after the namespace and after the `use` block, and between functions.
- Trailing commas in multi-line arrays and argument lists.
