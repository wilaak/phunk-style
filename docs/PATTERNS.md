# Patterns

Replicate language control flows in PHP

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