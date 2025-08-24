# Flowlight – LightService‑style Guide

---

## Why Flowlight?

Business flows become complex fast: validations, mapping, persistence, notifications, branching… Flowlight keeps each step small and composable, and gives you a single **Context** object that carries state through the pipeline. The result is code that reads like a story: **Think SRP: keep each action tiny (do one thing), then compose them.**

```
(Validate Input) → (Normalize) → (Persist) → (Notify)
```

## ✨ Features

- **Composable pipelines** — chain multiple actions together with clarity.
- **Success & failure handling** — uniform error propagation.
- **Testable** — encourages isolated and unit-tested business logic.
- **Lightweight** — minimal dependencies, built for PHP 8.3+.

---

## Installation

```bash
composer require omnitech-solutions/flowlight
```

---

## 🧩 Core Building Blocks

### **Action**

- Base unit of work.
- Extend `Flowlight\Action` and implement `executed(Context $ctx): void`.
- Called through `::execute($ctx)`, which wraps lifecycle hooks and error handling.

```php
class CalculateDiscount extends Action
{
    protected function executed(Context $ctx): void
    {
        $amount = $ctx->params()->get('amount');
        $ctx->withParams(['discount' => $amount * 0.1]);
    }
}
```

---

### **Context**

- Execution container passed through the pipeline.
- Holds **inputs**, **params**, **errors**, **resources**, and **metadata**.
- Provides fluent helpers (`withParams`, `withErrors`, `withResource`, …).
- Tracks **operation type** (`CREATE`/`UPDATE`) and **status** (`INCOMPLETE`, `COMPLETE`, `FAILED`).
- Raised errors are captured into `$ctx->errorInfo` instead of bubbling up, keeping pipelines resilient.

```php
$ctx = Context::makeWithDefaults(['amount' => 100]);

$out = CalculateDiscount::execute($ctx);

if ($out->success()) {
    echo $out->paramsArray()['discount']; // 10
}
```

---

### **BaseDto**

- Base class for Data Transfer Objects.
- Define **validation rules** using Laravel’s validator.
- Rules adapt automatically depending on `ContextOperation` (`CREATE` keeps `id/uuid`, `UPDATE` drops them).

```php
class UserDto extends BaseDto
{
    protected static function rules(): Collection
    {
        return collect([
            'email' => ['required', 'email'],
            'name'  => ['required', 'string'],
        ]);
    }
}
```

---

### ValidatorAction

A specialized Action that validates and maps data around a DTO.

- Provide a DTO via `protected static function dtoClass(): string`.
- Optionally supply **before/after mappers** to shape data:
    - `beforeValidationMapper()` – normalize raw input → map expected DTO keys
    - `afterValidationMapper()` – enrich/rename after validation (e.g., add `normalized_email`)
- The base class takes care of applying mappers, validating, and merging results into `ctx->params`.

```php
use Flowlight\ValidatorAction;
use Flowlight\Context;

class RegisterUserValidate extends ValidatorAction
{
    protected static function dtoClass(): string
    {
        return UserDto::class;
    }

    protected static function beforeValidationMapper(): callable|string|null
    {
        return static function (mixed $in): array {
            $data = is_array($in) ? $in : (array) $in;
            return [
                'email' => $data['user_email'] ?? null,
                'name'  => $data['full_name'] ?? null,
                // pass‑throughs allowed
                'id'    => $data['id']   ?? null,
                'uuid'  => $data['uuid'] ?? null,
            ];
        };
    }

    protected static function afterValidationMapper(): callable|string|null
    {
        return static function (mixed $in): array {
            $data = is_array($in) ? $in : (array) $in;
            return [
                ...$data,
                'normalized_email' => isset($data['email']) && is_string($data['email'])
                    ? strtolower($data['email'])
                    : null,
            ];
        };
    }
}
```

> **Note on hooks:** LightService’s `executed do |ctx|` block maps naturally to Flowlight’s `Action::executed($ctx)`. For validation flows, prefer two steps: a `ValidatorAction` to populate `params`, followed by a plain `Action` that performs the side‑effect (DB write, API call, etc.).

---

### **Organizer**

- Groups multiple Actions into a **pipeline**.
- Executes each in order, passing the same `Context`.
- Stops early if context fails.

