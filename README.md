# Flowlight — LightService‑style Guide

---

## Table of Contents
- [Why Flowlight?](#why-flowlight)
- [Features](#features)
- [Installation](#installation)
- [Project Structure](#project-structure)
- [Core Concepts](#core-concepts)
    - [Context](#context)
    - [Action](#action)
    - [Organizer](#organizer)
- [Failure & Control Flow](#failure--control-flow)
    - [Outcomes](#outcomes)
    - [withErrors (merge only)](#witherrors-merge-only)
    - [withErrorsThrowAndReturn (merge + stop)](#witherrorsthrowandreturn-merge--stop)
    - [throwAndReturn (message/code + stop)](#throwandreturn-messagecode--stop)
    - [Internal control‑flow exception: JumpWhenFailed](#internal-control-flow-exception-jumpwhenfailed)
    - [WithErrorHandler (unexpected throws → context failure)](#witherrorhandler-unexpected-throws--context-failure)
- [Usage](#usage)
    - [Quick Start (Organizer)](#quick-start-organizer)
    - [Validator Action pattern](#validator-action-pattern)
    - [Service code with WithErrorHandler](#service-code-with-witherrorhandler)
    - [Reading results](#reading-results)
- [Testing Guidelines](#testing-guidelines)
- [Planned / Not Implemented](#planned--not-implemented)
- [Minimal API Reference](#minimal-api-reference)

---

## Why Flowlight?
Business flows grow complex quickly: validation, mapping, persistence, notifications, branching. **Flowlight** keeps each step small and composable, carrying state via a single **Context** so the code reads like a story:

```
(Validate) → (Normalize) → (Persist) → (Notify)
```

## Features
- **Composable pipelines** — Actions and Organizers chain clearly.
- **Validation as data** — accumulate errors; stop intentionally.
- **Unified exception capture** — normalize unexpected throws into the Context.
- **Lightweight** — PHP ≥ 8.2, minimal deps.

> **Not Implemented (TBD)**: lifecycle hooks (before/after/around), skip‑remaining, expects/promises, structured logging.

## Installation
```bash
composer require omnitech-solutions/flowlight
```

## Project Structure
```
src/
  Action.php
  Organizer.php
  Context.php
  Enums/ContextStatus.php
  Traits/WithErrorHandler.php
  Exceptions/
    ContextFailedError.php
    JumpWhenFailed.php
```

## Core Concepts

### Context
Carries inputs, params, errors, resources, and diagnostics.

- Errors are grouped by key (e.g., `email`) with a `base` bucket for global messages.
- Diagnostics live under `internalOnly` (e.g., `message`, `error_code`, `errorInfo`).
- Public callers consume `success()` / `failure()` and `errorsArray()`.

### Action
Extend `Flowlight\Action` and implement `perform(Context $ctx): void`.

```php
use Flowlight\Action;
use Flowlight\Context;

final class CalculateDiscount extends Action
{
    protected function perform(Context $ctx): void
    {
        $amount = $ctx->paramsArray()['amount'] ?? null;
        if (!is_numeric($amount)) {
            $ctx->withErrors(['amount' => 'must be numeric']);
            $ctx->throwAndReturn('Validation failed'); // control flow unwinds
        }

        $ctx->withParams(['discount' => (float)$amount * 0.1]);
        // completion is set internally when appropriate
    }
}
```

### Organizer
Declares a sequence of steps; each step receives the same Context.

- Define steps by overriding `protected static function steps(): array`.
- Call via `Organizer::call(array $input = [], array $overrides = [], ?callable $transformContext = null): Context`.

```php
use Flowlight\Organizer;

final class CheckoutOrganizer extends Organizer
{
    protected static function steps(): array
    {
        return [
            \App\Actions\ValidateCheckout::class,
            \App\Actions\CalculateDiscount::class,
            \App\Actions\ChargePayment::class,
            \App\Actions\SendReceipt::class,
        ];
    }
}
```

## Failure & Control Flow

### Outcomes
Use `success()` / `failure()` to decide how to render results. Public code should not depend on internal flags.

### withErrors (merge only)
Accumulates errors without stopping the chain.

```php
$ctx->withErrors([
  'email' => ['is invalid', 'is required'],
  'base'  => ['Please correct the highlighted fields'],
]);
```

### withErrorsThrowAndReturn (merge + stop)
Accumulates errors, sets an optional message/code, then stops immediately using internal control flow.

```php
$ctx->withErrorsThrowAndReturn(
  ['email' => 'is invalid'],
  'Validation failed',
  ['error_code' => 1001]
);
```
**Result (illustrative) once the organizer returns:**
- `errorsArray()` ⇒ `['email' => ['is invalid'], 'base' => ['Validation failed']]`
- `internalOnly()` ⇒ `['message' => 'Validation failed', 'error_code' => 1001]`

### throwAndReturn (message/code + stop)
Stops immediately with a message/code, without attaching field errors.

```php
$ctx->throwAndReturn('Unauthorized');
// or
$ctx->throwAndReturn('Upstream unavailable', ['error_code' => 502]);
```

### Internal control‑flow exception: JumpWhenFailed
Internal exception used to unwind quickly after a *throw‑and‑return* path. The organizer boundary catches it and normalizes the Context.

### WithErrorHandler (unexpected throws → context failure)
Wrap risky code; unexpected exceptions are recorded into the Context with a human message and the pipeline is stopped. Optional `rethrow` propagates after recording.

```php
use Flowlight\Traits\WithErrorHandler;

final class ExternalCallService
{
    use WithErrorHandler;

    public function run(\Flowlight\Context $ctx): void
    {
        self::withErrorHandler($ctx, static function (\Flowlight\Context $c): void {
            performExternalCall(); // may throw
        }, rethrow: false);
    }
}
```

## Usage

### Quick Start (Organizer)
```php
$out = CheckoutOrganizer::call(['amount' => 100]);

if ($out->success()) {
    echo $out->paramsArray()['discount'] ?? '';
} else {
    $errors = $out->errorsArray();
}
```

### Validator Action pattern
Accumulate rule errors, then stop once you decide it’s terminal.

```php
final class ValidateCheckout extends \Flowlight\Action
{
    protected function perform(\Flowlight\Context $ctx): void
    {
        $p = $ctx->paramsArray();

        if (empty($p['email'])) {
            $ctx->withErrors(['email' => 'is required']);
        }
        if (!empty($p['age']) && $p['age'] < 18) {
            $ctx->withErrors(['age' => 'must be 18+']);
        }

        if (!empty($ctx->errorsArray())) {
            $ctx->withErrorsThrowAndReturn($ctx->errorsArray(), 'Validation failed');
        }
    }
}
```

### Service code with WithErrorHandler
See the trait example above. Keep validation failures (expected) separate from true exceptions (unexpected).

### Reading results
Consume `success()` / `failure()` and `errorsArray()`; avoid internal flags.

## Testing Guidelines
- **Action tests** — create Context via `Context::makeWithDefaults`, execute, assert params/resources/errors.
- **ValidatorAction tests** — feed invalid input, assert errors shape and that the organizer stops on throw‑and‑return.
- **Organizer tests** — assert short‑circuiting and happy‑path composition.
- **WithErrorHandler tests** — cover callable + Throwable‑proxy paths, and `rethrow`.

## Planned / Not Implemented
- Lifecycle hooks (before/after/around)
- Skip remaining (`skipRemaining()` parity)
- Expects & Promises (compile/runtime guards)
- Structured logging around organizer/action boundaries

## Minimal API Reference

**Context**
- `withErrors(array|Traversable $errs): self` — merge errors (no stop).
- `withErrorsThrowAndReturn(array|Traversable $errs, ?string $message = null, array|int $optionsOrErrorCode = []): self` — merge + stop.
- `throwAndReturn(?string $message = null, array|int $optionsOrErrorCode = []): self` — stop with message/code only.
- `errorsArray(): array` — user‑facing errors (incl. `base`).
- `internalOnly(): ArrayAccess|array` — diagnostics (`message`, `error_code`, `errorInfo`).
- `success(): bool` / `failure(): bool`

**Organizer**
- `protected static function steps(): array`
- `public static function call(array $input = [], array $overrides = [], ?callable $transformContext = null): Context`

**Traits\WithErrorHandler**
- `withErrorHandler(Context $ctx, callable|Throwable $blockOrThrowable, bool $rethrow = false): void`

**Exceptions**
- `JumpWhenFailed` — internal control‑flow exception.
- `Exceptions\ContextFailedError` — exception carrying a Context.

