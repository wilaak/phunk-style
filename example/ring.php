<?php

namespace app\queue;

class RingBuffer
{
    public int $head = 0;
    public int $tail = 0;

    #[Internal]
    public int $capacity = 0;

    #[Internal]
    public int $mask = 0;

    /** @var list<int> */
    public array $type = [];
    /** @var list<int> */
    public array $a = [];
    /** @var list<int> */
    public array $b = [];
}

function ring_buffer_new(int $capacity): RingBuffer
{
    assert($capacity > 0 && ($capacity & ($capacity - 1)) === 0);

    $ring           = new RingBuffer();
    $ring->capacity = $capacity;
    $ring->mask     = $capacity - 1;
    $ring->type     = array_fill(0, $capacity, 0);
    $ring->a        = array_fill(0, $capacity, 0);
    $ring->b        = array_fill(0, $capacity, 0);
    return $ring;
}

function ring_buffer_count(RingBuffer $ring): int
{
    return $ring->head - $ring->tail;
}

function ring_buffer_is_empty(RingBuffer $ring): bool
{
    return $ring->head === $ring->tail;
}

function ring_buffer_is_full(RingBuffer $ring): bool
{
    return $ring->head - $ring->tail === $ring->capacity;
}

function ring_buffer_push(RingBuffer $ring, int $type, int $a, int $b): bool
{
    if ($ring->head - $ring->tail === $ring->capacity) {
        return false; 
    }
    $slot              = $ring->head & $ring->mask;
    $ring->type[$slot] = $type;
    $ring->a[$slot]    = $a;
    $ring->b[$slot]    = $b;
    $ring->head++;
    return true;
}

function ring_buffer_pop(RingBuffer $ring, int &$type, int &$a, int &$b): bool
{
    if ($ring->head === $ring->tail) {
        return false;
    }
    $slot = $ring->tail & $ring->mask;
    $type = $ring->type[$slot];
    $a    = $ring->a[$slot];
    $b    = $ring->b[$slot];
    $ring->tail++;
    return true;
}

// =============================================================================
// Usage
// =============================================================================
//
// $ring = queue\ring_buffer_new(1024);
//
// // produce (respecting backpressure):
// if (!queue\ring_buffer_push($ring, $type, $a, $b)) {
//     // ring full: drop or handle overflow
// }
//
// // consume one at a time:
// $type = $a = $b = 0;
// while (queue\ring_buffer_pop($ring, $type, $a, $b)) {
//     // handle ($type, $a, $b)
// }
//
// // OR drain the whole batch at once (fastest: a flat sweep, no per-item calls):
// for ($t = $ring->tail, $h = $ring->head; $t < $h; $t++) {
//     $slot = $t & $ring->mask;
//     // handle $ring->type[$slot], $ring->a[$slot], $ring->b[$slot]
// }
// $ring->tail = $ring->head;   // mark everything consumed in one step
//
// =============================================================================
