# <img alt="Phunk" width="150" src="./assets/phunk-word-dynamic.svg">

*This is a draft for the PHUNK code style and is a W.I.P!*

[Style](docs/STYLE.md) | [Modules](docs/MODULES.md) | [Patterns](docs/PATTERNS.md) | [Optimizations](docs/OPTIMIZATIONS.md)

---

The primary motivation for this coding style is to make it more practical to write and reason about large, complex, persistent application servers which require low latency and high throughput.

We draw from these four established schools:

- **Functional core / imperative shell**: gather-decide-commit, pure-core/thin-shell
- **Data-oriented design**: cache-locality, work batching, free functions over data
- **SEDA / staged event-driven architecture**: bounded queues, backpressure, run the loop
- **Some Go + Rust sensibilities**: module visibility, error as values, small contracts

We use a loop that advances our program, each step able to process data in bulk, which serves well both for performance and for keeping a large amount of state manageable as the system grows.
