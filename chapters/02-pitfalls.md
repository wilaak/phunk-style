<img alt="Phunk" width="120" src="../assets/phunk-text.svg">

---

## Assembly line pitfalls

Not everything is a silver bullet.

### Scattered state

> [!TIP]
> Consolidate each value's mutations into as few stages as possible.

The more stages that can change a value, the more places you must search when it comes out wrong, and the more hidden purposes it quietly picks up as its meaning shifts from stage to stage.

Often this is just a matter of reordering the logic so the writes sit together. When they genuinely cannot, copy rather than mutate in place: a `slug` whose meaning drifts partway down the line becomes `slug` and a transient `slug_normalized`, two honest names instead of one overloaded one.

That is an extra name to track, but it tells the truth about what the value is.

### Leaky functions

> [!TIP]
> A function should touch only the state passed to it.

Reaching outside its arguments, to globals, I/O, or data shared by reference, makes its dependencies and effects invisible from the call site. For any function, take stock of whether it:

- reads or writes globals,
- reads from or writes to I/O,
- reads or writes data passed by reference.

For each, ask whether it is truly necessary: could the global be passed in, the file write be deferred to a later stage, the by-reference mutation be a returned value instead?

Full purity is a large demand with costs of its own. You gain most of the benefit one step down from it:

- A function accesses only what is passed to it: no globals.
- A function is passed only what it needs: an item or a field, not a whole collection, when the smaller thing will do.
- Core logic and I/O stay apart: logic does no I/O, and I/O carries no non-trivial logic.

 Loggers and allocators are stateful in the strict sense, but not in ways that corrupt your logic, so reaching for them as globals is fine.

### Tangled data

> [!TIP]
> Design the data on its own terms, before the code.

Data more complex than the problem requires is harder to understand, harder to change, and harder to keep mutation consolidated: if type A references type B, every touch of A drags in B, so you cannot manage them independently.

Designing data around code (see [Why not just use classes?](05-classes.md)) is a major source of these needless links, fractures, and redundancies.

Working procedurally, you can interrogate a design before any code exists:

- Is this the most compact encoding of the information required? If not, is there a reason to denormalize?
- Are these linkages necessary? If so, can a key or index stand in for a pointer?
- Does this need to be stored at all, or can it be recomputed when needed?
- Can this hierarchy or graph be flattened into an array?

### Stretched context

> [!TIP]
> Some logic lives across runs, not inside one.

An assembly line reasons cleanly about a single run, but a program's hardest parts often span many: a long-running async task that blocks some interactions until it finishes, a scripted game sequence that plays out over many frames. This state fits in no single event handler or tick, so it needs an explicit home and careful thought.

It is also harder to test. A step debugger sees only one run; debugging across many needs recorded input you can replay, which few web frameworks or game engines offer out of the box.

Both pressures point the same way: holding state across runs, and replaying it to debug, both want a single place where the state lives and one point where it advances. A loop gives you exactly that.

---

Prev: [It's all about that data](01-data.md) | [Home](../README.md) | Next: [A loop at the center](03-loop.md)
