# <img alt="Phunk" width="200" src="./assets/phunk-text.svg">

*This is a draft of the PHUNK PHP coding style guidelines and is a W.I.P with rough edges!*

# Introduction

The primary motivation for this coding style guide is to make it more practical to write and reason about large, complex and efficient stateful application servers, which require low latency and high throughput, inspired by real battle-tested systems.

In this style we will favor free functions, use less abstraction/encapsulation and focus more on how we store and transform our data. We will use namespaces as modules and classes are used more sparingly instead of the default unit of abstraction.

For lack of a better term we will call it a procedural style, in which programs can be thought of as a multitude of assembly lines where data is loaded at one end with various stations along the line to manipulate it to the other end.

Being explicit about your code like this will not only make the computer have a better time understanding it, you will too. The ideas explored are not novel, many are battle-tested in systems that already run under heavy load.

# Getting started

Build the mental model first.

1. [It's all about that data](chapters/01-data.md)
2. [Assembly line pitfalls](chapters/02-pitfalls.md)
3. [A loop at the center](chapters/03-loop.md)
4. [Working with the machine](chapters/04-hardware.md)
5. [Why not just use classes?](chapters/05-classes.md)

# Reference

- [STYLE.md](STYLE.md)
- [MODULES.md](MODULES.md)
- [OPTIMIZATIONS.md](OPTIMIZATIONS.md)
