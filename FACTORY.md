# The factory model

> [!NOTE]
> This is an exploratory sketch, not settled style. The assembly-line approach in
> [README.md](README.md) is good on its own. This document asks what a small set of
> rules for *doing* it would add, and the answer is discoverability: a fixed, tiny
> vocabulary for the parts of a line, so you can read what a program does and how its
> data flows without opening a single function body. The data-as-table, the engine,
> and the monitoring further down are payoffs of following those rules, not the
> point. The conventions in [MODULES.md](MODULES.md) and
> [OPTIMIZATIONS.md](OPTIMIZATIONS.md) still apply.

## The idea

[README.md](README.md) frames every program as an assembly line, data in one end,
stations along the way, a result out the other, and argues it is easier to reason
about than a web of objects. That holds on its own. It gets much better with a few
conventions for the parts of a line, because then the structure is legible from the
outside:

> [!TIP]
> Name the parts of an assembly line from a fixed, tiny vocabulary, and you can read
> the flow of a whole program without reading its code.

Most of the rules are just naming. A few more let the line be described as data,
which is what later makes it monitorable and drawable. But discoverability is the
reason: a new engineer should be able to walk the factory floor and follow the belts
before opening anything. The shared thing between stations is a signature and a flat
table, not a base class.

## Rules at a glance

- Every function is a station with one of five roles, named by a verb-suffix:
  Source, Step, Sink, Router. Buffers are nouns.
- A Step is pure. Effects live only at the boundary stations, Source and Sink,
  never mid-line.
- State lives only in Buffers. Everything between them is a pure belt.
- The line's shape is described as data, a flat station table, not wired in code.
- One engine drives the table, so there is one place to measure the whole system.
- A line is driven, not self-driving: it exposes `step`, and the host owns the clock.

## Stations and belts

A station has one job. The vocabulary is fixed and small, on purpose: a fixed
alphabet is what makes a factory legible at a glance. Each role is a verb-suffix on
the usual subject-prefixed name (see [README.md](README.md) naming rules).

| Factorio  | Station | Role                                     | Reads like                     |
| --------- | ------- | ---------------------------------------- | ------------------------------ |
| Miner     | Source  | brings input onto the line, raw to typed | `net\drain`, `request_parse`   |
| Assembler | Step    | transforms a batch, pure                 | `world_step`, `view_render`    |
| Loader    | Sink    | sends output off the line, typed to raw  | `net\flush`, `response_write`  |
| Splitter  | Router  | partitions or filters a batch onward     | `cmd_route`, `event_partition` |
| Chest     | Buffer  | holds a batch between stations (a noun)  | `World`, `Inbox`, `Outbox`     |

The spine is three: Source, Step, Sink (gather, decide, commit). Parsing raw input
into typed values, and serializing it back, lives in the Source and Sink, at the
ends, never mid-line. Router and Buffer are what you add once the line branches or
carries state across ticks, which is exactly when a program grows too large to hold
in your head.

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

## A worked example: a stateful HTTP service

Enough in the abstract. Here is a small but real server built from stations: an
in-memory leaderboard behind an HTTP API. Writes mutate shared state, so it is the
stateful case the loop is for, not a stateless endpoint you would leave reactive.

The shared state, and the typed write that rides the belt:

```php
namespace app\score;

use Swoole\Coroutine\Channel;

// The shared state. Mutated only by the tick, so there are no locks.
final class Leaderboard
{
    /** @var array<string, int> player name -> total points */
    public array $points = [];
}

// A buffered write. Carries its reply channel, so the tick can resume the request
// coroutine that is parked waiting for the result.
final class Submit
{
    public string  $player = '';
    public int     $points = 0;
    public Channel $reply;
}

final class SubmitResult
{
    public int $rank  = 0;
    public int $total = 0;
}
```

The three stations, the Source, Step, Sink spine. The Step is the only writer of the
board, so all mutation lives in one place:

