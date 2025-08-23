<?php

declare(strict_types=1);

namespace Flowlight;

use Throwable;

/**
 * Organizer — run a sequence of steps (Actions or callables) with a shared Context.
 *
 * Usage:
 *   final class MyFlow extends Organizer
 *   {
 *       protected static function steps(): array
 *       {
 *           return [
 *               DoThingAction::class,
 *               fn (Context $ctx) => $ctx->withMeta(['ran' => true]),
 *           ];
 *       }
 *   }
 *
 *   $ctx = MyFlow::call(['input_key' => 'value']);
 */
abstract class Organizer
{
    /**
     * Subclasses should override with a list of steps (class-strings of Actions or callables).
     * Kept non-abstract so anonymous organizers can be defined in tests; ::call() will enforce non-empty.
     *
     * @return array<int, callable(Context):void|string>
     */
    protected static function steps(): array
    {
        return [];
    }

    /**
     * Build a Context, set organizer metadata, run steps (plus terminal step), and return the resulting Context.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $overrides
     */
    public static function call(array $input = [], array $overrides = []): Context
    {
        $ctx = Context::makeWithDefaults($input, $overrides)->setCurrentOrganizer(static::class);

        try {
            static::reduce($ctx, self::allSteps());
        } catch (Throwable $e) {
            static::withErrorHandler($ctx, $e);
        }

        if ($ctx->success()) {
            $ctx->markComplete();
        }

        return $ctx;
    }

    /**
     * Run steps only if the context is currently successful and has no errors.
     *
     * @param  array<int, callable(Context):void|string>  $steps
     */
    public static function reduceIfSuccess(Context $ctx, array $steps): Context
    {
        if ($ctx->success() && $ctx->errors()->isEmpty()) {
            static::reduce($ctx, $steps);
        }

        return $ctx;
    }

    /**
     * Execute steps in order; a step can be an Action class-string (exposes ::execute) or a callable(Context): void.
     *
     * @param  array<int, callable(Context):void|string>  $steps
     */
    protected static function reduce(Context $ctx, array $steps): void
    {
        foreach ($steps as $step) {
            if (\is_string($step)) {
                $ctx->setCurrentAction($step);
                /** @var class-string<Action> $step */
                $step::execute($ctx);
            } elseif (\is_callable($step)) {
                $ctx->setCurrentAction(\is_object($step) ? $step::class : 'callable');
                $step($ctx);
            } else {
                throw new \RuntimeException('Step is neither an Action class-string nor a callable(Context): void');
            }

            if ($ctx->errors()->isNotEmpty() || $ctx->failure()) {
                $ctx->setLastFailedContext($ctx);
                break;
            }

            $label = $ctx->actionName() ?? 'callable';
            $ctx->addSuccessfulAction($label);
        }
    }

    protected static function withErrorHandler(Context $ctx, Throwable $e): void
    {
        $ctx->recordRaisedError($e);
        $ctx->withErrors(['base' => [$e->getMessage()]]);
        $ctx->setLastFailedContext($ctx);
    }

    /**
     * The complete list of steps to run: declared steps plus a terminal “AllActionsComplete” step.
     *
     * @return array<int, callable(Context):void|string>
     */
    private static function allSteps(): array
    {
        $list = static::steps();

        $list[] = static function (Context $ctx): void {
            $ctx->withMeta(['all_actions_complete' => true]);
        };

        return $list;
    }
}
