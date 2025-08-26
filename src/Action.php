<?php

declare(strict_types=1);

namespace Flowlight;

use Throwable;

/**
 * Base Action encapsulating a single unit of work with observable lifecycle hooks.
 *
 * Lifecycle (when not already complete):
 *  1) beforeExecute(Context): always invoked before perform()
 *  2) perform(Context): does the work; may throw or mark Context as failed/aborted
 *  3) afterExecute(Context): always invoked after perform(), even if perform() threw
 *  4) afterSuccess(Context): invoked iff no errors and not aborted (and no exception)
 *  5) afterFailure(Context): invoked iff perform() threw OR Context has errors/aborted
 *
 * Short-circuit:
 *  - If Context::isComplete() is true on entry, no hooks run and the Context is returned unchanged.
 *
 * Exceptions:
 *  - If perform() throws, execute() rethrows after running afterExecute() and afterFailure().
 *  - Callers (e.g., Organizers) are expected to translate thrown exceptions into contextual
 *    failures rather than letting them bubble up the stack.
 *
 * @phpstan-consistent-constructor
 */
abstract class Action
{
    /**
     * Hook executed immediately before perform().
     *
     * @var null|callable(Context):void
     */
    protected static $beforeExecute = null;

    /**
     * Hook executed immediately after perform() (runs for both success and failure, including throws).
     *
     * @var null|callable(Context):void
     */
    protected static $afterExecute = null;

    /**
     * Hook executed when the action completes without errors and is not aborted.
     *
     * @var null|callable(Context):void
     */
    protected static $afterSuccess = null;

    /**
     * Hook executed when perform() throws or the Context records errors/abort.
     *
     * @var null|callable(Context):void
     */
    protected static $afterFailure = null;

    protected Context $context;

    /**
     * Construct an Action with an existing Context or initial parameters.
     *
     * If an array is provided, a Context is created via Context::makeWithDefaults($params).
     * The constructed Context is tagged with the invoked Action and current Action class.
     *
     * @param  Context|array<string,mixed>  $context
     */
    public function __construct(Context|array $context = [])
    {
        $this->context = $context instanceof Context
            ? $context
            : Context::makeWithDefaults($context);

        $this->context
            ->withInvokedAction($this)
            ->setCurrentAction(static::class);
    }

    /**
     * Execute the Action and return the (possibly mutated) Context.
     *
     * Behavior:
     *  - If Context::isComplete() is true, returns immediately (no hooks executed).
     *  - Invokes beforeExecute(), then perform().
     *  - Always invokes afterExecute() once perform() returns or throws.
     *  - Invokes afterSuccess() when no errors and not aborted; otherwise afterFailure().
     *
     * @param  Context|array<string,mixed>  $context  Existing Context or initial params
     * @return Context The resulting Context after execution
     *
     * @throws Throwable If perform() throws; hooks afterExecute() and afterFailure() still run first.
     */
    public static function execute(Context|array $context = []): Context
    {
        /** @phpstan-var static $action */
        $action = new static($context);
        $ctx = $action->context;

        // Short-circuit if already complete (no hooks)
        if ($ctx->isComplete()) {
            return $ctx;
        }

        if (is_callable(static::$beforeExecute)) {
            (static::$beforeExecute)($ctx);
        }

        try {
            // Run the unit of work
            $action->perform($ctx);
        } catch (Throwable $e) {
            // Ensure hooks run for observability/metrics before rethrowing
            if (is_callable(static::$afterExecute)) {
                (static::$afterExecute)($ctx);
            }
            if (is_callable(static::$afterFailure)) {
                (static::$afterFailure)($ctx);
            }

            // Upstream orchestration should convert this into a contextual failure.
            throw $e;
        }

        // Post-perform hooks when no exception occurred
        if (is_callable(static::$afterExecute)) {
            (static::$afterExecute)($ctx);
        }

        $isSuccess = $ctx->errors()->isEmpty() && (! $ctx->aborted());

        if ($isSuccess) {
            if (is_callable(static::$afterSuccess)) {
                (static::$afterSuccess)($ctx);
            }
        } else {
            if (is_callable(static::$afterFailure)) {
                (static::$afterFailure)($ctx);
            }
        }

        return $ctx;
    }

    /**
     * Register a global 'before execute' hook for this Action class.
     *
     * @param  callable(Context):void  $fn
     */
    public static function setBeforeExecute(callable $fn): void
    {
        static::$beforeExecute = $fn;
    }

    /**
     * Register a global 'after execute' hook for this Action class.
     *
     * @param  callable(Context):void  $fn
     */
    public static function setAfterExecute(callable $fn): void
    {
        static::$afterExecute = $fn;
    }

    /**
     * Register a global 'after success' hook for this Action class.
     *
     * @param  callable(Context):void  $fn
     */
    public static function setAfterSuccess(callable $fn): void
    {
        static::$afterSuccess = $fn;
    }

    /**
     * Register a global 'after failure' hook for this Action class.
     *
     * @param  callable(Context):void  $fn
     */
    public static function setAfterFailure(callable $fn): void
    {
        static::$afterFailure = $fn;
    }

    /**
     * Retrieve the currently registered 'before execute' hook (if any).
     *
     * @return null|callable(Context):void
     */
    public static function beforeExecuteBlock(): ?callable
    {
        return static::$beforeExecute;
    }

    /**
     * Retrieve the currently registered 'after execute' hook (if any).
     *
     * @return null|callable(Context):void
     */
    public static function afterExecuteBlock(): ?callable
    {
        return static::$afterExecute;
    }

    /**
     * Retrieve the currently registered 'after success' hook (if any).
     *
     * @return null|callable(Context):void
     */
    public static function afterSuccessBlock(): ?callable
    {
        return static::$afterSuccess;
    }

    /**
     * Retrieve the currently registered 'after failure' hook (if any).
     *
     * @return null|callable(Context):void
     */
    public static function afterFailureBlock(): ?callable
    {
        return static::$afterFailure;
    }

    /**
     * Implement the unit of work. Implementations should:
     *  - Mutate the Context (e.g., resources, params, errors) as needed.
     *  - Mark completion/abortion on the Context as appropriate.
     *  - Throw only for unexpected/exceptional conditions; expected failures should be
     *    recorded on the Context (errors/abort), allowing execute() to invoke afterFailure()
     *    without raising.
     */
    abstract protected function perform(Context $context): void;
}
