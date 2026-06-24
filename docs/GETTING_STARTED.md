# Overview

A guided tour from an empty directory to a running app, then to your own reusable
library, using the conventions the rest of these docs describe. You're assumed to
know PHP; this page shows how the pieces fit, not the language.

For the reference material behind each step, see [MODULES.md](MODULES.md) (the module
unit) and [ARCHITECTURE.md](ARCHITECTURE.md) (how modules compose).

## Contents

- [Hello, module](#hello-module)
- [A module is a namespace](#a-module-is-a-namespace)
- [Functions over a family of types](#functions-over-a-family-of-types)
- [Visibility: internal and local](#visibility-internal-and-local)
- [Importing other modules](#importing-other-modules)
- [Errors are values](#errors-are-values)
- [The composition root](#the-composition-root)
- [Config first](#config-first)
- [Capabilities and the env](#capabilities-and-the-env)
- [Serving requests](#serving-requests)
- [Adding a second module](#adding-a-second-module)
- [The dependency DAG](#the-dependency-dag)
- [Pure core, thin shell](#pure-core-thin-shell)
- [Extracting a library](#extracting-a-library)
- [Config and Deps](#config-and-deps)
- [The boot function](#the-boot-function)
- [What not to do](#what-not-to-do)
- [Checklist](#checklist)

## Hello, module

The smallest unit you build with is a module. Start a project with exactly one, and
a composition root to run it.

```
📁 src/
  📄 loader.php        # loads every file at startup; no PSR-4 lazy-loading
  📁 app/
    📁 shorten/        # the one module: namespace app\shorten
      📄 link.php
📄 server.php          # the composition root
```

## A module is a namespace

A module is one flat word under a vendor root, and the directory mirrors it. PHP has
no real module unit, so a namespace plays the part.

```php
namespace app\shorten;
```

**Note:** Err coarse. A module is a subsystem, not a single type. Begin with one and
split only when a second subsystem genuinely emerges.

## Functions over a family of types

A module is plain functions over the types it owns. Values come in through the
signature and values go out; the edge type (`Ctx` here) is never a global.

```php
namespace app\shorten;

use app\http;
use app\store;

#[Route(Method::GET, '/{code}')]
function link_resolve(http\Ctx $ctx): void
{
    $code   = http\ctx_param($ctx, 'code');
    $target = store\link_find($ctx->db, $code);
    if ($target === null) {
        http\ctx_text($ctx, http\Status::NOT_FOUND, 'not found');
        return;
    }

    http\ctx_redirect($ctx, http\Status::FOUND, $target);
}
```

## Visibility: internal and local

PHP has no module-private visibility, so two attributes add it. Unmarked is the
public surface.

```php
#![internal]                      // module-private: visible in the namespace only
function link_url_valid(string $url): bool { /* ... */ }

#![local]                         // file-private: rare
function link_generate_code(int $bytes): string { /* ... */ }
```

## Importing other modules

Import the namespace, not the symbol, so the prefix stays at the call site and names
its origin.

```php
// good: the prefix names where it came from
use app\util;
$v = util\clamp($x, $lo, $hi);

// avoid: a symbol import strips the origin
use function app\util\clamp;
$v = clamp($x, $lo, $hi);
```

**Note:** Class imports (`use app\ledger\Account;`) are fine. Alias only to break a
collision, never to shorten.

## Errors are values

Expected failures are return values, not exceptions. Give a module its own error
enum and return a union; the caller `match`es on it.

```php
#![local]
enum LinkError
{
    case BadJson;
    case InvalidUrl;
    case SaveFailed;
}

#![local]
function link_parse(string $body): string|LinkError
{
    $data = json_decode($body, associative: true);
    if (!is_array($data) || !isset($data['url']) || !is_string($data['url'])) {
        return LinkError::BadJson;
    }
    if (!link_url_valid($data['url'])) {
        return LinkError::InvalidUrl;
    }
    return $data['url'];
}
```

## The composition root

`server.php` is the `main()` of the executable: the one impure file where you read
config, call `new`, and choose implementations. Everything below receives what it
needs through signatures.

```php
require __DIR__ . '/src/loader.php';

use app\{
    config,
    env,
    api,
};
```

**Note:** One root per executable — `server.php` at the project root, `bin/<name>`
per worker.

## Config first

Parse config once, at the top of the root: read the raw environment and validate into
a typed `Config`. A bad value then fails at startup, not mid-request.

```php
$config = config\from_env();
```

## Capabilities and the env

Build the long-lived capabilities — the "singletons" — as plain variables, then
assemble them into a `readonly` `$env` shared across every request.

```php
$database = new \PDO($config->dsn, $config->database_user, $config->database_password, [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);

$env = new env\Env($database, $config);
```

**Note:** `$env` holds capabilities, never request data. Keep it `readonly` so the
compiler enforces that a shared singleton cannot carry per-request state.

## Serving requests

Hand the framework a one-line adapter; the logic lives in a named shell function that
gathers inputs, calls the core, and writes the result.

```php
// in server.php — the closure is just the adapter
$server->on('request', static fn ($req, $res) => api\serve($env, $req, $res));
```

```php
namespace app\api;

function serve(env\Env $env, \Swoole\Http\Request $req, \Swoole\Http\Response $res): void
{
    $request  = http\request_parse($req);    // edge: framework type -> our Request
    $response = handle($env, $request);       // assembly line: Request -> Response
    http\response_write($res, $response);     // edge: our Response -> framework type
}
```

## Adding a second module

A second subsystem is a second flat directory under `app/`, not a nested one. Many
files in one directory is the goal; near-empty trees just add path noise.

```
📁 app/
  📁 shorten/    # shell: handles requests, calls down
  📁 store/      # owns persistence
  📁 http/       # the edge: framework type <-> our Ctx/Request/Response
```

## The dependency DAG

Modules form a DAG: depend downward only, and siblings never import each other. An
operation that needs two siblings belongs in the layer above both.

```
shorten  ─┐         shorten depends on store and http
          ├─> store     store and http do not know about shorten
          └─> http      store and http never import each other
```

## Pure core, thin shell

The core decides, the shell acts. The core takes plain values and is testable without
a database; only the shell touches `$env` and I/O.

```php
// pure core: values in, decision out — no $env, no I/O
function link_url_valid(string $url): bool
{
    $is_http = str\starts_with($url, 'http://') || str\starts_with($url, 'https://');
    return $is_http && filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

## Extracting a library

A module stays inside the app until a cluster of modules has an independent reason to
be reused or booted on its own. That cluster — not a single module — is a **package**,
the unit of reuse.

**Note:** The package boundary is the *only* place dependencies stop riding the
app-wide `$env`. Do not split per module inside one app.

## Config and Deps

At the package boundary, dependencies split into two narrow values the package owns.

```php
namespace acme\mailer;

// pure data: serializable, loadable from a file
readonly class Config
{
    public function __construct(
        public string $host,
        public int    $port,
    ) {}
}

// capabilities: constructed in code
readonly class Deps
{
    public function __construct(
        public SmtpTransport $transport,
    ) {}
}
```

## The boot function

Give the package one `boot()` that turns plain `Config` into live `Deps`, so a host
app wires it in a single line.

```php
namespace acme\mailer;

function boot(Config $config): Deps
{
    $transport = new SmtpTransport($config->host, $config->port);
    return new Deps($transport);
}
```

```php
// in some app's composition root, the package is one line
$mailer = acme\mailer\boot($config->mailer);
```

## What not to do

Inside one application, do not mint a `Deps` per module — that trades the god `$env`
for wiring sprawl. Narrow the call instead.

```php
// bad: every module hands itself a bespoke environment
function link_resolve(shorten\Env $env, string $code): void { /* ... */ }

// good: a deep function takes the one capability it uses
function link_resolve(\PDO $database, string $code): void { /* ... */ }
```

## Checklist

- One module to start; split only when a second subsystem is real.
- Import the namespace, not the symbol; keep the prefix at the call site.
- Mark `#![internal]` / `#![local]`; unmarked is public.
- Errors are values: return a union, `match` at the caller.
- Pure core, thin shell: gather, decide, commit — no I/O interleaved with logic.
- One composition root per executable; `$env` carries capabilities, never request
  data.
- Reach for a package's own `Config`/`Deps` only when it lifts out for reuse.
