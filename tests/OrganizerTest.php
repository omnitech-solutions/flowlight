<?php

declare(strict_types=1);

use Flowlight\Action;
use Flowlight\Context;
use Flowlight\Enums\ContextStatus;
use Flowlight\Organizer;

describe(Organizer::class, function () {
    describe('steps() default', function () {
        it('returns an empty array by default (coverage for the method body)', function () {
            $Org = new class extends Organizer {};

            $ref = new ReflectionClass($Org);
            $m = $ref->getMethod('steps');
            $m->setAccessible(true);

            /** @var array<int, callable(Context):void|string> $result */
            $result = $m->invoke(null);

            expect($result)->toBeArray()->and($result)->toBe([]);
        });
    });

    describe('::call', function () {
        it('runs class-string Action and callable steps successfully', function () {
            $Org = new class extends Organizer
            {
                protected static function steps(): array
                {
                    $A = new class extends Action
                    {
                        protected function perform(Context $ctx): void
                        {
                            $ctx->withParams(['touched' => $ctx->input()->get('seed')]);
                        }
                    };

                    return [
                        $A::class,
                        static function (Context $ctx): void {
                            $ctx->withMeta(['callable_ran' => true]);
                        },
                    ];
                }
            };

            $ctx = $Org::call(['seed' => 'value']);

            expect($ctx->paramsArray())->toBe(['touched' => 'value'])
                ->and($ctx->metaArray())->toHaveKey('callable_ran')
                ->and($ctx->errors()->isEmpty())->toBeTrue()
                ->and($ctx->success())->toBeTrue();
        });

        it('stops on first error and does not run subsequent steps', function () {
            $Org = new class extends Organizer
            {
                protected static function steps(): array
                {
                    return [
                        static function (Context $ctx): void {
                            $ctx->withErrors(['field' => ['bad']]);
                        },
                        static function (Context $ctx): void {
                            $ctx->withMeta(['should_not_run' => true]);
                        },
                    ];
                }
            };

            $ctx = $Org::call();

            expect($ctx->errorsArray())->toBe(['field' => ['bad']])
                ->and($ctx->metaArray())->not->toHaveKey('should_not_run')
                ->and($ctx->success())->toBeFalse();
        });

        it('catches exceptions from a step, routes through withErrorHandler, and does not mark complete', function () {
            $Org = new class extends Organizer
            {
                protected static function steps(): array
                {
                    return [
                        static function (Context $ctx): void {
                            throw new RuntimeException('boom');
                        },
                        static function (Context $ctx): void {
                            $ctx->withMeta(['unreachable' => true]);
                        },
                    ];
                }
            };

            $ctx = $Org::call();

            /** @var array<string, list<string>> $errors */
            $errors = $ctx->errorsArray();

            expect($ctx->errors()->isEmpty())->toBeFalse()
                ->and($errors)->toHaveKey('base')
                ->and($errors['base'])->toBeArray()
                ->and($errors['base'][0])->toContain('boom')
                ->and($ctx->metaArray())->not->toHaveKey('unreachable')
                ->and($ctx->success())->toBeFalse()
                ->and($ctx->status())->not->toBe(ContextStatus::COMPLETE);
        });
    });

    describe('reduceIfSuccess', function () {
        it('runs steps when context is successful and error-free', function () {
            $Ctx = Context::makeWithDefaults();
            $steps = [
                static function (Context $ctx): void {
                    $ctx->withMeta(['ran_reduce_if_success' => true]);
                },
            ];

            $Out = new class extends Organizer {};
            $Out::reduceIfSuccess($Ctx, $steps);

            expect($Ctx->metaArray())->toHaveKey('ran_reduce_if_success');
        });

        it('does not run steps when there are errors', function () {
            $Ctx = Context::makeWithDefaults()->withErrors(['field' => ['bad']]);
            $steps = [
                static function (Context $ctx): void {
                    $ctx->withMeta(['should_not_run' => true]);
                },
            ];

            $Out = new class extends Organizer {};
            $Out::reduceIfSuccess($Ctx, $steps);

            expect($Ctx->metaArray())->not->toHaveKey('should_not_run');
        });

        it('does not run steps when status is FAILED', function () {
            $Ctx = Context::makeWithDefaults()->markFailed();
            $steps = [
                static function (Context $ctx): void {
                    $ctx->withMeta(['should_not_run' => true]);
                },
            ];

            $Out = new class extends Organizer {};
            $Out::reduceIfSuccess($Ctx, $steps);

            expect($Ctx->metaArray())->not->toHaveKey('should_not_run');
        });
    });

    describe('reduce internals', function () {
        it('throws RuntimeException for invalid step (neither class-string Action nor callable)', function () {
            $Org = new class extends Organizer
            {
                /**
                 * @param  array<int, mixed>  $steps
                 */
                public static function reduceProxy(Context $ctx, array $steps): void
                {
                    /** @var array<int, callable(Context):void|string> $steps */
                    self::reduce($ctx, $steps);
                }

                protected static function steps(): array
                {
                    return [];
                }
            };

            $ctx = Context::makeWithDefaults();

            /** @var mixed $invalid */
            $invalid = 12345;

            expect(fn () => $Org::reduceProxy($ctx, [$invalid]))
                ->toThrow(RuntimeException::class, 'Step is neither an Action class-string nor a callable(Context): void');
        });

        it('sets current action appropriately for class-string and callable steps', function () {
            $Org = new class extends Organizer
            {
                protected static function steps(): array
                {
                    $A = new class extends Action
                    {
                        protected function perform(Context $ctx): void
                        {
                            $ctx->withMeta(['saw_action' => $ctx->actionName()]);
                        }
                    };

                    return [
                        $A::class,
                        static function (Context $ctx): void {
                            $ctx->withMeta(['saw_callable' => $ctx->actionName()]);
                        },
                    ];
                }
            };

            $ctx = $Org::call();
            $meta = $ctx->metaArray();

            expect($meta)->toHaveKeys(['saw_action', 'saw_callable'])
                ->and(is_string($meta['saw_action']))->toBeTrue()
                ->and(is_string($meta['saw_callable']))->toBeTrue()
                ->and($ctx->success())->toBeTrue();
        });
    });

    describe('withErrorHandler proxy', function () {
        it('records error info and adds base error', function () {
            $Proxy = new class extends Organizer
            {
                /**
                 * @param  array<string, mixed>  $input
                 */
                public static function callWithProxy(array $input = []): Context
                {
                    $ctx = Context::makeWithDefaults($input);
                    self::withErrorHandler($ctx, new RuntimeException('xyz'));

                    return $ctx;
                }
            };

            $ctx = $Proxy::callWithProxy();

            /** @var array<string, list<string>> $errors */
            $errors = $ctx->errorsArray();
            $info = $ctx->internalOnly()->get('errorInfo');

            expect($ctx->errors()->isEmpty())->toBeFalse()
                ->and($errors)->toHaveKey('base')
                ->and($errors['base'])->toBeArray()
                ->and($errors['base'][0])->toContain('xyz')
                ->and($info)->toBeArray();
        });
    });
});
