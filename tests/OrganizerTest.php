<?php

declare(strict_types=1);

use Flowlight\Action;
use Flowlight\Context;
use Flowlight\Enums\ContextOperation;
use Flowlight\Enums\ContextStatus;
use Flowlight\Organizer;

describe('::call transformContext', function () {
    it('applies transformContext BEFORE steps run', function () {
        $Org = new class extends Organizer
        {
            protected static function steps(): array
            {
                // Step observes changes made by transformContext
                return [
                    static function (Context $ctx): void {
                        // Was operation set by transform?
                        $ctx->withMeta([
                            'saw_create_operation' => $ctx->createOperation(),
                            'seed_seen_in_step' => $ctx->input()->get('seed'),
                            'meta_from_transform' => $ctx->meta()->get('transform_flag'),
                        ]);

                        // Also write a param so we can see normal step execution
                        $ctx->withParams(['step_ran' => true]);
                    },
                ];
            }
        };

        $ctx = $Org::call(
            input: ['seed' => 'abc'],
            overrides: [],
            transformContext: static function (Context $c): void {
                // Mutate BEFORE any step
                $c->markCreateOperation();
                $c->withMeta(['transform_flag' => 'on']);
            }
        );

        expect($ctx->success())->toBeTrue()
            ->and($ctx->status())->toBe(ContextStatus::COMPLETE)
            ->and($ctx->metaArray())->toMatchArray([
                'saw_create_operation' => true,
                'seed_seen_in_step' => 'abc',
                'meta_from_transform' => 'on',
            ])
            ->and($ctx->paramsArray())->toMatchArray(['step_ran' => true]);
    });

    it('is a NO-OP when transformContext is null', function () {
        $Org = new class extends Organizer
        {
            protected static function steps(): array
            {
                return [
                    static function (Context $ctx): void {
                        $ctx->withParams(['ran' => true]);
                        // No transform flag should exist
                        $ctx->withMeta(['had_transform_flag' => $ctx->meta()->has('transform_flag')]);
                    },
                ];
            }
        };

        $ctx = $Org::call(['seed' => 'value']);

        expect($ctx->success())->toBeTrue()
            ->and($ctx->paramsArray())->toMatchArray(['ran' => true])
            ->and($ctx->meta()->get('had_transform_flag'))->toBeFalse();
    });

    it('works when a subclass OVERRIDES call and delegates to parent::call', function () {
        $Org = new class extends Organizer
        {
            public static function call(
                array $input = [],
                array $overrides = [],
                ?callable $transformContext = null
            ): Context {
                // custom wrapper could add logs, metrics, etc…
                return parent::call($input, $overrides, $transformContext);
            }

            protected static function steps(): array
            {
                $A = new class extends Action
                {
                    protected function perform(Context $ctx): void
                    {
                        $ctx->withParams(['wrapped' => $ctx->meta()->get('hook') === 'ok']);
                    }
                };

                return [$A::class];
            }
        };

        $ctx = $Org::call(
            ['irrelevant' => 1],
            [],
            static function (Context $c): void {
                $c->withMeta(['hook' => 'ok']);
            }
        );

        expect($ctx->success())->toBeTrue()
            ->and($ctx->paramsArray())->toMatchArray(['wrapped' => true])
            ->and($ctx->meta()->get('hook'))->toBe('ok');
    });
});

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

describe('Organizer::callForCreateOperation', function () {
    it('marks context CREATE before steps and completes on success', function () {
        // Capture what operation steps observe
        $observed = [];

        // Anonymous organizer with simple steps
        $Org = new class extends Organizer
        {
            /** @phpstan-ignore-next-line */
            public static array $observed;

            protected static function steps(): array
            {
                return [
                    // step 1: record the operation seen by steps
                    static function (Context $ctx): void {
                        /** @var string $op */
                        $op = $ctx->operation();
                        self::$observed[] = $op;
                    },
                ];
            }
        };
        /** @var class-string<Organizer> $cls */
        $cls = $Org::class;
        $cls::$observed = &$observed;

        /** @var Context $out */
        $out = $cls::callForCreateOperation(['foo' => 'bar']);

        // Steps saw CREATE
        expect($observed)->toBe([ContextOperation::CREATE->value]);

        // Context is still CREATE afterward and pipeline completed
        expect($out->operation())->toBe(ContextOperation::CREATE->value)
            ->and($out->success())->toBeTrue()
            ->and($out->metaArray())->toHaveKey('all_actions_complete', true);
    });

    it('applies user transform after markCreateOperation (transform can override)', function () {
        $observed = [];

        $Org = new class extends Organizer
        {
            /** @phpstan-ignore-next-line */
            public static array $observed;

            protected static function steps(): array
            {
                return [
                    static function (Context $ctx): void {
                        /** @var string $op */
                        $op = $ctx->operation();
                        self::$observed[] = $op;
                    },
                ];
            }
        };
        /** @var class-string<Organizer> $cls */
        $cls = $Org::class;
        $cls::$observed = &$observed;

        // Transform flips it back to UPDATE; since transform runs AFTER markCreateOperation,
        // steps should observe UPDATE.
        $transform = static function (Context $ctx): void {
            $ctx->markUpdateOperation();
        };

        /** @var Context $out */
        $out = $cls::callForCreateOperation(['x' => 1], [], $transform);

        expect($observed)->toBe([ContextOperation::UPDATE->value])
            ->and($out->operation())->toBe(ContextOperation::UPDATE->value)
            ->and($out->success())->toBeTrue()
            ->and($out->metaArray())->toHaveKey('all_actions_complete', true);
    });

    it('passes overrides through unchanged', function () {
        /** @var list<array<string,mixed>> $observed */
        $observed = [];

        $Org = new class extends Organizer
        {
            /** @var list<array<string,mixed>> */
            public static array $observed = [];

            protected static function steps(): array
            {
                return [
                    static function (Context $ctx): void {
                        // record both operation and a value from overrides
                        self::$observed[] = [
                            'op' => $ctx->operation(),
                            'meta.flag' => data_get($ctx->metaArray(), 'flag'),
                        ];
                    },
                ];
            }
        };

        /** @var class-string<Organizer> $cls */
        $cls = $Org::class;

        // keep a reference so we can read what the anon Organizer recorded
        $cls::$observed = &$observed;

        /** @var array<string,mixed> $overrides */
        $overrides = ['meta' => ['flag' => true]];

        $cls::callForCreateOperation([], $overrides);

        // runtime assertion + static hint for PHPStan before offset access
        expect($observed)->not->toBeEmpty();
        /** @var non-empty-list<array<string,mixed>> $observed */
        $first = $observed[0];
        expect($first['op'] ?? null)->toBe('CREATE')
            ->and($first['meta.flag'] ?? null)->toBeTrue();
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
        $Ctx = Context::makeWithDefaults()->abort();
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
