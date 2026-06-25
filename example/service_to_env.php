<?php

// =============================================================================
// From controllers + container to modules + Env
// -----------------------------------------------------------------------------
// A side-by-side of the same feature — settle an invoice over HTTP — written
// first the way most PHP frameworks teach it, then the way this style does it.
//
// The point of interest for a PHP dev is PART 2's central idea: a *module owns
// its own Env*. There is no container, no controller, no constructor injection.
// A "service" turns out to be two ordinary things — a struct of dependencies
// and free functions that take it — wired together once at the bottom of the
// file. Read PART 1, then PART 2, then the notes at the very end.
// =============================================================================


// =============================================================================
// PART 1 — The classical setup: container, controller, service
// =============================================================================
// Three layers, each existing mostly to satisfy the layer above it. The
// container resolves the controller, the controller delegates to the service,
// the service reaches its dependencies through private fields it was injected
// with. An interface sits in front of each collaborator out of habit.
namespace legacy;

use Psr\Container\ContainerInterface;

interface InvoiceRepositoryInterface
{
    public function find(string $id): ?Invoice;
    public function save(Invoice $invoice): void;
}

interface PaymentGatewayInterface
{
    public function charge(int $amount_cents, string $token): bool;
}

// The service: dependencies hidden in fields, behavior welded on as methods.
// To be container-friendly it is a sealed unit behind an interface, even though
// there will only ever be one implementation.
final class BillingService
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private PaymentGatewayInterface    $gateway,
        private \PsrLogger                 $logger,
    ) {}

    public function settle(string $id, string $card_token): Receipt
    {
        $invoice = $this->invoices->find($id);
        if ($invoice === null) {
            throw new NotFoundException($id);          // control flow by exception
        }
        if ($invoice->paid) {
            throw new ConflictException('already paid');
        }

        $ok = $this->gateway->charge($invoice->amount_cents, $card_token);
        if (!$ok) {
            throw new PaymentFailedException();
        }

        $invoice->paid = true;
        $this->invoices->save($invoice);
        $this->logger->info('settled', ['invoice' => $id]);

        return new Receipt($invoice->amount_cents);
    }
}

// The controller: a class whose only job is to translate HTTP <-> service call
// and turn the service's exceptions back into status codes.
final class BillingController
{
    public function __construct(private BillingService $billing) {}

    public function settle(Request $request): Response
    {
        try {
            $receipt = $this->billing->settle(
                $request->route('id'),
                $request->input('card_token'),
            );
            return new JsonResponse(201, ['paid' => $receipt->amount_cents]);
        } catch (NotFoundException) {
            return new JsonResponse(404, ['error' => 'not found']);
        } catch (ConflictException) {
            return new JsonResponse(409, ['error' => 'already paid']);
        } catch (PaymentFailedException) {
            return new JsonResponse(402, ['error' => 'payment failed']);
        }
    }
}

// The wiring: a container configured to autowire all of the above, lazily, by
// interface, rebuilt (on classic PHP) or cached (on a long runtime) for you.
function legacy_container_setup(ContainerInterface $c): void
{
    $c->bind(InvoiceRepositoryInterface::class, SqlInvoiceRepository::class);
    $c->bind(PaymentGatewayInterface::class, StripeGateway::class);
    $c->singleton(BillingService::class);
    $c->singleton(BillingController::class);
    // ...and a route table mapping POST /invoices/{id}/settle to the controller.
}


// =============================================================================
// PART 2 — The same feature as a module that owns its Env
// =============================================================================
// One namespace = one module (see docs/MODULES.md). The module `billing` owns
// the Invoice type, its errors, its dependencies, and the functions over them.
// No controller class, no service class, no interfaces, no container.
namespace app\billing;

use app\{
    http,
    store,
    payment,
};

// -- The Env: this module's dependency bundle -------------------------------
//
// This is the novel part for most PHP devs, so read slowly.
//
// In PART 1 the dependencies lived as private fields on BillingService, put
// there by the container through the constructor. Here they live as public
// fields on a plain struct that the module owns. That's the whole trick: the
// "service object" splits into (a) this bundle of long-lived dependencies, and
// (b) the free functions below that take it as their first argument.
//
// It holds only what `billing` needs, and it is built ONCE at startup (see the
// composition root at the bottom). Heavy external state — the DB pool, the
// payment client — lives here for the life of the process, shared by every
// request. Nothing in here is per-request.
class Env
{
    public store\Pool      $db;       // long-lived: a connection pool, not a connection
    public payment\Client  $gateway;  // long-lived: an upstream HTTP client
    public Logger          $log;
}

// -- The errors: values, not exceptions -------------------------------------
//
// PART 1 threw NotFoundException / ConflictException / PaymentFailedException
// and caught them in the controller. Here the failures are a closed set of
// values returned up the call. The caller must handle them; it cannot forget.
#![internal]
enum SettleError
{
    case NotFound;
    case AlreadyPaid;
    case PaymentFailed;
}

