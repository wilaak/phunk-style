<img alt="Phunk" width="120" src="../assets/phunk-text.svg">

---

## Working with the machine

A short aside on hardware, because it is the reason flat data and batches keep coming up. You do not need any of it to follow the rules, but it makes them feel less arbitrary.

Start with one fact: a CPU is fast and main memory is slow. Fetching a value from RAM can cost hundreds of times more than an arithmetic operation, so for a lot of real work the processor is not busy computing, it is waiting for data to arrive. Going fast is mostly a matter of keeping it fed.

To soften that gap, the CPU keeps a small, very fast scratch space close by: the cache. Think of it as your desk. What you are actively using sits on the desk where you can grab it instantly; everything else lives in a warehouse down the hall, which is main memory, and walking there to fetch something takes a while. The whole game is to keep what you need next already on the desk.

Two things help with that. First, memory does not arrive one value at a time. It comes in fixed chunks of around 64 bytes, called cache lines, so when you read one value its immediate neighbours come along for free. Data laid out next to data you will touch next is effectively fetched ahead of time.

Second, the CPU watches how you walk through memory. Read it in order and it notices, running ahead to pull the next lines onto the desk before you ask. This is the prefetcher, and it only works on predictable, sequential access. Jump around unpredictably and it gives up, and you pay the full warehouse trip on every miss.

Flat data plays to both. An array of like values sits contiguous in memory, streams through the cache, and lets the prefetcher run the whole way, and processing it as a batch keeps the CPU fed instead of stalling. A web of scattered objects does the opposite: to reach the next one you follow a pointer to wherever it happens to live, which nothing can predict, so each hop risks a cache miss. Working with the grain of the machine like this is what people mean by mechanical sympathy, and it is why this guide reaches for flat structures and bulk passes by default. The hot-path specifics live in [OPTIMIZATIONS.md](../OPTIMIZATIONS.md).

---

Prev: [A loop at the center](03-loop.md) | [Home](../README.md) | Next: [Why not just use classes?](05-classes.md)
