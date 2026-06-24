# Patterns

Replicate modern-language control flow in PHP, without the machinery.

## Dispatch: closed set vs open set

A closed set of variants — known when you write the code — is an enum, dispatched
by one central `match`. Polymorphism becomes a value on the belt; dispatch becomes
a switch.

```php
namespace app\notify;

// The "interface" Notifier with send() becomes a tag...
enum Channel
{
    case Email;
    case Sms;
    case Push;
}

// ...and the "implementations" become arms of one central match: no closure, no
// interface, no object, just data in and a free function per arm.
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

## Sum type with data

An enum is a tag with no fields. When a variant carries data, give each variant a
record and return a union; `match` on the type.

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