// -- The core operation: a free function over the Env ------------------------
//
// This IS BillingService::settle, with the dependencies arriving as $env
// instead of $this. Same logic, but it returns its error instead of throwing,
// and it leases its connection from the pool and releases it at the edge.
function invoice_settle(
    Env    $env,
    string $id,
    string $card_token,
): Receipt|SettleError
{
    // The pool is process-lived; a single connection is request-lived. Borrow
    // one, and return it no matter how we leave (see docs/STYLE.md, clean up at
    // the edge). On Swoole the scheduler hands the connection to another
    // coroutine while this one waits on I/O.
    $conn = store\pool_acquire($env->db);
    try {
        $invoice = store\invoice_find($conn, $id);
        if ($invoice === null) {
            return SettleError::NotFound;
        }
        if ($invoice->paid) {
            return SettleError::AlreadyPaid;
        }

        $charged = payment\charge($env->gateway, $invoice->amount_cents, $card_token);
        if (!$charged) {
            return SettleError::PaymentFailed;
        }

        $invoice->paid = true;
        store\invoice_save($conn, $invoice);
        $env->log->info('settled', ['invoice' => $id]);

        return new Receipt($invoice->amount_cents);
    } finally {
        store\pool_release($env->db, $conn);
    }
}

// -- The HTTP edge: what the controller used to be ---------------------------
//
// No controller class — just a handler function. It receives the module's Env
// (long-lived deps) and the per-request Ctx (the request, route params, etc.),
// calls the core, and maps the returned error to a status. The exhaustive
// match means a new SettleError case won't compile until it's handled here.
#[Route(Method::POST, '/invoices/{id}/settle')]
function invoice_settle_page(Env $env, http\Ctx $ctx): void
{
    $result = invoice_settle(
        $env,
        http\ctx_param($ctx, 'id'),
        $ctx->request->input('card_token'),
    );

    if ($result instanceof Receipt) {
        http\ctx_json($ctx, http\Status::CREATED, ['paid' => $result->amount_cents]);
        return;
    }

    $status = match ($result) {
        SettleError::NotFound      => http\Status::NOT_FOUND,
        SettleError::AlreadyPaid   => http\Status::CONFLICT,
        SettleError::PaymentFailed => http\Status::PAYMENT_REQUIRED,
    };
    http\ctx_json($ctx, $status, ['error' => $result->name]);
}


// =============================================================================
// PART 3 — The composition root: wiring, in plain sight, built once
// =============================================================================
// This replaces the container config from PART 1. There is no autowiring and no
// resolution by interface — just `new`, bottom to top, at one greppable site.
// Subsystems compose by one Env holding another: if `billing` called `ledger`,
// its Env would hold a `ledger\Env`. Siblings meet only here.
namespace app;

use app\{
    billing,
    store,
    payment,
    http,
};

function boot(config\Config $config): billing\Env
{
    $env = new billing\Env();
    $env->db      = store\pool_open($config->dsn, size: 16);   // built ONCE
    $env->gateway = payment\client_open($config->stripe_key);
    $env->log     = log\make(STDERR);
    return $env;
}

function main(): void
{
    $config = config\from_env();
    $env    = boot($config);

    // The server holds the long-lived $env and builds a small per-request Ctx
    // for each connection. Handlers get both: deps on the left, request on the
    // right. Compare PART 1, where the container built the whole graph per
    // request (classic PHP) or hid the lifetimes behind scopes.
    http\serve($config->port, static function (http\Ctx $ctx) use ($env): void {
        http\route($env, $ctx);   // dispatches to billing\invoice_settle_page, etc.
    });
}


// =============================================================================
// NOTES — why this shape, for a reader coming from controllers + containers
// =============================================================================
//
// 1. A "service" was never one thing. It was a dependency bundle plus methods.
//    We named the bundle (Env) and freed the methods (free functions over it).
//    Constructor injection becomes one `new` at the composition root.
//
// 2. A module owns its Env. The Env is not app-wide; `billing` has its own,
//    holding only what billing needs. Read a function's signature and you know
//    exactly what it can touch — the dependency surface a container obscured is
//    now right there in the type. (docs/MODULES.md, docs/MIGRATING_FROM_DI.md)
//
// 3. Long-lived vs per-request are two different values. The Env (pools,
//    clients) is built once and shared; the Ctx (request, params, a leased
//    connection) is built per request and thrown away. The container's
//    "singleton" and "scoped" lifetimes were just these two, hidden.
//
// 4. Heavy external state stays *behind* the Env. The handler holds a reference
//    to billing\Env and calls one function; it never enumerates the pool and
//    the gateway itself. That is the encapsulation the service object gave you,
//    kept — minus the ceremony.
//
// 5. Errors are values. No try/catch ladder in the handler; an exhaustive match
//    the compiler checks. Exceptions are reserved for the genuinely exceptional
//    (docs/STYLE.md, error handling).
//
// 6. No interface-per-class. PaymentGatewayInterface with one implementation is
//    gone; `payment\charge` is a free function. Add an interface only when a
//    second implementation or a test stub genuinely needs one (docs/PATTERNS.md,
//    closed set vs open set).
//
// 7. Explicitness, and why it matters under coroutines. PART 1's BillingService
//    reaches its state through $this — ambient and invisible in any signature.
//    PART 2 has no $this: state arrives as $env and parameters, and the only
//    mutable thing in invoice_settle is the connection it leased, which is local
//    to this call. That is not just tidier; it is safe under concurrency. A
//    method that mutates $this->field and suspends on I/O mid-update can be
//    re-entered by another coroutine that sees the half-updated field (actor
//    reentrancy). With state passed in and out, a signature tells the truth about
//    what a function touches, and "follow what mutates it" — how you actually
//    debug such a bug — stays possible. (docs/MIGRATING_FROM_DI.md, explicitness;
//    docs/CONCURRENCY.md)