```php
namespace app\score;

use Swoole\Coroutine\Channel;

//
// STEP (the single writer): apply the whole batch to the board, then compute each
// reply. Mutates the Leaderboard passed in; returns the replies to send.
//
/**
 * @param  list<Submit>                    $batch
 * @return list<array{Channel, SubmitResult}>
 */
function leaderboard_apply(Leaderboard $board, array $batch): array
{
    $replies = [];
    foreach ($batch as $submit) {
        $board->points[$submit->player] =
            ($board->points[$submit->player] ?? 0) + $submit->points;

        $result        = new SubmitResult();
        $result->total = $board->points[$submit->player];
        $result->rank  = leaderboard_rank($board, $submit->player);

        $replies[] = [$submit->reply, $result];
    }
    return $replies;
}

#[Internal]
function leaderboard_rank(Leaderboard $board, string $player): int
{
    $mine = $board->points[$player] ?? 0;
    $rank = 1;
    foreach ($board->points as $points) {
        if ($points > $mine) {
            $rank++;
        }
    }
    return $rank;
}

//
// SINK: resume each parked request coroutine with its result. The push is what
// wakes the coroutine waiting in the HTTP handler.
//
/** @param list<array{Channel, SubmitResult}> $replies */
function replies_send(array $replies): void
{
    foreach ($replies as [$channel, $result]) {
        $channel->push($result);
    }
}
```

The tick is the line, three stages in order:

```php
namespace app\score;

use app\factory;

//
// One tick: gather the writes buffered since last tick, apply them in one pass as
// the only writer, resume the waiting requests. Reads never enter here.
//
function score_tick(Leaderboard $board, factory\Buffer $inbox): void
{
    $batch   = factory\buffer_drain($inbox);        // Source: writes buffered this tick
    $replies = leaderboard_apply($board, $batch);   // Step:   the only writer of $board
    replies_send($replies);                         // Sink:   wake the request coroutines
}
```

The composition root wires it under Swoole. The per-request callback stays a thin
adapter: parse, buffer, await, write.

```php
namespace app;

use app\{
    factory,
    score,
};
use Swoole\Coroutine\Channel;

$board = new score\Leaderboard();
$inbox = new factory\Buffer();

$server = new \Swoole\Http\Server('0.0.0.0', 8080);

// One worker owns the state: a single writer needs a single process. Scale by
// sharding players across workers, never by sharing the board (see the gaps below).
$server->set(['worker_num' => 1]);

$server->on('request', static function ($req, $res) use ($inbox, $board) {
    $method = $req->server['request_method'];
    $path   = $req->server['request_uri'];

    // A write: parse it, drop it on the inbox belt, park until the tick answers.
    if ($method === 'POST' && $path === '/score') {
        $body = \json_decode($req->rawContent(), true);

        $submit         = new score\Submit();
        $submit->player = (string) $body['player'];
        $submit->points = (int) $body['points'];
        $submit->reply  = new Channel(1);

        factory\buffer_fill($inbox, [$submit]);
        $result = $submit->reply->pop();

        $res->header('Content-Type', 'application/json');
        $res->end(\json_encode(['rank' => $result->rank, 'total' => $result->total]));
        return;
    }

    // A read: served straight from state. Safe because the tick is the only writer
    // and never yields mid-apply, so the board is never seen half-updated.
    if ($method === 'GET' && $path === '/leaderboard') {
        $res->header('Content-Type', 'application/json');
        $res->end(\json_encode($board->points));
        return;
    }

    $res->status(404);
    $res->end();
});

// Own the loop: a 5 ms tick advances all shared state in one place.
$server->on('workerStart', static function () use ($board, $inbox) {
    \Swoole\Timer::tick(5, static fn () => score\score_tick($board, $inbox));
});

$server->start();
```

Follow one request through it. A POST coroutine parses the body, drops a `Submit` on
the inbox, and parks on its reply channel. The next tick drains the batch, applies it
to the board as the only writer, and pushes each result back, waking the parked
coroutine to write its response. The request's in-flight state lives on its own
coroutine stack, so there is no pending-request table to keep: the coroutine answer
to "Stretched context". Reads skip the line entirely.

