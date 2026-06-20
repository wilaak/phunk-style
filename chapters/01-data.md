<img alt="Phunk" width="120" src="../assets/phunk-text.svg">

## It's all about that data

If you boil it down, a program is just transforming data from one form into another. In essence, any program can be thought of as a set of assembly lines where data is loaded at one end with various stations along the line to manipulate it to the other end.

Sometimes it may not feel like that, though. With enough abstraction, frameworks and deep object graphs the data flow tends dissapear under machinery, and you follow call stacks instead of the data. That disconnect is the abstractions hiding the line, not the program lacking one and the first move is to make the line more explicit again.

If something is computable, it's ultimately a data transformation. Programs can be broadly categorized by the primary kind of data transformation they perform:

- Servers transform network requests into responses.
- Interactive apps transform state based on user input.
- Simulations and games transform state from input and clock ticks.
- Processing jobs transform arguments and file data into output.

That is essentially every program ever written, so writing a correct one is mostly a matter of transforming its data correctly. Some of those transformations are trivial; others are fiendishly complex, threading many steps and a great deal of state from input to output.

The ideal then is to be as explicit about how we transform our data as we can be. An explicit assembly line keeps complex transformations more tractable: when correct input gives a wrong result, you bisect the line to find the stage at fault. This works recursively, too, since if A, B, and C are right but D is wrong, you bisect D's substages the same way.

In contrast, behavior spread across objects calling objects, a method calling a method calling a method, has no single order to walk and so harder to bisect: a wrong result could be anywhere in the web. That is what many OOP styles grow into, see [Why not just use classes?](05-classes.md).

Moving data through the line in bulk is also the shape the hardware runs fastest, since flat data processed in sequence beats scattered objects chased one at a time, see [Working with the machine](04-hardware.md). Nothing is a silver bullet, the assembly line style has some recurring issues of its own, see [Assembly line pitfalls](02-pitfalls.md).

Sometimes it can be useful to visualize your program as if you were playing Factorio:

![Factorio Is Literally Just Programming GIF](../assets/factorio-we-do-a-little.gif)

---

[Home](../README.md) | Next: [Assembly line pitfalls](02-pitfalls.md)
