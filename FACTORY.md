# The factory model

> [!NOTE]
> This is an exploratory sketch, not settled style. It builds on the assembly-line
> idea from [README.md](README.md) and asks: if a program is a set of assembly
> lines, what if we described the whole factory as data and ran it with one small
> engine? The payoff would be a system you can monitor and visualize for free. The
> rules in [MODULES.md](MODULES.md) and [OPTIMIZATIONS.md](OPTIMIZATIONS.md) still
> apply; this only adds a way to wire stations together.

## The idea

[README.md](README.md) frames every program as an assembly line: data in one end,
stations along the way, a result out the other. The loop section drives one such
line on a timer. This document generalizes that to a graph of lines and makes one
claim:

> [!TIP]
> Describe the factory as data, run it with one small engine, and monitoring and
> visualization fall out instead of being bolted on.

The shared thing between stations is not a base class. It is a uniform function
signature and a flat table that lists the stations and the belts between them. The
topology is data you can print, diff, hot-swap, and draw. The work inside a station
is ordinary concrete code.

## Stations and belts

A station has one job. The vocabulary is fixed and small, on purpose: a fixed
alphabet is what makes a factory legible at a glance. Each role is a verb-suffix on
the usual subject-prefixed name (see [README.md](README.md) naming rules).

| Factorio | Station | Role                                   | Reads like                      |
| -------- | ------- | -------------------------------------- | ------------------------------- |
| Miner    | Source  | pulls buffered input onto the line     | `net\drain`, `queue\take`       |
| Inserter | Edge    | crosses a boundary: raw to typed       | `request_parse`, `frame_write`  |
| Assembler| Step    | transforms a batch, pure               | `world_step`, `view_render`     |
| Splitter | Router  | partitions or filters a batch onward   | `cmd_route`, `event_partition`  |
| Chest    | Buffer  | holds a batch between stations (a noun) | `World`, `Inbox`, `Outbox`     |

The spine is three: Source, Step, Sink (gather, decide, commit). Router and Buffer
are what you add once the line branches or carries state across ticks, which is
exactly when a program grows too large to hold in your head.

> [!NOTE]
> Five is the budget, not a starting point. If a station is not one of these, it is
> probably doing two things. Keep the alphabet tiny or the factory stops being
> legible.

## The shared contract is a signature

A station is a plain free function with one shape: a batch in, a batch out, with
capabilities through `$env`.

```php
namespace app\factory;

use app\env;

/**
 * The station contract. Not a base class: just the signature every station shares.
 *
 * @var \Closure(list<mixed>, env\Env): list<mixed>
 */
```

