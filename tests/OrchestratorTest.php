<?php

declare(strict_types=1);

namespace Tests;

use Flowlight\Action;
use Flowlight\Context;
use Flowlight\Orchestrator;
use Flowlight\Organizer;
use Flowlight\Utils\LangUtils;
use RuntimeException;

$makeHarness = static function () {
    return new class extends \Flowlight\Orchestrator
    {
        /**
         * Organizer step list used by tests.
         *
         * @var array<int,
         *     (callable(array<string,mixed>): (\Flowlight\Context|array<string,mixed>|null))
         *     |class-string<\Flowlight\Organizer>
         *     |class-string<\Flowlight\Action>
         * >
         */
        private static array $orgSteps = [];

        /** @var array<int, callable(\Flowlight\Context): void|string> */
        private static array $steps = [];

        /** @var class-string<\Flowlight\Action>|null */
        public static ?string $capturedActionClass = null;

        /**
         * @param  array<int,
         *     (callable(array<string,mixed>): (\Flowlight\Context|array<string,mixed>|null))
         *     |class-string<\Flowlight\Organizer>
         *     |class-string<\Flowlight\Action>
         * >  $list
         */
        public static function _setOrganizerSteps(array $list): void
        {
            self::$orgSteps = $list;
        }

        /** @param array<int, callable(\Flowlight\Context): void|string> $list */
        public static function _setSteps(array $list): void
        {
            self::$steps = $list;
        }

        /**
         * @return array<int,
         *     (callable(array<string,mixed>): (\Flowlight\Context|array<string,mixed>|null))
         *     |class-string<\Flowlight\Organizer>
         *     |class-string<\Flowlight\Action>
         * >
         */
        protected static function organizerSteps(): array
        {
            return self::$orgSteps;
        }

        /** @return array<int, callable(\Flowlight\Context): void|string> */
        protected static function steps(): array
        {
            return self::$steps;
        }

        public static function _execMethodFor(callable|string $step): string
        {
            return parent::execMethodFor($step);
        }

        /**
         * @param  (callable(array<string,mixed>): (\Flowlight\Context|array<string,mixed>|null))|class-string<\Flowlight\Organizer>  $step
         * @param  array<string,mixed>  $input
         */
        public static function _runOrganizerCallable(callable|string $step, array $input): \Flowlight\Context
        {
            return parent::runOrganizerCallable($step, $input);
        }

        /**
         * Unsafe variant for negative tests.
         *
         * @param  array<string,mixed>  $input
         */
        public static function _runOrganizerCallableUnsafe(mixed $step, array $input): \Flowlight\Context
        {
            /** @phpstan-ignore-next-line */
            return parent::runOrganizerCallable($step, $input);
        }

        /**
         * @param  class-string<\Flowlight\Action>  $action
         * @param  array<string,mixed>  $input
         */
        public static function _runActionExecute(string $action, array $input): \Flowlight\Context
        {
            return parent::runActionExecute($action, $input);
        }

        /**
         * Unsafe variant for negative tests.
         *
         * @param  array<string,mixed>  $input
         */
        public static function _runActionExecuteUnsafe(string $action, array $input): \Flowlight\Context
        {
            /** @phpstan-ignore-next-line */
            return parent::runActionExecute($action, $input);
        }

        /**
         * Testable proxy for Orchestrator::call.
         *
         * @param  array<string,mixed>  $input
         * @param  array<string,mixed>  $overrides
         * @param  null|callable(\Flowlight\Context,\Flowlight\Context): void  $each
         */
        public static function _call(array $input = [], array $overrides = [], ?callable $each = null): \Flowlight\Context
        {
            return parent::call($input, $overrides, $each);
        }
    };
};

describe('organizerSteps()', function () {
    it('returns an empty array by default (coverage for method body)', function () {
        $klass = new class extends Orchestrator
        {
            /** @return array<int, (callable(array<string,mixed>): (Context|array<string,mixed>|null))|class-string<Organizer>> */
            public static function _organizerSteps(): array
            {
                /** @var array<int, (callable(array<string,mixed>): (Context|array<string,mixed>|null))|class-string<Organizer>> */
                return parent::organizerSteps();
            }

            /** @return array<int, callable(Context): void|string> */
            protected static function steps(): array
            {
                return [];
            }
        };

        expect($klass::_organizerSteps())->toBe([]);
    });
});

