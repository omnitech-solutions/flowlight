<?php

declare(strict_types=1);

namespace Flowlight;

use Throwable;

/**
 * Base Action with lifecycle hooks around a single unit of work.
 *
 * Lifecycle:
 *  - beforeExecute($ctx)
 *  - perform($ctx)                        // implemented by concrete action
 *  - afterExecute($ctx)
 *  - afterSuccess($ctx) | afterFailure($ctx)  // based on $ctx->errors()->isEmpty()
 *
 * Usage:
 *   final class TouchParamsAction extends Action
 *   {
 *       protected function perform(Context $ctx): void
 *       {
 *           $value = $ctx->input()->get('value');
 *           $ctx->withParams(['touched' => $value]);
 *       }
 *   }
 *
 *   $ctx = TouchParamsAction::execute(['value' => 123]);
 *
 * @phpstan-consistent-constructor
 */
abstract class Action
{
    /** @var null|callable(Context):void */
    protected static $beforeExecute = null;

    /** @var null|callable(Context):void */
    protected static $afterExecute = null;

    /** @var null|callable(Context):void */
    protected static $afterSuccess = null;

    /** @var null|callable(Context):void */
    protected static $afterFailure = null;

    protected Context $context;

    /**
     * Construct an Action with a Context (or array payload to initialize one).
     *
     * @param  Context|array<string,mixed>  $context
     */
    public function __construct(Context|array $context = [])
    {
        if ($context instanceof Context) {
            $this->context = $context;
        } else {
            /** @var array<string,mixed> $context */
            $this->context = Context::makeWithDefaults($context);
        }

        $this->context
            ->withInvokedAction($this)
            ->setCurrentAction(static::class);
    }

    /**
     * Execute this action with lifecycle hooks applied and return the (mutated) Context.
     *
     * @param  Context|array<string,mixed>  $context
     *
     * @throws Throwable
     */
    public static function execute(Context|array $context = []): Context
    {
        // safe with @phpstan-consistent-constructor
        $action = new static($context);
        $ctx = $action->context;

        if (is_callable(static::$beforeExecute)) {
            (static::$beforeExecute)($ctx);
        }

        try {
            $action->perform($ctx);
        } catch (Throwable $e) {
            if (is_callable(static::$afterExecute)) {
                (static::$afterExecute)($ctx);
            }
            if (is_callable(static::$afterFailure)) {
                (static::$afterFailure)($ctx);
            }
            throw $e;
        }

        if (is_callable(static::$afterExecute)) {
            (static::$afterExecute)($ctx);
        }

        $isSuccess = $ctx->errors()->isEmpty();

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
     * @param  callable(Context):void  $fn
     */
    public static function setBeforeExecute(callable $fn): void
    {
        static::$beforeExecute = $fn;
    }

    /**
     * @param  callable(Context):void  $fn
     */
    public static function setAfterExecute(callable $fn): void
    {
        static::$afterExecute = $fn;
    }

    /**
     * @param  callable(Context):void  $fn
     */
    public static function setAfterSuccess(callable $fn): void
    {
        static::$afterSuccess = $fn;
    }

    /**
     * @param  callable(Context):void  $fn
     */
    public static function setAfterFailure(callable $fn): void
    {
        static::$afterFailure = $fn;
    }

    /**
     * Getters for tests/introspection.
     *
     * @return null|callable(Context):void
     */
    public static function beforeExecuteBlock(): ?callable
    {
        return static::$beforeExecute;
    }

    /** @return null|callable(Context):void */
    public static function afterExecuteBlock(): ?callable
    {
        return static::$afterExecute;
    }

    /** @return null|callable(Context):void */
    public static function afterSuccessBlock(): ?callable
    {
        return static::$afterSuccess;
    }

    /** @return null|callable(Context):void */
    public static function afterFailureBlock(): ?callable
    {
        return static::$afterFailure;
    }

    /**
     * Implement your action’s business logic here.
     */
    abstract protected function perform(Context $context): void;
}
