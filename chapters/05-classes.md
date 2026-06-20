<img alt="Phunk" width="120" src="../assets/phunk-text.svg">

---

## Why not just use classes?

So far the case has been for what to do: think in assembly lines, give a stateful program a loop. This section is the other half, why the PHP default works against both. PHP makes the class the default unit of nearly everything: the thing you model, the file you put on disk, the unit the autoloader pulls in. For short-lived request work that is fine. For a long-lived, high-throughput server it quietly works against you on two fronts: it scatters the structure of the program, and it scatters the data in memory.

Splitting a system into small, encapsulated units is good at some scale, but the idea gets pushed until everything is a unit and smaller is always assumed better. The premise is that a smaller unit is easier to get right, which is true on its own. What it forgets is that the correctness of a system lives in how its units fit together, not in each one alone. Concentrated complexity gets traded for scattered complexity, which is harder to follow, so the total goes up rather than down.

Conflating data types with modules makes this worse. When every type has to be its own unit, data and the code over it fracture across odd boundaries: a field is moved to another class because it does not fit the supposed responsibility of the first. The boundaries serve the rule, not the problem.

Object modeling also assumes the world comes pre-divided into neat types, and it rarely does. Real concepts overlap and shift, so you spend energy on where a method belongs and what to name the class that owns it, and the answer keeps moving as the program grows. When a file holds a single class, attention goes to the shape of the abstraction instead of the shape of the data, which is the part that matters: a program is an assembly line that transforms data. Designing the hierarchy first means committing to boundaries you cannot judge yet, since the real limits only show up once you build. The up-front structure is usually guesswork you later pay to undo. It is easier to let structure follow the data once you can actually see it.

The second problem is the drift away from sequential code and flat data. Small objects can do little alone, so they collect references to other objects, and getting anything done becomes a method calling a method calling a method. Data ends up cross-referenced into a graph with no fixed order to read. Code is easiest to follow as an assembly line: data in one end, a sequence of stages, a result out the other. When the wrong result comes out, you bisect the stages. A graph of objects calling each other has no such order to bisect.

Encapsulation can make this harder still. Hiding state inside objects is meant to protect it, but it also hides what any operation actually touches: work happens by sending a message to an object, which sends a message to another, so the real behavior is smeared across the graph instead of sitting in one place you can read. Concerns that cut across the object boundaries, like logging, validation, or transactions, have nowhere natural to live, so they get scattered or bolted on through more layers of indirection. A lot of methods end up doing no real work of their own, only passing the call further along.

## The fat struct

So what replaces the web of small objects? Go the other way: keep the state in a few wide structs of public, typed fields, and write free functions that take them and do the work. Where encapsulation splits a concept across many classes so each can guard its own piece, you let the concept sit in the open as one flat record, and let the functions over it say plainly which fields they read and write. This is the default here, not a special case. A wide public struct is simply how you hold state; reaching for a private field or an accessor is the thing you justify, not the struct.

The payoff is exactly the cross-cutting work that encapsulation makes awkward. Logging, validation, serialization, snapshotting, diffing, a migration from one version of the state to the next, all of these want to see the whole of the state at once, and a public struct hands it to them. Each becomes one function that reads the fields it needs, in one place you can read, instead of a concern threaded through every object or reached for with reflection. The operation's reach is visible too: a free function names the fields it touches right in its body, where a method hides its reach behind a chain of messages.

Going wide is not a licence for a junk drawer, and the difference is a discipline, not a size limit. A fat struct earns its width two ways, both already named above. Group fields by how they are accessed, not by some notion of responsibility: data read and written together in the same stages belongs together, and the record is wide because the work genuinely touches that much state at once. And consolidate writes, the [Scattered state](02-pitfalls.md#scattered-state) pitfall: a wide record stays tractable only when many stages read a given field and few write it. Many readers, few writers. Lose that and you do have a god object, which is the failure the small-object camp rightly warns about. The discipline is what keeps the fat struct on the right side of that line.

## It fights the hardware

The structural cost is what you feel first, but there is a performance cost underneath it, and it is exactly the one [Working with the machine](04-hardware.md) described. A stateful server holds a lot of live data at once, and modelling each piece as an object with references to others spreads it across a graph of separate allocations, reached by pointer, one item at a time. That is the access pattern the hardware punishes: the prefetcher cannot follow it, every hop risks a cache miss, and each refcounted reference dirties a cache line just to be read or passed.

Keeping like data together flips all of it. The working set sits in flat structures you can inspect and pass over in bulk, so it both reads clearly and streams through the cache. The state ends up easier to reason about and faster to process, for the same underlying reason. A uniform batch is easier to split across cores too, and cross-cutting work like logging or validation becomes one stage over the whole set rather than something threaded through every object.

## How autoloading locked it in

If this object-first habit is so costly for long-lived work, why is it the PHP default? Partly history, partly the way code is loaded. PHP has, for most of its life, been shaped by its HTTP one request per process model, so the language was rarely pushed past short-lived work, where a class sits comfortably as both the thing being modeled (an `Order`, a `User`) and the unit that organizes and loads code.

PHP gained a real object model in PHP 5, just as object-oriented design was the dominant idea in the industry, and the conventions that grew up around it followed suit. PSR-0 and then PSR-4 standardized autoloading by mapping a fully-qualified class name to a file path: one class per file. Composer made that mapping the basis of the whole package ecosystem, so a dependency is shipped and loaded as a tree of classes.

This was useful, but it quietly fixed the class as the unit of code. Autoloading rewards one class per file, one file per class rewards many small classes, and the tooling assumes that shape from end to end. So fine-grained OOP persists in public PHP not because it wins on its merits, but because the defaults make anything else swim upstream.

The rest of the field settled elsewhere. Go, Rust, Zig, and Odin organize code by package or module: a directory of related code, built together, with plain data and free functions rather than a type per file. The unit is the namespace, not the class. None of them have objects in the OOP sense at all; behavior is a function that takes a struct by reference, and they get along without classes entirely. PHP can work the same way: treat a namespace as a module, load the source once at startup, and drop per-class autoloading entirely (see [MODULES.md](../MODULES.md)).

Once you account for memory and lifetime like this, PHP handles work it was never thought suited for: persistent services, real-time systems, game servers. The aim is simply to make that practical, through better organization and the performance that follows.

---

Prev: [Working with the machine](04-hardware.md) | [Home](../README.md)
