<?php

// =============================================================================
// Consumers as a dependency graph over one ring
// -----------------------------------------------------------------------------
// The LMAX Disruptor idea, sized for a single Swoole process. A queue couples
// produce and consume into pop(): the item is read AND removed, so a pipeline
// needs a separate queue per stage and copies between them. This inverts that —
// consuming is advancing a cursor, not removing — so many consumers read ONE
// ring at their own pace, and a multi-stage pipeline becomes a DAG of cursors
// chasing each other around a single buffer.
//
// The worked flow is a diamond: each incoming Order is journalled AND metered
// in parallel, then settled once BOTH are done.
//
//                  ┌──► journal ──┐
//   producer ──────┤              ├──► settle
//                  └──► meter  ────┘
//
// The point of interest: the sequence/cursor machinery lives entirely in the
// `ring` and `pipeline` modules (LAYER 1 and 2, written once). The business
// stages (LAYER 3) are plain functions over an Order and never see a sequence.
// That containment is the whole reason this stays readable. Read the layers in
// order, then the notes at the very end.
//
// Relation to example/ring.php: that file is a single-producer/single-consumer
// value queue (pop removes). This is the multi-consumer, cursor-based variant —
// reach for it only when you have fan-out with a join, not for a linear A→B→C.
// =============================================================================


// =============================================================================
// LAYER 1 — the ring primitive (written once, reused for every pipeline)
// =============================================================================
// All the sequence arithmetic is quarantined here. Slots are reused; a power-of-
// two size turns the modulo into `seq & mask`. Nothing below this layer mentions
// a sequence number.
namespace app\ring;

class Ring
{
    /** @var list<mixed> */
    public array $slots;
    public int   $mask;
    public int   $cursor = -1;   // highest published sequence; -1 = empty

    public function __construct(int $size_pow2)
    {
        assert(($size_pow2 & ($size_pow2 - 1)) === 0);   // power of two

        $this->slots = array_fill(0, $size_pow2, null);
        $this->mask  = $size_pow2 - 1;
    }
}

// One per consumer: how far it has processed. Each cursor has exactly one writer
// — its own consumer — so reading it from elsewhere needs no lock.
class Cursor
{
    public int $seq = -1;
}

/** @param list<Cursor> $cursors */
function min_seq(array $cursors): int
{
    $min = PHP_INT_MAX;
    foreach ($cursors as $cursor) {
        if ($cursor->seq < $min) {
            $min = $cursor->seq;
        }
    }
    return $min;
}

// The barrier: the highest sequence a consumer may read. With no dependencies it
// trails the producer; otherwise it trails the slowest dependency.
/** @param list<Cursor> $deps */
function barrier(Ring $ring, array $deps): int
{
    if ($deps === []) {
        return $ring->cursor;
    }
    return min_seq($deps);
}

// Publish one event. While the ring would lap the slowest gating consumer, yield
// — that wait IS the backpressure, and the ring size is the bound.
/** @param list<Cursor> $gating */
function publish(Ring $ring, array $gating, mixed $event): void
{
    $next = $ring->cursor + 1;
    while ($next - min_seq($gating) > $ring->mask) {
        \co\tick_yield();   // ring full: let the consumers drain
    }

    $ring->slots[$next & $ring->mask] = $event;
    $ring->cursor = $next;
}


// =============================================================================
// LAYER 2 — the consumer driver (written once)
// =============================================================================
// Process from where we left off up to the barrier, in one batch, then yield. A
// consumer that has fallen behind catches up in a single tight loop, so batching
// falls out for free. `$consume` is a plain function over the event.
namespace app\pipeline;

use app\ring;

/**
 * @param list<ring\Cursor>     $deps
 * @param callable(mixed): void $consume
 */
function run(ring\Ring $ring, ring\Cursor $me, array $deps, callable $consume): void
{
    while (true) {
        $available = ring\barrier($ring, $deps);
        for ($seq = $me->seq + 1; $seq <= $available; $seq++) {
            $consume($ring->slots[$seq & $ring->mask]);   // read in place, no copy
        }
        $me->seq = $available;

        \co\tick_yield();
    }
}


// =============================================================================
// LAYER 3 — the application (what you read and write daily)
// =============================================================================
// `boot()` reads as a literal description of the diamond: who depends on whom.
// The stages are one-liners over an Order — no sequence, no cursor, no barrier.
namespace app\orders;

use app\ring;
use app\pipeline;

class Order
{
    public int    $id          = 0;
    public int    $amount_cents = 0;
    public string $customer    = '';
}

function boot(): void
{
    $ring = new ring\Ring(1024);

    $journalled = new ring\Cursor();
    $metered    = new ring\Cursor();
    $settled    = new ring\Cursor();

    // journal and meter depend on nothing -> they run concurrently off the producer
    \co\go(fn () => pipeline\run($ring, $journalled, [], order_journal(...)));
    \co\go(fn () => pipeline\run($ring, $metered,    [], order_meter(...)));

    // settle depends on BOTH -> it touches an order only once it is journalled and metered
    \co\go(fn () => pipeline\run($ring, $settled, [$journalled, $metered], order_settle(...)));

    // the producer, back-pressured by the slowest final consumer ($settled)
    \co\go(function () use ($ring, $settled) {
        foreach (orders_incoming() as $order) {
            ring\publish($ring, [$settled], $order);
        }
    });
}

// The actual work. Plain functions over plain data; none of them know they live
// in a ring. The two PARALLEL stages only READ the order; the single downstream
// writer ($settled) is the only one that mutates it — single-writer applied to
// the diamond. Let both parallel arms mutate and the interleaving safety is gone.
function order_journal(Order $order): void { store\order_journal_append($order); }   // read-only
function order_meter(Order $order): void   { metrics\order_count($order); }          // read-only
function order_settle(Order $order): void  { ledger\order_settle($order); }          // the lone writer


// =============================================================================
// Notes
// =============================================================================
// - Readability rests on one rule: cursors never leave the `ring`/`pipeline`
//   modules. The day a stage function takes a $seq parameter, this turns into
//   noise. Keep the machinery quarantined and the stages stay trivial.
//
// - The DAG is declarative and lives in one place — the `$deps` arrays in
//   boot(). You read the topology, you don't trace it.
//
// - Safety by construction: fan out for READS (journal, meter run in parallel),
//   converge to ONE writer for the change (settle, downstream and alone). This
//   is the single-writer principle shaped to a pipeline.
//
// - `\co\go` and `\co\tick_yield` stand in for a thin wrapper over Swoole's
//   coroutine create + scheduler yield; write them as a small `co` module so the
//   application reads this cleanly rather than with raw runtime calls.
//
// - What transfers from LMAX and what doesn't: the STRUCTURE (cursors, barriers,
//   read-in-place, backpressure via the slowest gating consumer, batch-to-
//   barrier) all works here, and read-in-place composes with "handles, not
//   references" — the slot is read by index, never copied. What does NOT transfer
//   is the Disruptor's reason for existing: lock-free, cache-line-padded MULTICORE
//   throughput. In one cooperative process the parallel arms overlap I/O waits,
//   they do not run on separate cores. For true parallel consumers you need
//   workers + a shared-memory ring (Swoole\Table) + atomic sequences, which
//   trades this simplicity away.
//
// - When NOT to reach for this: a linear A→B→C with no fan-out is a plain drain
//   loop, `a(); b(); c();` per item — no cursors needed. And never gate the
//   producer on a non-critical consumer (metrics): a dead one would stall the
//   whole ring. Let it lag and drop instead.
// =============================================================================