```php
class CheckoutOrganizer extends Organizer
{
    protected static function actions(): array
    {
        return [
            CalculateDiscount::class,
            ChargePayment::class,
            SendReceipt::class,
        ];
    }
}

$ctx = Context::makeWithDefaults(['amount' => 100]);
$out = CheckoutOrganizer::execute($ctx);
```

---

### **Orchestrator**

- Higher-level control for running multiple organizers or nested pipelines.
- Useful for **complex workflows** or branching logic.

```php
class OrderOrchestrator extends Orchestrator
{
    protected static function organizers(): array
    {
        return [
            CheckoutOrganizer::class,
            FulfillmentOrganizer::class,
        ];
    }
}
```

---

## Putting It Together (LightService‑style)

A common pattern is **validate → persist**.

```php
use Flowlight\Action;
use Flowlight\Context;

class PersistUser extends Action
{
    protected function executed(Context $ctx): void
    {
        // At this point params are validated and normalized
        $user = User::create($ctx->paramsArray());
        $ctx->withResource('user', $user)->markComplete();
    }
}

$ctx = Context::makeWithDefaults([
    'user_email' => 'USER@EXAMPLE.COM',
    'full_name'  => 'Des O’Leary',
]);

$ctx = RegisterUserValidate::executeForCreateOperation($ctx);
if ($ctx->success()) {
    $ctx = PersistUser::execute($ctx);
}

if ($ctx->success()) {
    $user = $ctx->resource()->get('user');
} else {
    // handled errors live on the context
    logger()->error('signup failed', [
        'errors'    => $ctx->errorsArray(),
        'errorInfo' => $ctx->errorInfo(), // summarized throwable if recorded
    ]);
}
```

---

## Stopping the Series of Actions

When an action detects a business/validation problem, record it on the context. Two common options:

1. **Mark failed and continue returning the context** (downstream steps won’t run in an Organizer):

```php
$ctx->withErrors(['email' => ['already taken']])->markFailed();
```

2. **Abort immediately** (throws a `ContextFailedError`, typically caught by the runner):

```php
$ctx->addErrorsAndAbort(['base' => ['invalid state']], 'Cannot proceed');
```

Either way, downstream code can check `success()`/`failure()`.

> **Handled exceptions:** If you catch an exception inside an action and call `$ctx->recordRaisedError($e)`, its summary becomes available at `ctx->errorInfo` for logging/inspection. Once you’ve recorded errors and set failure on the context, the library does not rethrow by default.

---

## CREATE vs UPDATE

Validation often differs between *creating* and *updating*. The context stores the desired operation in `meta['operation']` and exposes helpers:

```php
$ctx->markCreateOperation(); // or $ctx->markUpdateOperation();
if ($ctx->createOperation()) { /* … */ }
```

`BaseDto` uses this to keep `id`/`uuid` rules on **CREATE** and drop them on **UPDATE**.

---

## Testing Tips

- **Action unit tests:** construct a context with only the inputs you need, run `::execute`, assert on `params`, `resources`, and `status`.
- **ValidatorAction tests:** send intentionally bad input and assert that `errors` are merged and the context is marked failed.
- **Organizer tests:** treat them like integration tests—assert short‑circuiting on failure and final context state on success.

---

## Quick Reference

- `Context::makeWithDefaults(array $input = [], array $overrides = [])`
- `Context#withInputs/withParams/withErrors/withResource/withMeta/withInternalOnly`
- `Context#markCreateOperation()/markUpdateOperation()`
- `Context#markComplete()/markFailed()`
- `Context#addErrorsAndAbort()`
- `Action::execute(Context $ctx): Context` → `perform(Context $ctx): void`
- `ValidatorAction::execute*(Context $ctx)` + `dtoClass()` + optional `before/after` mappers
- `Organizer::execute(Context $ctx)` + `protected static function actions(): array`
- `Orchestrator::execute(Context $ctx)` + `protected static function organizers(): array`

---

## 📦 Dependencies

**Runtime**