If you want the analyzer to enforce the shape, a single-method interface is the
guide-sanctioned way to type a contract callback (see [MODULES.md](MODULES.md), "No
premature abstraction"). There is no `Station` taxonomy, no `AbstractEnricherStage`.
One shape, end to end.

The belts between stations are buffers, the only stateful station:

```php
namespace app\factory;

final class Buffer
{
    /** @var list<mixed> The batch waiting on this belt. Refilled each tick. */
    public array $items = [];
}

function buffer_drain(Buffer $buffer): array
{
    $items         = $buffer->items;
    $buffer->items = [];
    return $items;
}

function buffer_fill(Buffer $buffer, array $items): void
{
    foreach ($items as $item) {
        $buffer->items[] = $item;
    }
}
```

## The factory is a table

A station is a record naming its inbound belt, its outbound belt, and the function
to run. The whole factory is a flat list of these, built at the composition root.
This is the same move [MODULES.md](MODULES.md) makes for wiring ("the composition
root is the dependency graph written out"), now applied to the dataflow: the table
is the graph written out.

```php
namespace app\factory;

final class Station
{
    public string $name = '';

    /** Index into the buffer pool: the inbound belt. */
    public int $in = 0;

    /** Index into the buffer pool: the outbound belt. */
    public int $out = 0;

    /** @var \Closure(list<mixed>, \app\env\Env): list<mixed> */
    public \Closure $run;
}
```

## One engine runs it

The driver is one generic loop over the table. Because every station crosses it,
every station is measured at the same point.

```php
namespace app\factory;

use app\env;

//
// One factory tick: run every station once, in order. Drain its inbound belt,
// run it over the whole batch, fill its outbound belt. The driver is the shell;
// each station's $run is the pure core.
//
function factory_tick(array $stations, array $buffers, env\Env $env, Metrics $metrics): void
{
    foreach ($stations as $station) {
        $started_ns = \hrtime(true);

        $batch  = buffer_drain($buffers[$station->in]);
        $result = ($station->run)($batch, $env);
        buffer_fill($buffers[$station->out], $result);

        $elapsed_ns = \hrtime(true) - $started_ns;
        metrics_record($metrics, $station->name, \count($batch), $elapsed_ns);
    }
}
```

The generic dispatch is per-tick, never per-element. Inside a station's `$run` the
work is a tight, concrete, inlined loop over a flat array, so the hot-path rules in
[OPTIMIZATIONS.md](OPTIMIZATIONS.md) still hold: generic at the coarse grain for
legibility, concrete at the fine grain for speed.

## A drivable loop

`factory_tick` above is one host driving one factory. For loops to interoperate, to
nest, to be hosted under any framework, and to be tested deterministically, they
need a shared way to be driven.

> [!TIP]
> A loop is driven, not self-driving. It exposes a `step`, and the host owns the
> clock.

A self-driving loop, one that owns its own `Timer::tick` (see [README.md](README.md),
"A loop at the center"), cannot be embedded or tested. Invert it: expose `step` and
let whoever hosts the loop decide when to call it. The interop surface is three
things you already have, an inbox, an outbox, and `step`:

```php
namespace app\factory;

//
// The interop contract. The host owns the clock and calls step; the loop fills its
// outbox from its inbox. A method, not a free function, is the right call here: a
// loop is a long-lived instance with a lifecycle, and a host driving a mixed set of
// loops is the "boundary needs polymorphism" case (see MODULES.md, No premature
// abstraction). step returns items processed, so the host can spot an idle loop (0)
// or one falling behind (fewer than its inbox holds).
//
interface Loop
{
    public function step(float $dt): int;
}
```

A loop carries its belts as public fields and advances behind `step`:

```php
namespace app\world;

use app\factory;

final class WorldLoop implements factory\Loop
{
    public factory\Buffer $inbox;
    public factory\Buffer $outbox;

    public World $world;

    public function step(float $dt): int
    {
        $batch = factory\buffer_drain($this->inbox);

        world_step($this->world, $batch, $dt);          // decide (pure core)
        factory\buffer_fill($this->outbox, world_emit($this->world));

        return \count($batch);
    }
}
```

This is the same shape as a station, batch in, advance, batch out, just with the
batch held in named belts and the advance behind `step`. So a loop is a station at a
larger scale, and loops nest: a child loop drops into a parent factory as one
station, its `step` called from the parent's tick. One contract runs from a single
`_step` function up to a whole hosted system.

The host is whatever owns the clock, and that changes per setting while the loop does
not:

```php
// Top-level host: a real timer owns the cadence.
$loop = new world\WorldLoop(/* ... */);
Timer::tick(50, static fn () => $loop->step(0.05));

// Test host: drive it by hand, deterministically, from recorded input.
factory\buffer_fill($loop->inbox, $recorded_batch);
$loop->step(0.05);
assert_equals($expected, $loop->outbox->items);

// Parent host: a bigger loop drives the child inside its own tick, then pipes
// the child's outbox onward.
$child->step($dt);
factory\buffer_fill($next_inbox, factory\buffer_drain($child->outbox));
```

What the contract unlocks:

- Host anywhere. Swoole, ReactPHP, or a bare CLI loop fills the inbox from its I/O
  and calls `step` on its timer. The loop does not know who drives it.
- Nest and pipe. A parent shuttles a child's outbox into the next inbox, a DAG of
  loops under the same no-cycle rule as the module DAG.
- One set of tools. Any monitor, visualizer, or replayer works on any loop, because
  all of them expose the same inbox, outbox, and stats.
- Deterministic tests. Record the inbox, drive `step` with a fixed `dt`, diff the
  outbox. The record-and-replay [README.md](README.md) "Stretched context" asked
  for, now standard.

> [!NOTE]
> Two boundaries. First, `step` returning a count is the smallest thing that keeps a
> backed-up child from silently stalling its parent; resist growing it past a simple
> progress or saturation signal. Second, a host that time-slices many loops is a
> scheduler, which is a runtime, which is the framework this guide avoids.
> Standardize the loop contract, which is tiny and shippable; do not standardize the
> scheduler until a concrete second host proves its shape. Nested loops ticking at
> different rates, and fixed versus variable `dt`, are the open questions there.

## Monitoring falls out

Because work happens in exactly one place, the numbers a factory HUD shows are a few
counters, not instrumentation threaded through every path.

```php
namespace app\factory;

final class StationStat
{
    public int $items_total = 0;
    public int $ticks_total = 0;
    public int $nanos_total = 0;
}

final class Metrics
{
    /** @var array<string, StationStat> Keyed by station name. */
    public array $by_station = [];
}

function metrics_record(Metrics $metrics, string $name, int $items, int $nanos): void
{
    $stat = $metrics->by_station[$name] ??= new StationStat();

    $stat->items_total += $items;
    $stat->ticks_total += 1;
    $stat->nanos_total += $nanos;
}
```

From these and the buffer fill levels you read the whole system:

- Throughput: items per station per tick, the items-per-second on every belt.
- Latency: nanoseconds per station, how long each machine took.
- Backpressure: a buffer's `items` count, the chest backing up. The bottleneck is
  visible, not inferred.
- Utilization: station time over the tick budget, which machine is saturated.

This is the loop section's "monitoring falls out of the structure" claim
generalized from one loop to an arbitrary graph.

## Visualization is the table plus the numbers

The station table already is a node-edge graph: each station a node, each shared
buffer index an edge. Emit it and you are looking at your factory; overlay the
metrics and the belts show their rates.

```php
namespace app\factory;

//
// Render the factory as Graphviz DOT, edges labelled with throughput. The diagram
// is derived from the same table the engine runs, so it can never drift from it.
//
function factory_dot(array $stations, Metrics $metrics): string
{
    $lines = ["digraph factory {"];

    foreach ($stations as $station) {
        $stat = $metrics->by_station[$station->name] ?? null;
        $rate = $stat !== null && $stat->ticks_total > 0
            ? \intdiv($stat->items_total, $stat->ticks_total)
            : 0;

        $lines[] = \sprintf(
            '  buf%d -> buf%d [label="%s: %d/tick"];',
            $station->in,
            $station->out,
            $station->name,
            $rate,
        );
    }

    $lines[] = "}";
    return \implode("\n", $lines);
}
```

A new engineer reads the composition root for the floor plan, the table for the
belts, and the live diagram for the flow rates, without opening a single station
body.

## Routers and joiners: the rough edge

The flat table assumes one belt in, one belt out. That holds for a straight line
and breaks the moment the flow forks or merges. This is the part to prototype before
believing the model, because it is where the simple shape strains.

Two honest options:

- Lists for `in` and `out`. A station reads from several belts and writes to
  several, returning a batch per outbound slot. The signature grows a little: the
  return is keyed by local out-slot rather than a bare list. Still data, still
  uniform, slightly less clean.
- An emitter argument. The station takes an `Emit` it calls to place items on named
  belts: `fn(array $batch, Emit $emit, env\Env $env): void`. This handles fan-out
  and fan-in cleanly, at the cost of a per-batch callback (per-batch, never
  per-element, so the hot-path warning does not apply).

Neither is obviously right yet. The straight-line table is the part worth shipping
first; routing is the open question.

## Flexibility

Because the topology is data, you reshape the factory by editing rows, not by
rewiring an object graph:

- Reorder, insert, or remove a station by changing the table.
- A/B test or feature-flag by swapping a station's `$run`.
- Test a station in isolation: it is a pure function over a batch.
- Test a sub-factory by running a sub-table through the same driver.

## Boundaries

> [!NOTE]
> This is a framework, and this guide is proudly anti-framework. The defense is that
> it stays a thin in-house driver, tens of lines, with an inspectable table. The
> gravity well here pulls toward reinventing a heavyweight stream engine (Flink,
> Storm, Akka). The whole value is that it does not. The moment it grows a config
> DSL and a scheduler, it has become the thing it set out to avoid.

- The uniform `array` belt is a real constraint: everything must express itself as
  a batch in, a batch out. Clean for streams, awkward for side inputs and joins (see
  routers above).
- The metrics are a temptation. Watching the numbers is a game; keep the
  observability serving debugging and capacity, not vanity.
- Per-tick generic dispatch is cheap, but do not let the generic driver leak into
  the per-element loop inside a station. Coarse grain generic, fine grain concrete.

## How it fits the rest

The factory model is not a new architecture so much as three things the other docs
already describe, seen as one:

- The loop ([README.md](README.md), "A loop at the center") is the driver.
- Modules ([MODULES.md](MODULES.md)) own stations and the buffers between them; a
  module's public stations are its surface.
- The composition root builds the station table the same way it builds the
  dependency graph: plain data, read top to bottom, no container.

A program is then a factory described as data and run by one small engine, which is
the most literal reading of "a program is an assembly line" the guide can offer.
