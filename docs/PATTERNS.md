# Patterns

Replicate language control flows in PHP.

## Dispatch: closed set vs open set

```php
namespace app\notify;

enum Channel
{
    case Email;
    case Sms;
    case Push;
}

function notify_send(Channel $channel, Message $message): Result
{
    return match ($channel) {
        Channel::Email => notify_send_email($message),
        Channel::Sms   => notify_send_sms($message),
        Channel::Push  => notify_send_push($message),
    };
}
```

| Situation                                       | Use                     |
| ----------------------------------------------- | ----------------------- |
| Variants known at authoring time (a closed set) | enum + central `match`  |
| Variants registered by strangers at runtime     | single-method interface |

## Guard ladder

```php
$tier = match (true) {
    $score >= 100 => Tier::Gold,
    $score >= 10  => Tier::Silver,
    default       => Tier::Bronze,
};
```

## Sum type with data

```php
class Circle
{
    public float $radius = 0.0;
}

class Rectangle
{
    public float $w = 0.0;
    public float $h = 0.0;
}

function shape_area(Circle|Rectangle $shape): float
{
    return match (true) {
        $shape instanceof Circle    => 3.14159 * $shape->radius ** 2,
        $shape instanceof Rectangle => $shape->w * $shape->h,
    };
}
```

## Newtype

Wrap an identifier or a primitive that crosses a boundary in a one-field `readonly` class, so the type separates values a raw `int` would conflate.

```php
namespace app\ledger;

readonly class AccountId
{
    function __construct(
        public int $value
    ) {}
}
```

## Error conversion at the boundary

A module's error enum is part of its public surface; a foreign one is not.

```php
$row = store\account_load($db, $id);
if ($row instanceof store\LoadError) {
    return match ($row) {
        store\LoadError::NotFound => ledger\AccountError::NotFound,
        store\LoadError::Timeout  => ledger\AccountError::Unavailable,
    };
}
```

The caller branches on the enum, not on the internals of whatever you called.

## Guard clauses

Bounce invalid input at the top, one condition per guard, each returning an error value. The happy path runs unindented at the bottom.

```php
function account_debit(
    Account $account,
    int     $amount_cents,
): DebitResult
{
    if ($account->frozen) {
        return new DebitResult(error: AccountError::Frozen);
    }
    if ($amount_cents <= 0) {
        return new DebitResult(error: AccountError::InvalidAmount);
    }
    if ($account->balance_cents < $amount_cents) {
        return new DebitResult(error: AccountError::InsufficientFunds);
    }

    $account->balance_cents -= $amount_cents;
    return new DebitResult(account: $account);
}
```

## Typed tree builder

A result builder without the language feature: variadic free functions that each return one common node type.

```php
namespace app\cli;

final readonly class Node
{
    function __construct(
        public string $name,
        public string $summary,
        public array  $flags,
        public array  $children,
    ) {}
}

final readonly class Flag
{
    function __construct(
        public string $name,
        public string $help,
    ) {}
}
```

```php
namespace app\cli\cmd;

use app\cli;

function make(string $name, string $summary, cli\Flag|cli\Node|null ...$parts): cli\Node
{
    $flags    = [];
    $children = [];
    foreach ($parts as $part) {
        match (true) {
            $part instanceof cli\Flag => $flags[]    = $part,
            $part instanceof cli\Node => $children[] = $part,
            $part === null            => null,
        };
    }
    return new cli\Node($name, $summary, $flags, $children);
}
```

```php
namespace app\cli\opt;

use app\cli;

function flag(string $name, string $help): cli\Flag
{
    return new cli\Flag($name, $help);
}
```

The tree is then a single declarative expression:

```php
$root = cmd\make('git', 'the stupid content tracker',
    cmd\make('remote', 'manage tracked repositories',
        cmd\make('add', 'add a remote',
            opt\flag('--fetch', 'fetch after adding'),
        ),
        cmd\make('remove', 'remove a remote'),
    ),
    cmd\make('commit', 'record changes',
        opt\flag('--amend', 'replace the tip commit'),
        $signed ? opt\flag('--gpg-sign', 'GPG-sign this commit') : null,
    ),
);
```

Reach for this for anything tree-shaped and declarative: routes, menus, a query/filter AST, a validation schema, layout.

## State machine

Model states as an enum and transitions as one central function. Every legal transition is listed; anything else falls out as an error value, so illegal transitions can't happen silently.

