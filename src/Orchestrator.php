<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Utils\LangUtils;

/**
 * Orchestrator — two-phase pipeline
 *
 * Phase 1 (Organizer phase): run lightweight “organizer steps” for pre-work (validation,
 * shaping inputs, lookups). Each step can be:
 *  - callable(array<string,mixed>): Context|array<string,mixed>|null
 *  - class-string<Organizer> (delegates to Organizer::call)
 *
 * Phase 2 (Main pipeline): run the regular Organizer::steps() via reduce().
 *
 * Error handling
 *  - Orchestrator::call() wraps execution with Organizer::withErrorHandler().
 *  - Steps should prefer contextual failures (populate ctx->withErrors()/abort()) over throwing.
 *  - If Actions throw, they’ll be rethrown by Action::execute(); upstream organizers are expected
 *    to convert exceptions into contextual failures where appropriate.
 */
abstract class Orchestrator extends Organizer
{
    /**
     * Declare pre-pipeline “organizer steps”.
     *
     * Each entry is either:
     *  - callable(array<string,mixed>): Context|array<string,mixed>|null
     *  - class-string<Organizer>
     *
     * @return array<int, (callable(array<string,mixed>): (Context|array<string,mixed>|null))|class-string<Organizer>>
     */
    protected static function organizerSteps(): array
    {
        return [];
    }

    /**
     * Execute the two-phase orchestrator.
     *
     * Flow:
     *  1) Build Context (makeWithDefaults) and set current organizer.
     *  2) withErrorHandler($ctx, fn):
     *      a) processEachOrganizer($ctx, $eachOrganizerResult)
     *      b) If input['orchestrator_action_proc'] is callable, invoke it with $ctx.
     *      c) reduce($ctx, allSteps()) to run main pipeline.
     *  3) If ctx->success(), markComplete().
     *
     * @param  array<string,mixed>  $input  Initial inputs for the orchestrator Context.
     * @param  array<string,mixed>  $overrides  Optional context collections to seed (whitelisted).
     * @param  null|callable(Context $organizerCtx, Context $orchestratorCtx):void  $eachOrganizerResult
     *                                                                                                    Callback invoked after each organizer step completes, receiving the step’s Context
     *                                                                                                    and the root orchestrator Context (for inspection/merging/side-effects).
     */
    public static function call(array $input = [], array $overrides = [], ?callable $eachOrganizerResult = null): Context
    {
        $ctx = Context::makeWithDefaults($input, $overrides)
            ->setCurrentOrganizer(static::class);

        self::withErrorHandler($ctx, function (Context $ctx) use ($eachOrganizerResult): void {
            // run organizer phase
            $ctx = static::processEachOrganizer($ctx, $eachOrganizerResult);

            // 🔧 Allow input-provided proc to mutate the orchestrator context
            $proc = $ctx->input()->get('orchestrator_action_proc');
            if (is_callable($proc)) {
                $proc($ctx);
            }

            // then run main steps
            static::reduce($ctx, static::allSteps());
        });

        if ($ctx->success()) {
            $ctx->markComplete();
        }

        return $ctx;
    }

    /**
     * Run all organizer-phase steps before the main orchestrator steps.
     *
     * For each configured step:
     *  - If step is callable(array): invoke and coerce result to a Context.
     *  - If step is class-string<Organizer>: delegate to ::call().
     *  - If step is class-string<Action>: execute via runActionExecute().
     * Optionally invokes $eachOrganizerResult with the step Context and the root Context.
     *
     * @param  null|callable(Context $organizerCtx, Context $orchestratorCtx):void  $eachOrganizerResult
     * @return Context The original orchestrator Context (mutated along the way).
     */
    protected static function processEachOrganizer(Context $orchestratorCtx, ?callable $eachOrganizerResult = null): Context
    {
        foreach (static::organizerSteps() as $step) {
            $exec = static::execMethodFor($step);

            if ($exec === 'call') {
                $currentOrganizerCtx = static::runOrganizerCallable($step, $orchestratorCtx->inputArray());
            } else { // 'execute'
                if (! \is_string($step)) {
                    throw new \RuntimeException('Execute path requires an Action class-string.');
                }
                /** @var class-string<Action> $step */
                // @codeCoverageIgnoreStart
                $currentOrganizerCtx = static::runActionExecute($step, $orchestratorCtx->inputArray());
                // @codeCoverageIgnoreEnd
            }

            if ($eachOrganizerResult !== null) {
                $eachOrganizerResult($currentOrganizerCtx, $orchestratorCtx);
            }
        }

        return $orchestratorCtx;
    }

