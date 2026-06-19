# Concurrency

*this is draft*

Concurrency is an essential part of web applications. Its main goals revolve around reducing wasted CPU time as well as enabling event driven architectures. The most common form of concurrency in PHP today is cooperative multitasking with an event-loop/reactor and scheduler limited to a single PHP process. This is used by projects like Swoole, AMPHP, ReactPHP and SWOW.

## Introduction

Swoole's concurrency primitives, specifically a technique called cooperative multitasking with coroutines. Coroutines are functions that can run concurrently, hence the name co-routine. Routine and function is often used interchangably in programming land, if you are new to the terminology you can imagine a co-routine as a co-function.

In a typical PHP application you are constrained to having only a single program flow at all times. If you were to do any operation that involve some sort of waiting, such as querying a database, the PHP process would be sitting there idle, twiddling one's thumbs, patiently waiting for the function to return before being able to do anything else.

What if instead of the PHP process just sitting there waiting, we could do something more useful in that meantime? This what cooperative multitasking with coroutines aims to solve.

### Coroutines

You're probably used to the synchronous code flow in a typical PHP application, how does it compare to coroutines? From the outside, coroutines behave the same way, it's code flow is synchronous, the difference is that you can have more of them at the same time.

In that sense, you can imagine that a coroutine is like having multiple independent synchronous code flows all managed in a single PHP process. Coroutines are very lightweight at only around 1 KB allowing you to create thousands or even tens of thousands of coroutines in a single PHP process.

Unlike threads, coroutines all share the same memory space.

...