```php
namespace app\order;

enum State
{
    case Draft;
    case Placed;
    case Shipped;
    case Cancelled;
}

enum Event
{
    case Place;
    case Ship;
    case Cancel;
}

function order_transition(State $state, Event $event): State|TransitionError
{
    return match (true) {
        $state === State::Draft  && $event === Event::Place  => State::Placed,
        $state === State::Placed && $event === Event::Ship   => State::Shipped,
        $state === State::Draft  && $event === Event::Cancel => State::Cancelled,
        $state === State::Placed && $event === Event::Cancel => State::Cancelled,
        default => TransitionError::IllegalTransition,
    };
}
```

## Sequential fallible pipeline

PHP has no error-propagation operator, so don't reach for clever combinators: run each fallible step, check its error, return early.

```php
function order_place(app\Env $env, OrderRequest $request): PlaceResult
{
    $account = ledger\account_load($env->db, $request->account_id);
    if ($account->error !== null) {
        return new PlaceResult(error: PlaceError::from_ledger($account->error));
    }

    $debited = ledger\account_debit($account->account, $request->total_cents);
    if ($debited->error !== null) {
        return new PlaceResult(error: PlaceError::from_ledger($debited->error));
    }

    $order = order_create($env->db, $request, $debited->account);
    return new PlaceResult(order: $order);
}
```

## Environment struct

Construct the world once at startup, in order, in one readable function.

```php
namespace app;

final readonly class Env
{
    function __construct(
        public \PDO         $db,
        public lock\Locks   $locks,
        public mail\Mailer  $mailer,
    ) {}
}

function env_boot(Config $config): Env
{
    return new Env(
        db:     db\connect($config->dsn),
        locks:  lock\locks_create(),
        mailer: mail\mailer_create($config->smtp),
    );
}
```

```php
// narrow: this module's surface says exactly what it depends on
function account_load(\PDO $db, AccountId $id): LoadResult {}

// wide: an edge that dispatches into many modules
function request_handle(app\Env $env, http\Request $request): http\Response {}
```

## Stateful module

Some concerns are genuinely process-global: a logger, a metrics sink, a clock. Rather than threading them through every signature, a module may own explicit global state behind its functions.

```php
namespace app\log;

final class module
{
    public static ?Writer $writer = null;
}

function init(Writer $writer): void
{
    assert(module::$writer === null);
    module::$writer = $writer;
}

function info(string $message): void
{
    module::$writer?->write(Level::Info, $message);
}
```

```php
// boot, alongside env_boot
log\init(log\writer_stderr());

// anywhere, without dragging the world along
log\info('settlement started');
```

## Typed messages over interfaces

The open-set half of the dispatch table says "register behavior at runtime -> single-method interface". You almost never need the interface. A message is a typed struct, the behavior is a closure, and a stateful module hides a registry keyed by message type.

```php
namespace app\events;

final readonly class OrderPlaced
{
    function __construct(
        public int $order_id
    ) {}
}

final readonly class OrderShipped
{
    function __construct(
        public int $order_id
    ) {}
}
```

```php
namespace app\events;

final class module
{
    /** @var array<class-string, list<\Closure>> */
    public static array $handlers = [];
}

/**
 * @template T of object
 * @param class-string<T>   $message
 * @param \Closure(T): void $handler
 */
function on(string $message, \Closure $handler): void
{
    module::$handlers[$message][] = $handler;
}

function emit(object $message): void
{
    foreach (module::$handlers[$message::class] ?? [] as $handler) {
        $handler($message);
    }
}
```

The message type is the dispatch key, so the closure's parameter is checked against what `emit` will pass:

```php
// boot: wire behavior by message type
events\on(OrderPlaced::class, fn (OrderPlaced $e) => mail\receipt($e->order_id));

// anywhere: fire a typed value, never a stringly-typed name
events\emit(new OrderPlaced($order->id));
```

Tests need no mocking framework, the handler is a closure, so install one that records and assert on it:

```php
$seen = [];
events\on(OrderPlaced::class, function (OrderPlaced $e) use (&$seen): void {
    $seen[] = $e->order_id;
});
events\emit(new OrderPlaced(42));
assert($seen === [42]);
```

An interface would demand a named type per event, a class per handler, boot wiring to register the instances, and a mock object to observe calls under test. The registry-of-closures collapses all four.

Methods are never necessary. A method is just a function with an implicit first argument and a dispatch table welded to a type. A function over that type gives you the same dispatch.