describe('::call()', function () use ($makeHarness) {
    it('uses withErrorHandler when a step throws', function () use ($makeHarness) {
        $H = $makeHarness();

        $throwingStep = static function (Context $ctx): void {
            throw new RuntimeException('boom');
        };
        $H::_setOrganizerSteps([]);
        $H::_setSteps([$throwingStep]);

        $ctx = $H::_call(['a' => 1]);

        expect($ctx->errorsArray())->toHaveKey('base');
        $errors = $ctx->errorsArray();
        $baseList = isset($errors['base']) && is_array($errors['base']) ? $errors['base'] : [];
        $firstMsg = ($baseList[0] ?? '');
        expect($firstMsg)->toBe('boom');

        $info = $ctx->internalOnly()->get('errorInfo');
        expect($info)->toBeArray()->toHaveKeys(['type', 'message', 'exception', 'backtrace']);
    });

    it('reports a clear error when execute-path receives a non-string step', function () {
        $klass = new class extends Orchestrator
        {
            protected static function organizerSteps(): array
            {
                return [
                    static fn (array $in) => null,
                ];
            }

            /** @return array<int, callable(Context): void|string> */
            protected static function steps(): array
            {
                return [];
            }

            protected static function execMethodFor(callable|string $step): string
            {
                return 'execute';
            }
        };

        $ctx = $klass::call(['any' => 'thing']);

        expect($ctx->errorsArray())->toHaveKey('base');
        $errors = $ctx->errorsArray();
        $baseList = isset($errors['base']) && is_array($errors['base']) ? $errors['base'] : [];
        $msg = ($baseList[0] ?? '');
        expect($msg)->toContain('Execute path requires an Action class-string.');
    });

    it('passes orchestrator input to runActionExecute and yields the sub-context', function () use ($makeHarness) {
        $H = $makeHarness();

        // Action executed during main steps (execute-path)
        $Act = new class extends \Flowlight\Action
        {
            protected function perform(\Flowlight\Context $ctx): void
            {
                // Echo back whatever input this sub-context received so we can assert it
                $ctx->withParams(['observed_input' => $ctx->inputArray()]);
            }
        };
        /** @var class-string<\Flowlight\Action> $actClass */
        $actClass = $Act::class;

        // Organizer phase has no steps; main steps include an Action class-string → execute-path
        $H::_setOrganizerSteps([]);
        $H::_setSteps([$actClass]);

        $input = ['alpha' => 1];

        $ctx = $H::_call($input);

        expect($ctx->inputArray())->toBe($input)
            ->and($ctx->paramsArray())->toBe(['observed_input' => $input]); // runActionExecute received orchestrator input
    });
});

describe('execMethodFor()', function () use ($makeHarness) {
    it('returns "call" for a callable', function () use ($makeHarness) {
        $H = $makeHarness();
        $step = static fn (array $in) => $in;
        expect($H::_execMethodFor($step))->toBe('call');
    });

    it('returns "call" for an Organizer class-string', function () use ($makeHarness) {
        $H = $makeHarness();
        $Org = new class extends Organizer
        {
            /** @return array<int, callable(Context): void|string> */
            protected static function steps(): array
            {
                return [];
            }
        };
        /** @var class-string<Organizer> $orgClass */
        $orgClass = $Org::class;

        expect(LangUtils::matchesClass($orgClass, Organizer::class))->toBeTrue()
            ->and($H::_execMethodFor($orgClass))->toBe('call');
    });

    it('returns "execute" for an Action class-string', function () use ($makeHarness) {
        $H = $makeHarness();
        $Act = new class extends Action
        {
            protected function perform(Context $ctx): void {}
        };
        /** @var class-string<Action> $actClass */
        $actClass = $Act::class;

        expect(LangUtils::matchesClass($actClass, Action::class))->toBeTrue()
            ->and($H::_execMethodFor($actClass))->toBe('execute');
    });

    it('throws for unsupported strings', function () use ($makeHarness) {
        $H = $makeHarness();
        expect(fn () => $H::_execMethodFor('NotAClass'))
            ->toThrow(RuntimeException::class);
    });
});