- **PHP 8.3+** — language requirement
- **ext-json** — required for array/JSON handling
- **illuminate/config** (10–12) — lightweight config handling
- **illuminate/container** (10–12) — IoC container integration
- **illuminate/support** (10–12) — collections, helpers
- **illuminate/translation** (10–12) — required for validation messages
- **illuminate/validation** (10–12) — validation engine used by DTOs
- **spatie/laravel-data** — DTO base class with mapping/serialization support

**Development**

- **pestphp/pest** — modern PHP test runner
- **phpstan/phpstan** — static analysis
- **laravel/pint** — opinionated code formatter
- **infection/infection** — mutation testing
- **squizlabs/php\_codesniffer** — additional style/linting
- **roave/security-advisories** — security meta-package

---

## 📂 Project Structure

```
├── src/
│   ├── BaseDto.php                # Base class for DTOs, rule definitions & validation
│   ├── Context.php                # Execution container for pipelines (inputs, params, errors, etc.)
│   ├── ValidatorAction.php        # Abstract Action with DTO validation & mappers
│   ├── Action.php                 # Base Action interface (execute/perform contract)
│   ├── Organizer.php              # Runs multiple actions in sequence (a pipeline)
│   ├── Orchestrator.php           # Runs multiple organizers or pipelines
│   ├── Enums/
│   │   ├── ContextStatus.php      # Enum: INCOMPLETE | COMPLETE | FAILED
│   │   └── ContextOperation.php   # Enum: CREATE | UPDATE (affects DTO validation rules)
│   └── Utils/
│       ├── CollectionUtils.php    # Helpers for merging, compacting, deduplicating collections
│       └── LangUtils.php          # Class matching / reflection helpers
│
├── tests/
│   ├── BaseDtoTest.php            # Validates DTO rules, CREATE vs UPDATE behavior
│   ├── ContextTest.php            # Tests Context mutators, operations, errors, status handling
│   ├── ValidatorActionTest.php    # Tests Action execution with before/after mappers
│   └── Utils/
│       └── CollectionUtilsTest.php # Tests Collection utility functions
│
├── composer.json                  # Dependencies & autoloading
├── phpunit.xml                    # PHPUnit / Pest configuration
├── phpstan.neon.dist              # PHPStan config for static analysis
├── Makefile                       # Automation (lint, test, analyse, ci)
└── README.md                      # Project documentation (you’re reading it!)
```

---

## 🧪 Testing

This project uses [Pest](https://pestphp.com/) for testing.

Run the test suite:

```bash
make test
```

Run a specific test:

```bash
make test TEST=tests/Unit/ExampleTest.php
```

### Coverage

Generate coverage reports (requires Xdebug or PCOV):

```bash
make coverage
```

Open the HTML report:

```bash
make coverage-show
```

---

## 🧹 Code Quality

### Linting / Formatting

```bash
make lint           # Auto-fix with Pint
make format:check   # Check formatting only
```

### Static Analysis

```bash
make analyse
```

Update the PHPStan baseline:

```bash
make analyse:update-baseline
```

---

## 🛠 Tooling

- **Testing**: [PestPHP](https://pestphp.com/)
- **Coverage**: Xdebug / PCOV
- **Code style**: [Laravel Pint](https://laravel.com/docs/pint)
- **Static analysis**: [PHPStan](https://phpstan.org/)
- **Mutation testing**: [Infection](https://infection.github.io/)

---

## 🔧 Makefile Targets

| Command                        | Description                                          |
| ------------------------------ | ---------------------------------------------------- |
| `make install`                 | Install dependencies                                 |
| `make test`                    | Run tests (supports `TEST=...`)                      |
| `make coverage`                | Run tests with coverage                              |
| `make coverage-show`           | Open HTML coverage report                            |
| `make lint`                    | Run Pint to auto-fix style                           |
| `make static`                  | Run static analysis (PHPStan + Pint check)           |
| `make analyse`                 | Run PHPStan                                          |
| `make analyse:update-baseline` | Regenerate PHPStan baseline                          |
| `make clean`                   | Remove caches & artifacts                            |
| `make ci`                      | Run full pipeline (lint + static + tests + coverage) |



---

## 🤝 Contributing

1. Fork the repo and create a branch
2. Install dependencies with `make install`
3. Run `make ci` before submitting a PR

---

## 📄 License

MIT © [Desmond O’Leary](https://github.com/desoleary)



