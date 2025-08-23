<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Utils\LangUtils;
use Throwable;

/**
 * Orchestrator — two-phase pipeline (organizer steps → main steps).
 */
abstract class Orchestrator extends Organizer
{
    /**
     * Organizer-phase steps:
     *   - callable(array<string,mixed>): Context|array<string,mixed>|null
     *   - class-string<Organizer>
     *
     * @return array<int, (callable(array<string,mixed>): (Context|array<string,mixed>|null))|class-string<Organizer>>
     */
    protected static function organizerSteps(): array
    {
        return [];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $overrides
     * @param  null|callable(Context $organizerCtx, Context $orchestratorCtx):void  $eachOrganizerResult
     */
    public static function call(array $input = [], array $overrides = [], ?callable $eachOrganizerResult = null): Context
    {
        $ctx = Context::makeWithDefaults($input, $overrides)
            ->setCurrentOrganizer(static::class);

        try {
            // run organizer phase
            $ctx = static::processEachOrganizer($ctx, $eachOrganizerResult);

            // 🔧 NEW: allow input-provided proc to mutate the orchestrator context
            $proc = $ctx->input()->get('orchestrator_action_proc');
            if (is_callable($proc)) {
                $proc($ctx);
            }

            // then run main steps
            static::reduce($ctx, static::allSteps());
        } catch (Throwable $e) {
            static::withErrorHandler($ctx, $e);
        }

        if ($ctx->success()) {
            $ctx->markComplete();
        }

        return $ctx;
    }

    /**
     * Run all organizer steps before orchestrator steps.
     *
     * @param  null|callable(Context $organizerCtx, Context $orchestratorCtx):void  $eachOrganizerResult
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
                $currentOrganizerCtx = static::runActionExecute($step, $orchestratorCtx->inputArray());
            }

            if ($eachOrganizerResult !== null) {
                $eachOrganizerResult($currentOrganizerCtx, $orchestratorCtx);
            }
        }

        return $orchestratorCtx;
    }

    /**
     * Decide how to invoke a step.
     *
     * - callable or Organizer class-string → "call"
     * - otherwise (typically Action class-string) → "execute"
     */
    protected static function execMethodFor(callable|string $step): string
    {
        $isObjOrString = \is_object($step) || \is_string($step);

        if (\is_callable($step) || ($isObjOrString && \Flowlight\Utils\LangUtils::matchesClass($step, Organizer::class))) {
            return 'call';
        }

        if ($isObjOrString && \Flowlight\Utils\LangUtils::matchesClass($step, Action::class)) {
            return 'execute';
        }

        throw new \RuntimeException(
            'Unsupported orchestrator step: must be callable, Organizer class-string, or Action class-string.'
        );
    }

    /**
     * @param  (callable(array<string,mixed>): (Context|array<string,mixed>|null))|class-string<Organizer>  $step
     * @param  array<string,mixed>  $input
     */
    protected static function runOrganizerCallable(callable|string $step, array $input): Context
    {
        // If it's an Organizer class-string, delegate to ::call
        if (\is_string($step) && LangUtils::matchesClass($step, Organizer::class)) {
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
     * @param  class-string<Action>  $actionClass
     * @param  array<string,mixed>  $input
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
     * steps() + terminal step.
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
