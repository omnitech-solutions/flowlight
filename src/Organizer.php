<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Traits\WithErrorHandler;

/**
 * Organizer — runs an ordered sequence of steps (Actions or callables) against a shared Context.
 *
 * Responsibilities
 *  - Construct a Context (with optional overrides), set organizer metadata, and execute steps in order.
 *  - Support both Action class-strings (invoked via Action::execute) and callable(Context): void steps.
 *  - Provide reduceIfSuccess() to conditionally continue only on success.
 *  - Capture failures mid-stream (records last failed snapshot) and stop further execution.
 *  - Mark the Context COMPLETE only when no errors/abortions are present.
 *
 * Error handling
 *  - call() wraps execution in WithErrorHandler::withErrorHandler(), allowing upstream conversion
 *    of thrown exceptions into contextual failures (ctx->withErrors()/abort()).
 *
 * Usage:
 *   final class MyFlow extends Organizer {
 *     protected static function steps(): array {
 *       return [
 *         DoThingAction::class,
 *         static function (Context $ctx): void { $ctx->withMeta(['ran' => true]); },
 *       ];
 *     }
 *   }
 *
 *   $ctx = MyFlow::call(['input_key' => 'value']);
 */
abstract class Organizer
{
    use WithErrorHandler;

    /**
     * Declare the ordered list of steps (Action class-strings or callable(Context): void).
     *
     * Non-abstract so tests can define anonymous organizers easily; ::call() and ::reduce()
     * handle empty lists and append a terminal marker step internally.
     *
     * @return array<int, callable(Context):void|string>
     */
    protected static function steps(): array
    {
        return [];
    }

    /**
     * Build a Context, optionally transform it, then run steps (plus terminal marker) and return the Context.
     *
     * Flow:
     *  1) Context::makeWithDefaults($input, $overrides) → setCurrentOrganizer(static::class)
     *  2) If $transformContext is callable, invoke it BEFORE any steps run.
     *  3) withErrorHandler($ctx, fn => reduce($ctx, allSteps()))
     *  4) If $ctx->success(), markComplete(); otherwise leave INCOMPLETE.
     *
     * @param  array<string,mixed>  $input  Initial inputs
     * @param  array<string,mixed>  $overrides  Whitelisted collection seeds (params, errors, resource, etc.)
     * @param  null|callable(Context):void  $transformContext  Pre-execution hook to mutate Context
     */
    public static function call(array $input = [], array $overrides = [], ?callable $transformContext = null): Context
    {
        $ctx = Context::makeWithDefaults($input, $overrides)->setCurrentOrganizer(static::class);

        if (is_callable($transformContext)) {
            $transformContext($ctx); // runs before steps (asserted in tests)
        }

        self::withErrorHandler($ctx, function (Context $ctx): void {
            static::reduce($ctx, self::allSteps());
        });

        // Do NOT mark complete on failure — tests assert this behavior
        if ($ctx->success()) {
            $ctx->markComplete();
        }

        return $ctx;
    }

    /**
     * Run the provided steps only if the Context is currently successful (no errors/aborted).
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
     * Execute steps in order. Each step is either:
     *  - class-string<Action>: sets current action and calls Action::execute($ctx)
     *  - callable(Context):void: sets current action label then invokes the callable
     *
     * Behavior:
     *  - After each step, if ctx has errors or failure() is true, record lastFailedContext and stop.
     *  - Otherwise record the step label via addSuccessfulAction().
     *
     * @param  array<int, callable(Context):void|string>  $steps
     *
     * @throws \RuntimeException If a step is neither an Action class-string nor a callable(Context): void
     */
    protected static function reduce(Context $ctx, array $steps): void
    {
        foreach ($steps as $step) {
            if (\is_string($step)) {
                /** @var class-string<Action> $step */
                $ctx->setCurrentAction($step);
                $label = $step;
                $step::execute($ctx);
            } elseif (\is_callable($step)) {
                $ctx->setCurrentAction(\is_object($step) ? $step::class : 'callable');
                $label = $ctx->actionName() ?? 'callable';
                $step($ctx);
            } else {
                // clear message that tests assert contains "Action class-string"
                throw new \RuntimeException('Step is neither an Action class-string nor a callable(Context): void');
            }

            if ($ctx->errors()->isNotEmpty() || $ctx->failure()) {
                $ctx->setLastFailedContext($ctx);
                break;
            }

            $ctx->addSuccessfulAction($label);
        }
    }

    /**
     * Combine declared steps() with a terminal marker step (sets meta['all_actions_complete']=true).
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