What it bought: every write to the board happens in one place, on a fixed cadence, so
there are no locks, "who changed this" has one answer, and rate limiting, batching,
and metrics attach to the tick instead of to every handler. What it cost: up to one
tick of latency on a write. For a stateless endpoint that is pure overhead, so handle
it in the coroutine and skip the loop. The model earns its keep once requests share
state and must stay consistent, the way a matching engine, a rate limiter, or a game
lobby does.

## A drivable loop

`factory_tick` above is one host driving one factory. For loops to interoperate, to
nest, to be hosted under any framework, and to be tested deterministically, they
need a shared way to be driven.

> [!TIP]
> A loop is driven, not self-driving. It exposes a `step`, and the host owns the
> clock.

A self-driving loop, one that owns its own `Timer::tick` (see [A loop at the
center](chapters/03-loop.md)), cannot be embedded or tested. Invert it: expose `step` and
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
  outbox. The record-and-replay [Stretched context](chapters/02-pitfalls.md#stretched-context) asked
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

## What a real application still needs

The model above is a happy-path skeleton. The honest gaps, roughly in the order they
bite a production system:

- Errors and partial failure. The line assumes every item is good and every Step
  succeeds. A real Source ingests malformed input and a real Step throws on one item
  in a batch, and neither should drop the batch or stall the line. This wants a
  failure channel: a dead-letter belt, per-item error results, and a retry or poison
  policy.
- Long I/O within a station. A station that makes a slow external call should not
  stall the tick. Under a coroutine runtime (Swoole, assumed throughout) this is
  mostly handled for you: spawn a coroutine for the call and let it drop its result
  onto a buffer when it lands, picked up on a later tick. The coroutine's own stack
  holds the in-flight state, so there is no manual state machine of pending requests
  to maintain. The model's one rule still holds: the pure decide must not yield, so
  I/O stays in the Source, the Sink, and spawned workers, never mid-advance.
- Bounded buffers and real backpressure. Belts are unbounded arrays; a Source faster
  than its Step grows one without limit until memory runs out. Production belts need
  a bound and a policy when full (block the Source, drop, or spill), with the
  consumed count `step` returns feeding admission control.
- Durability and recovery. State lives in memory, so a crash loses every Buffer. A
  real system snapshots state, logs its input ahead of processing (the record and
  replay the loop already hints at), and replays idempotently on restart.
- Keyed ordering and per-key state. Batching interleaves items, but many domains need
  per-key order (per connection, per account) and isolated per-key state. This wants
  a Router that partitions by key into per-key Buffers, plus a guarantee that one
  key's items keep their order.
- Scaling past one core. One loop is one core. Real throughput shards the work across
  many loops by key, which raises the question the single-loop model dodges: how
  shards exchange data, and how a key's state moves when they rebalance.
- Exactly-once-enough commit. Writing output and acknowledging input are two effects a
  crash can split, double-processing or losing work. The Sink needs a transactional
  boundary, idempotency keys, or the outbox pattern, none of which the sketch covers.
- Lifecycle and change. Nothing here covers graceful shutdown (drain in-flight, flush
  buffers), hot reconfiguration, adding or removing stations at runtime, or evolving
  the shape of data on a belt across a rolling upgrade.
- Tracing one item. The metrics count throughput but cannot answer "what happened to
  message X." That needs a correlation id riding with each item and structured logs
  across the stations it visits.

These are not reasons to abandon the model, they are its next design problems, and
several have a natural home in it: errors as a belt, completions as a Source, keys as
a Router. Naming them is the point. The sketch is the skeleton, not the system.

## How it fits the rest

The factory model is not a new architecture so much as three things the other docs
already describe, seen as one:

- The loop ([A loop at the center](chapters/03-loop.md)) is the driver.
- Modules ([MODULES.md](MODULES.md)) own stations and the buffers between them; a
  module's public stations are its surface.
- The composition root builds the station table the same way it builds the
  dependency graph: plain data, read top to bottom, no container.

A program is then a factory described as data and run by one small engine, which is
the most literal reading of "a program is an assembly line" the guide can offer.