    protected static function execMethodFor(callable|string $step): string
    {
        if (\is_callable($step)) {
            return 'call';
        }

        if (\is_string($step)) {
            if (\class_exists($step)) {
                if (LangUtils::matchesClass($step, Organizer::class)) {
                    return 'call';
                }
                if (LangUtils::matchesClass($step, Action::class)) {
                    return 'execute';
                }
            }
        }

        throw new \RuntimeException(
            'Unsupported orchestrator step: must be callable, Organizer class-string, or Action class-string.'
        );
    }

    /**
     * Invoke a callable/Organizer step and coerce its result to a Context.
     *
     * Behavior:
     *  - class-string<Organizer>  → return Organizer::call($input).
     *  - callable(array): Context → return as-is.
     *  - callable(array): array  → wrap with Context::makeWithDefaults($array).
     *  - callable(array): null   → return a fresh Context seeded from $input.
     *
     * @param  (callable(array<string,mixed>): (Context|array<string,mixed>|null))|class-string<Organizer>  $step
     * @param  array<string,mixed>  $input
     */
    protected static function runOrganizerCallable(callable|string $step, array $input): Context
    {
        // If it's an Organizer class-string, delegate to ::call
        if (\is_string($step) && \class_exists($step) && LangUtils::matchesClass($step, Organizer::class)) {
            /** @var class-string<Organizer> $orgClass */
            $orgClass = $step;

            return $orgClass::call($input);
        }

        // Otherwise it must be a callable(array): Context|array|null
        if (! \is_callable($step)) {
            throw new \RuntimeException('Organizer step must be callable or an Organizer class-string.');
        }

        $result = $step($input);

        if ($result instanceof Context) {
            return $result;
        }

        if (\is_array($result)) {
            /** @var array<string,mixed> $asArray */
            $asArray = $result;

            return Context::makeWithDefaults($asArray)->setCurrentOrganizer(static::class);
        }

        return Context::makeWithDefaults($input)->setCurrentOrganizer(static::class);
    }

    /**
     * Execute an Action step in its own sub-Context and return that Context.
     *
     * @param  class-string<Action>  $actionClass
     * @param  array<string,mixed>  $input
     * @return Context The sub-context passed to Action::execute().
     *
     * @throws \RuntimeException If $actionClass is not an Action.
     * @throws \Throwable If the Action throws (per Action::execute semantics).
     */
    protected static function runActionExecute(string $actionClass, array $input): Context
    {
        if (! LangUtils::matchesClass($actionClass, Action::class)) {
            throw new \RuntimeException('Organizer step (execute) must be an Action class-string.');
        }

        $sub = Context::makeWithDefaults($input)->setCurrentOrganizer(static::class);

        /** @var class-string<Action> $actionClass */
        $actionClass::execute($sub);

        return $sub;
    }

    /**
     * steps() + terminal marker step.
     *
     * Appends a final step that sets meta['all_actions_complete']=true to aid diagnostics.
     *
     * @return array<int, callable(Context):void|string>
     */
    protected static function allSteps(): array
    {
        $list = static::steps();

        $list[] = static function (Context $ctx): void {
            $ctx->withMeta(['all_actions_complete' => true]);
        };

        return $list;
    }
}