describe('runOrganizerCallable()', function () use ($makeHarness) {
    it('delegates to Organizer::call for Organizer class-string', function () use ($makeHarness) {
        $H = $makeHarness();

        $Org = new class extends Organizer
        {
            /** @return array<int, callable(Context): void|string> */
            protected static function steps(): array
            {
                return [
                    static function (Context $ctx): void {
                        $ctx->withParams(['via' => 'organizer']);
                    },
                ];
            }
        };
        /** @var class-string<Organizer> $orgClass */
        $orgClass = $Org::class;

        $sub = $H::_runOrganizerCallable($orgClass, ['in' => 1]);
        expect($sub)->toBeInstanceOf(Context::class)
            ->and($sub->paramsArray())->toHaveKey('via')
            ->and($sub->paramsArray()['via'])->toBe('organizer');
    });

    it('accepts callable returning Context', function () use ($makeHarness) {
        $H = $makeHarness();

        $callable = static function (array $input): Context {
            /** @var array<string,mixed> $input */
            return Context::makeWithDefaults($input)->withParams(['k' => 'v']);
        };

        $sub = $H::_runOrganizerCallable($callable, ['z' => 9]);
        expect($sub)->toBeInstanceOf(Context::class)
            ->and($sub->paramsArray())->toBe(['k' => 'v']);
    });

    it('accepts callable returning array and normalizes to Context', function () use ($makeHarness) {
        $H = $makeHarness();

        $callable = static function (array $input): array {
            return ['params' => ['arr' => 'ok']];
        };

        $sub = $H::_runOrganizerCallable($callable, ['x' => 1]);
        expect($sub)->toBeInstanceOf(Context::class)
            ->and($sub->inputArray())->toBe(['params' => ['arr' => 'ok']]);
    });

    it('accepts callable returning null and falls back to input Context', function () use ($makeHarness) {
        $H = $makeHarness();

        $callable = static function (array $input): null {
            return null;
        };

        $sub = $H::_runOrganizerCallable($callable, ['alpha' => 1]);
        expect($sub)->toBeInstanceOf(Context::class)
            ->and($sub->inputArray())->toBe(['alpha' => 1]);
    });

    it('throws if not callable and not Organizer class-string', function () use ($makeHarness) {
        $H = $makeHarness();
        expect(fn () => $H::_runOrganizerCallableUnsafe('NotAClass', []))
            ->toThrow(RuntimeException::class, 'Organizer step must be callable or an Organizer class-string.');
    });
});

describe('runActionExecute()', function () use ($makeHarness) {
    it('executes an Action class-string and returns the sub-context', function () use ($makeHarness) {
        $H = $makeHarness();

        $Act = new class extends Action
        {
            protected function perform(Context $ctx): void
            {
                $ctx->withParams(['ran' => 'yes']);
            }
        };
        /** @var class-string<Action> $actClass */
        $actClass = $Act::class;

        $sub = $H::_runActionExecute($actClass, ['foo' => 'bar']);
        expect($sub)->toBeInstanceOf(Context::class)
            ->and($sub->paramsArray())->toBe(['ran' => 'yes']);
    });

    it('throws if provided class-string is not an Action', function () use ($makeHarness) {
        $H = $makeHarness();
        expect(fn () => $H::_runActionExecuteUnsafe(\stdClass::class, []))
            ->toThrow(\RuntimeException::class, 'Organizer step (execute) must be an Action class-string.');
    });
});

describe('integration', function () {
    $makeOrchestrator = static function (): string {
        $klass = new class extends Orchestrator
        {
            /** @return array<int, (callable(array<string,mixed>): (Context|array<string,mixed>|null))|class-string<Organizer>> */
            protected static function organizerSteps(): array
            {
                return [
                    static function (array $input) {
                        $sub = Context::makeWithDefaults(['params' => []]);
                        /** @var null|callable(Context):void $fn */
                        $fn = $input['organizer_action_proc'] ?? null;
                        if (\is_callable($fn)) {
                            $fn($sub);
                        }

                        return $sub;
                    },
                ];
            }

            /** @return array<int, callable(Context): void|string> */
            protected static function steps(): array
            {
                return [
                    static function (Context $ctx): void {
                        /** @var callable(Context):void $fn */
                        $fn = $ctx->input()->get('orchestrator_action_proc');
                        $fn($ctx);
                    },
                ];
            }
        };

        /** @var class-string<Orchestrator> */
        return $klass::class;
    };

    it('adds orchestrator params without organizer params', function () use ($makeOrchestrator) {
        $klass = $makeOrchestrator();

        /** @var array<string,mixed> $input */
        $input = [
            'organizer_action_proc' => static function (Context $organizerCtx): void {},
            'orchestrator_action_proc' => static function (Context $ctx): void {
                $ctx->withParams(['x2' => 'x2']);
            },
        ];

        $ctx = $klass::call($input);

        expect($ctx->inputArray())->toBe($input)
            ->and($ctx->paramsArray())->toBe(['x2' => 'x2']);
    });

    it('adds organizer param to orchestrator when each-organizer callback is provided', function () use ($makeOrchestrator) {
        $klass = $makeOrchestrator();

        /** @var array<string,mixed> $input */
        $input = [
            'organizer_action_proc' => static function (Context $organizerCtx): void {
                $organizerCtx->withParams(['x1' => 'x1']);
            },
            'orchestrator_action_proc' => static function (Context $ctx): void {
                $ctx->withParams(['x2' => 'x2']);
            },
        ];

        $each = static function (Context $organizerCtx, Context $orchestratorCtx): void {
            $x1 = $organizerCtx->paramsArray()['x1'] ?? null;
            if ($x1 !== null) {
                $orchestratorCtx->withParams(['x3' => $x1]);
            }
        };

        $ctx = $klass::call($input, [], $each);

        expect($ctx->inputArray())->toBe($input)
            ->and($ctx->paramsArray())->toMatchArray(['x2' => 'x2', 'x3' => 'x1']);
    });
});
