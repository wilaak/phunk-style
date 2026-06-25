<?php

// =============================================================================
// From the Strategy pattern to data + control flow
// -----------------------------------------------------------------------------
// The Strategy pattern replaces a conditional with a set of interchangeable
// objects behind an interface, chosen at runtime. It is the textbook cure for
// "too many if/else". But for a *closed* set of variants — the ones you know
// when you write the code — it spends an interface, N classes, and a wiring
// step to express what is really one value and one match.
//
// This file puts the two side by side, then names the one case where the
// pattern still earns its keep. See docs/PATTERNS.md (closed set vs open set).
// =============================================================================


// =============================================================================
// PART 1 — The classical Strategy pattern
// =============================================================================
// Compute a discount. The "strategy" is the discount policy. Each policy is a
// class implementing a shared interface; a context holds one and delegates to
// it. Adding a policy means adding a class and registering it.
namespace legacy;

interface DiscountStrategy
{
    public function apply(int $subtotal_cents): int;
}

final class NoDiscount implements DiscountStrategy
{
    public function apply(int $subtotal_cents): int
    {
        return $subtotal_cents;
    }
}

final class PercentageDiscount implements DiscountStrategy
{
    public function __construct(private int $percent) {}

    public function apply(int $subtotal_cents): int
    {
        return $subtotal_cents - intdiv($subtotal_cents * $this->percent, 100);
    }
}

final class FixedDiscount implements DiscountStrategy
{
    public function __construct(private int $off_cents) {}

    public function apply(int $subtotal_cents): int
    {
        return max(0, $subtotal_cents - $this->off_cents);
    }
}

// The context: holds the chosen strategy and forwards to it. The polymorphism
// is real, but for a fixed set of policies it is indirection — the call site
// can no longer see which branch runs, and the set of branches is now scattered
// across three files instead of sitting in one place.
final class Checkout
{
    public function __construct(private DiscountStrategy $discount) {}

    public function total(int $subtotal_cents): int
    {
        return $this->discount->apply($subtotal_cents);
    }
}

// Selecting one. In a real app a container or a factory maps a config value to
// a concrete class — another layer whose only job is to choose a branch.
function legacy_make_discount(string $kind, int $amount): DiscountStrategy
{
    return match ($kind) {
        'none'    => new NoDiscount(),
        'percent' => new PercentageDiscount($amount),
        'fixed'   => new FixedDiscount($amount),
    };
}


// =============================================================================
// PART 2 — The same thing as data + a central match
// =============================================================================
// The set of policies is closed: we know all three when we write the code. So
// the "interface" becomes an enum — the tag naming each policy — and the
// "implementations" become arms of one match. The policy's data (a percent, a
// fixed amount) rides along on one small record next to the tag.
namespace app\pricing;

// The closed set the interface used to stand for, now a single enum.
enum DiscountKind
{
    case None;
    case Percent;
    case Fixed;
}

// The policy as data: which kind, and the number that kind needs.
class Discount
{
    public DiscountKind $kind   = DiscountKind::None;
    public int          $amount = 0;   // percent for Percent, cents off for Fixed
}

// One function, the whole policy set visible at once. Add a case to the enum and
// this match stops compiling until you handle it — the compiler enforces the
// exhaustiveness the pattern left to discipline.
function discount_apply(Discount $discount, int $subtotal_cents): int
{
    return match ($discount->kind) {
        DiscountKind::None    => $subtotal_cents,
        DiscountKind::Percent => $subtotal_cents - intdiv($subtotal_cents * $discount->amount, 100),
        DiscountKind::Fixed   => max(0, $subtotal_cents - $discount->amount),
    };
}

// No Checkout context, no held strategy, no factory layer. The "selection" is
// just building the value; the "behavior" is just calling the function on it.
function checkout_total(Discount $discount, int $subtotal_cents): int
{
    return discount_apply($discount, $subtotal_cents);
}

// If a variant's data differs in shape (not just a number), reach instead for a
// record per variant and a union — see docs/PATTERNS.md, sum type with data.


// =============================================================================
// PART 3 — When Strategy still earns its keep
// =============================================================================
// The pattern is right for an *open* set: variants registered by strangers at
// runtime, not known when you write the dispatch. A plugin host that loads
// payment providers it has never heard of cannot write a match over them, so a
// single-method interface is exactly the correct shape.
namespace app\plugin;

// The host ships this; third parties implement it and register at runtime. The
// host genuinely cannot enumerate the implementors, so polymorphism — not a
// match — is the honest tool.
interface PaymentProvider
{
    public function charge(int $amount_cents, string $token): bool;
}

function payment_run(PaymentProvider $provider, int $amount_cents, string $token): bool
{
    return $provider->charge($amount_cents, $token);
}


// =============================================================================
// NOTES
// =============================================================================
//
// 1. Closed set vs open set is the whole decision. Variants known when you write
//    the code -> enum/union + one central match. Variants registered by
//    strangers at runtime -> a single-method interface. (docs/PATTERNS.md)
//
// 2. Strategy spends an interface, N classes, a context, and a factory to model
//    a closed set. The match models it with one value and one function, and the
//    full set of branches is visible in one place instead of scattered.
//
// 3. Exhaustiveness is enforced, not hoped for. Add a variant to the union and
//    the match fails to compile until handled. With strategies, a forgotten
//    policy is a silent runtime gap.
//
// 4. The call site stays honest. `discount_apply($d, $n)` shows its inputs;
//    `$this->discount->apply($n)` hides which branch runs behind a held field
//    (the same ambient-state problem as docs/OOP.md, smaller).
//
// 5. Reach for the interface when a second, unknown-in-advance implementation is
//    real — not by reflex. One implementation, or a handful you control, needs
//    none.
