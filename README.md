# PHP Coding Style

Scope: PHP source only. Baseline is PER Coding Style (formerly PSR-12) for
formatting. This document overrides the baseline for identifier casing.

## Identifiers

| Construct                          | Casing            | Example              |
| ---------------------------------- | ----------------- | -------------------- |
| Variable                           | `snake_case`      | `$order_line`        |
| Property                           | `snake_case`      | `$created_at`        |
| Parameter                          | `snake_case`      | `int $row_count`     |
| Namespace                          | `snake_case`      | `app\order_book`     |
| Class, interface, trait, enum      | `PascalCase`      | `OrderLine`          |
| Enum case                          | `PascalCase`      | `Status::Active`     |
| Method                             | `camelCase`       | `getTotal()`         |
| Free function                      | `snake_case`      | `array_merge()`      |
| Constant, enum constant            | `UPPER_SNAKE_CASE`| `MAX_SIZE`           |

Variables and properties use `snake_case`. Namespaces use `snake_case`. All
type names (class, interface, trait, enum) retain `PascalCase`. Methods retain
`camelCase`. Free functions and constants follow the php-src conventions.

## Acronyms

Treat acronyms as regular words: first letter cased per the rule, the rest
lowercase. Applies inside any identifier.

- `HttpClient`, not `HTTPClient`
- `parseXmlId()`, not `parseXMLID()`
- `$user_id`, not `$user_iD`

## Formatting

Defer to PER Coding Style. Key points:

- 4 spaces per indent level. No tabs.
- One class/interface/trait/enum per file.
- Opening brace on its own line for classes, methods, and functions. Same line
  for control structures.
- `declare(strict_types=1);` at the top of every file.
- One blank line after the namespace declaration and after the `use` block.

## Enforcement

Enforce with PHP-CS-Fixer or PHP_CodeSniffer (`PER`/`PSR12` ruleset) for
formatting. Identifier casing in this document is project policy and is not
covered by the official standards, so configure custom sniffs if automated
checks are required.

