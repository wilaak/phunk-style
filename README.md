# <img alt="Phunk" width="250" src="./assets/phunk.svg">

*This is a draft for the PHUNK code style guide and is a W.I.P!*

See [Style](STYLE.md), see [Modules](MODULES.md), See [STDLIB](STDLIB.md)

# Introduction

The primary motivation for this coding style guide is to make it more practical to write and reason about large, complex, stateful application servers, which require low latency and high throughput.

For lack of a better term we will call it a procedural style, in which programs can be thought of as a series of conveyor belts where data is loaded at one end with various stations along the belt to manipulate it to the other end.

We use a loop that advances our program, each step able to process data in bulk, which serves well both for performance and for keeping a large amount of state manageable as the system grows.

## It's all about that data

A program is transforming data from one form into another. Any program can be thought of as a series of conveyor belts, where data is loaded on one end, moves through stages, one might transform it, another might move it to another belt on some condition, it ends up somewhere.

It might not always feel like that, though. In many codebases the abstractions, frameworks, and deep object graphs can hide the data flow.

What we can establish still is that if something is computable, it's ultimately a data transformation. Programs can be broadly categorized by the primary kind of data transformation they perform:

- Servers transform network requests into responses.
- Interactive apps transform state based on user input.
- Simulations and games transform state from input and clock ticks.
- Processing jobs transform arguments and file data into output.

That is essentially every program ever written, writing a correct one is mostly a matter of transforming its data correctly. Some of those transformations are trivial; others are very very complex, threading many steps and a great deal of state from input to output.

Thinking in terms of a conveyor belt where data moves in stages is helpful here. The explicit data flow keeps complex transformations more tractable: when correct input gives a wrong result, you bisect the belt to find the stage at fault. This works recursively, too, since if A, B, and C are right but D is wrong, you bisect D's substages the same way.

So a large idea of this style is that we want to make the data flow explicit, in contrast to some object-oriented styles, where its common for behavior to be more spread across objects calling other objects, method calling a method, or any other flow that has no easy single order to walk.

We want to think about programs more as a series of conveyor belts, and structure our programs in a way that better represents the data that is flowing through it.

*If you have played Factorio, it can be useful to think about your program as a factory:*

![Factorio Is Literally Just Programming GIF](./assets/factorio-we-do-a-little.gif)