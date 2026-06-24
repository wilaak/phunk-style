# Recovering from DI containers

A dependency-injection container builds your object graph, manages lifetimes, and resolves dependencies by interface.

This style does all three with plain code at the [composition root](ARCHITECTURE.md) — and in doing so sheds a pile of encapsulation the container quietly forced on you. This page is the why and the how.

## Why the container exists at all

A container is not a moral good; it is a workaround for a runtime. Classic PHP
(PHP-FPM, mod_php) is request-per-process: it tears down and rebuilds the entire
object graph on every request. To make that affordable the container has to
autowire construction, defer it lazily, and cache singletons across the rebuild.

A long-running process has no such problem. You build the graph **once** at startup
and hold it for the life of the process. The moment that's true, every job the
container did collapses into ordinary code:

| Container concept   | Here                                          |
| ------------------- | --------------------------------------------- |
| Autowiring          | Plain `new`, top to bottom at the root        |
| Singleton lifetime  | A variable built once at boot                 |
| Per-request scope   | A value built in the handler, passed down     |
| Resolve by interface| Construct the chosen concrete value at boot   |
| Lazy instantiation  | Eager; a long-lived process pays construction once |

The full table lives in [ARCHITECTURE.md](ARCHITECTURE.md). The point here: you are
not losing a feature, you are deleting a tax.

## The encapsulation it forced on you

The deeper cost was never the container config — it was the shape it demanded of
every class to be *containerable*. To be autowired, lazily built, and mock-resolved,
a class had to become a sealed unit with an interface in front of it. So you wrapped
everything, whether it had anything to protect or not.

### An interface per class, for one implementation

The container resolves by type, and "good practice" said depend on an abstraction,
so every service grew a parallel interface with exactly one implementation forever.

```php
// the container ritual: an interface and a class that will never have a sibling
interface PriceCalculatorInterface
{
    public function total(Cart $cart): int;
}

final class PriceCalculator implements PriceCalculatorInterface
{
    public function total(Cart $cart): int { /* ... */ }
}
```

```php
// here: a free function. The JIT devirtualizes a single-target call anyway.
function cart_total(Cart $cart): int { /* ... */ }
```

Add an interface when a *second* implementation must be interchangeable, or a
boundary genuinely needs a stub in tests — not as a reflex. One implementation needs
none.

### Private fields with a getter for each, guarding nothing

A container hands you constructor-injected services, and the encapsulation cargo cult
did the rest: every field private, a getter for each, often a setter too. Most of
that data has no invariant to defend. It's a struct wearing a costume.

```php
// the costume: six lines of ceremony to expose what was already just data
final class Money
{
    private int $amount;
    private string $currency;

    public function __construct(int $amount, string $currency) { /* ... */ }
    public function getAmount(): int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
}
```

```php
// here: public typed fields. Read what you need; nothing was hidden for a reason.
final class Money
{
    public function __construct(
        public int    $amount_cents,
        public string $currency,
    ) {}
}
```

Encapsulation has one job: protect an invariant. If reading or writing a field
cannot break anything, hiding it behind a method buys nothing and costs a reader two
hops. Make fields public until something real must be enforced (see
[DATA.md](DATA.md), wide public structs).

### Behavior welded to data it doesn't need

Because the container deals in objects, behavior had to live *on* an object, so logic
that is a plain transformation got trapped inside a class next to state it never
touches.

```php
// trapped: a stateless transform forced to be a method on an injected service
final class SlugGenerator
{
    public function __construct(private Transliterator $tr) {}
    public function generate(string $title): string { /* ... */ }
}
```

```php
// freed: a free function over values. Test it with no container, no mock.
function slug_generate(string $title): string { /* ... */ }
```

Carry data in structs; carry behavior in free functions that take the struct first.
The function moves to where it's used and tests in isolation.

## Encapsulation is a tool, not a default

None of this says "never make a field private." It says the default is wrong. Reach
for encapsulation when you have an invariant to hold:

- A field that must stay in sync with another (consolidate the write behind one
  method).
- A value that must be validated on the way in (a constructor that rejects bad
  input).
- A handle whose lifecycle you own (open/close, acquire/release).

Everywhere else, public data and free functions read straighter and cost the reader
less. Hide a thing because revealing it would let someone corrupt state — never
because a container or a habit said to wrap it.

## How to migrate

The move is mechanical because the container was doing mechanical work. Go in this
order:

1. **Stand up a composition root.** One `server.php` / `bin/<name>` per executable.
   Move every binding from the container config into plain `new` calls, top to
   bottom: config, then capabilities, then `$env`. See
   [ARCHITECTURE.md](ARCHITECTURE.md).

2. **Split wiring from entrypoint.** Put the `new` calls in a pure
   `app\boot(Config): Env`; leave the entrypoint a thin file that reads the
   environment, calls `boot`, and runs. A test reuses `boot` with a different
   `Config`.

3. **Collapse interfaces with one implementation.** Delete the interface, keep the
   class — or better, turn the service into free functions in the module that owns
   its types. Keep an interface only where a second implementation or a test stub
   actually needs one.

4. **Unwrap the data classes.** Make fields public and typed; drop getters and
   setters that guard nothing. Move the methods that are really transforms out into
   free functions.

5. **Pass capabilities through signatures.** What the container injected, you now
   hand down: `$env` at the shell edge, the single capability (`\PDO $database`)
   deeper in. Nothing resolves itself; everything arrives in a parameter.

### Before

```php
// container.php — bindings, lifetimes, interfaces
$container->singleton(LoggerInterface::class, fn () => new FileLogger('/var/log/app'));
$container->bind(PriceCalculatorInterface::class, PriceCalculator::class);

// the service: injected, interfaced, sealed
final class CheckoutService
{
    public function __construct(
        private PriceCalculatorInterface $prices,
        private LoggerInterface          $logger,
    ) {}

    public function handle(Cart $cart): Receipt
    {
        $total = $this->prices->total($cart);
        $this->logger->info('checkout', ['total' => $total]);
        return new Receipt($total);
    }
}
```

### After

```php
// server.php — the graph, built once, in plain sight
$config = app\config\from_env();
$env    = app\boot($config);   // wires $logger and the rest, top to bottom

$server->on('request', static fn ($req, $res) => app\api\serve($env, $req, $res));
```

```php
namespace app\checkout;

// shell: holds $env, calls the pure core, commits
function checkout_handle(env\Env $env, Cart $cart): Receipt
{
    $total = cart_total($cart);                 // pure free function, no interface
    $env->logger->info('checkout', ['total' => $total]);
    return new Receipt($total);
}
```

The graph is now greppable, the price calculation tests with no container, and the
only interface left (`$env->logger`) is one a real second implementation justifies.

## When a container is still the right call

Be honest about the boundary. If you are on request-per-process PHP and not moving to
a long-running runtime, the container is earning its keep — it's amortizing the
rebuild you can't avoid. This style targets long-running processes (Swoole, a worker
loop, a CLI daemon); that's the runtime where the container becomes pure overhead and
the composition root wins. Match the tool to the runtime, not to fashion.
