<img alt="Phunk" width="120" src="../assets/phunk-text.svg">

## A loop at the center

> [!TIP]
> Run the assembly line on a fixed cadence, advancing all state in one place.

Think of the loop as the assembly line given a heartbeat. A single run is stateless: data in, result out, nothing kept. Run it over and over against state you hold between passes, and you have a program that lives in time instead of forgetting itself after every request.

PHP's default is the opposite: a callback fires per request and forgets everything. That is fine when requests are independent, but a stateful server is not, and threading shared state through scattered callbacks is the smeared, hard-to-follow control flow the assembly line was meant to avoid.

Game engines settled this long ago with one loop at the center: each tick, gather the inputs that arrived, advance the whole state by one step, flush the outputs, repeat at a fixed rate. A server fits the same shape. Connections and messages buffer as they arrive, and the per-event callback shrinks to a thin adapter that only appends (see [MODULES.md](../MODULES.md), "When you own the loop, write a loop").

The tick is then the gather, decide, commit line a request handler uses, only batched and repeating:

```php
namespace app\world;

use app\{
    env,
    net,
};

//
// One tick: drain the input buffered since the last tick, advance the whole
// state by one step, flush the output. I/O only at the edges; the step is pure.
//
function world_tick(env\Env $env, World $world, float $dt): void
{
    // gather: everything buffered since last tick
    $events = net\drain($env->net);

    // decide: advance the whole batch in one pass
    world_step($world, $events, $dt);

    // commit: write results back
    net\flush($env->net, $world);
}
```

The composition root owns the loop instead of handing it to the framework. The per-message callback only buffers; a fixed-rate timer runs the tick:

```php
// The per-event callback is a thin adapter: it appends, it never touches state.
$server->on('message', static fn ($conn, $msg) => net\buffer($env->net, $conn, $msg));

// 20 ticks per second. State advances here and nowhere else.
$tick_ms = 50;
Timer::tick($tick_ms, static fn () => world\world_tick($env, $world, $tick_ms / 1000));
```

All of this assumes a coroutine runtime like Swoole. I/O there is cooperative: a database query or service call in the gather or commit looks blocking but yields, so the scheduler parks that coroutine and runs others instead of stalling the process. You write straight-line code and the runtime does the waiting, with no callbacks or promises threaded through it.

The gather, decide, commit shape is what keeps that safe. The only thing that yields is I/O, and it sits at the edges; the decide in the middle is pure and gives up control to no one, so no other coroutine interleaves partway through advancing the state. Cooperative concurrency without the usual races, because the one place state changes never yields.

Advancing all the state in one timed place pays off three ways.

It is easier to reason about: what happens to the state, and when, has one answer instead of being smeared across every handler. A tick is a pure step from old state plus this tick's inputs to new state, so it is testable without a live socket and replayable from recorded input, the record-and-replay [Stretched context](02-pitfalls.md#stretched-context) asked for, falling straight out of the shape.

It pushes data the right way: inputs collect into a batch before anything touches them, so a tick processes many items in one pass instead of one per callback. That is the bulk, cache-friendly shape the layout rules reward (see [OPTIMIZATIONS.md](../OPTIMIZATIONS.md)): stages over flat arrays, not a walk over an object graph.

And it gives one place to measure and bound the work: time a tick for latency, count its batch for throughput, both just counters. Rate limiting is a cap on work per tick, backpressure a bounded input buffer, statistics a tally per step. Monitoring, limiting, and metrics fall out of the structure instead of being bolted on.

This is one shape among several, not a mandate: a stateless request and response service is fine left reactive. Reach for the loop when state lives across events and the timing or the throughput is something you need to see and control, the real-time and simulation end of the four categories above.

---

Prev: [Assembly line pitfalls](02-pitfalls.md) | [Home](../README.md) | Next: [Working with the machine](04-hardware.md)
