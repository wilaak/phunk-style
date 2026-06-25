# Coloring

Function coloring is somehow magically gonna make your code better and easier to reason about!

*No, not really... sorry*

And that is partly why PHP should probably not adopt such a model. The rest being the cost to adopt such a model is basically creating a new language, and we will see that it's not actually that helpful or explicit either.

The thing that actually makes a concurrent system unsafe is a state invariant that must hold across a region getting violated because something mutated the state mid-region.

`await` denotes a single point in *one* function, but it doesn't say anything about:

 - what state is live across that point
 - who else can reach that state
 - whether any of them writes it

Something that could be of concern in a PHP application is when you hide an invariant behind a shared service class and let multiple request coroutines touch it.

Objects makes shared, mutable and implicit state the default all at once so be careful about where and how you use them.

A good way to tackle this is to put invariant-sensitive mutations behind a queue:

- no mid-region interleaving on that state
- explicit write path
- easier reasoning and auditing