<?php

declare(strict_types=1);

use Flowlight\Context;
use Flowlight\Enums\ContextStatus;
use Flowlight\Exceptions\ContextFailedError;

describe(Context::class, function () {
    describe('::makeWithDefaults', function () {
        it('creates context with defaults and overrides', function () {
            $ctx = Context::makeWithDefaults(['in' => 1], [
                'params' => ['p' => 1],
                'meta' => ['m' => true],
            ]);

            expect($ctx->inputArray())->toBe(['in' => 1])
                ->and($ctx->paramsArray())->toBe(['p' => 1])
                ->and($ctx->metaArray())->toBe(['m' => true]);
        });

        it('ignores unknown keys without side effects', function () {
            $ctx = Context::makeWithDefaults([], ['unknown' => 'x']);

            expect($ctx->paramsArray())->toBe([])
                ->and($ctx->errorsArray())->toBe([])
                ->and($ctx->resourceArray())->toBe([])
                ->and($ctx->metaArray())->toBe([])
                ->and($ctx->extraRulesArray())->toBe([])
                ->and($ctx->internalOnlyArray())->toBe([]);
        });

        it('accepts overrides for extraRules and internalOnly', function () {
            $ctx = Context::makeWithDefaults([], [
                'extraRules' => ['min' => 2],
                'internalOnly' => ['trace' => true],
            ]);

            expect($ctx->extraRulesArray())->toBe(['min' => 2])
                ->and($ctx->internalOnlyArray())->toBe(['trace' => true]);
        });

        it('can set invokedAction via overrides when object provided', function () {
            $action = new stdClass;
            $ctx = Context::makeWithDefaults([], ['invokedAction' => $action]);
            expect($ctx->invokedAction)->toBe($action);
        });
    });

    describe('withInputs', function () {
        it('merges input values', function () {
            $ctx = Context::makeWithDefaults();
            $ctx->withInputs(['a' => 1])->withInputs(['b' => 2]);

            expect($ctx->inputArray())->toBe(['a' => 1, 'b' => 2]);
        });
    });

    describe('withParams', function () {
        it('merges params', function () {
            $ctx = Context::makeWithDefaults()
                ->withParams(['p' => 1])
                ->withParams(['q' => 2]);

            expect($ctx->paramsArray())->toBe(['p' => 1, 'q' => 2]);
        });
    });

    describe('withErrors', function () {
        it('merges and deduplicates errors per field while keeping order', function () {
            $ctx = Context::makeWithDefaults();
            $ctx->withErrors(['email' => ['invalid']]);
            $ctx->withErrors(['email' => ['invalid', 'too short']]);

            expect($ctx->errorsArray())->toBe([
                'email' => ['invalid', 'too short'],
            ]);
        });

        it('accepts scalars and normalizes to list of strings', function () {
            $ctx = Context::makeWithDefaults();

            $ctx->withErrors(['age' => 0]);
            $ctx->withErrors(['active' => true]);

            expect($ctx->errorsArray())->toBe([
                'age' => ['0'],
                'active' => ['1'],
            ]);
        });

        it('accepts Traversable and normalizes via toCollection', function () {
            $iter = new ArrayIterator(['email' => ['invalid']]);
            $ctx = Context::makeWithDefaults();
            $ctx->withErrors($iter);

            expect($ctx->errorsArray())->toBe(['email' => ['invalid']]);
        });

        it('merges multiple fields in one call', function () {
            $ctx = Context::makeWithDefaults();
            $ctx->withErrors([
                'name' => ['required'],
                'email' => ['invalid'],
            ]);
            expect($ctx->errorsArray())->toBe([
                'name' => ['required'],
                'email' => ['invalid'],
            ]);
        });
    });

    describe('withMeta', function () {
        it('merges meta values', function () {
            $ctx = Context::makeWithDefaults()
                ->withMeta(['x' => 1])
                ->withMeta(['y' => 2]);

            expect($ctx->metaArray())->toBe(['x' => 1, 'y' => 2]);
        });
    });

    describe('withInternalOnly', function () {
        it('merges internal-only values', function () {
            $ctx = Context::makeWithDefaults()
                ->withInternalOnly(['foo' => 'bar'])
                ->withInternalOnly(['baz' => 1]);

            expect($ctx->internalOnlyArray())->toBe(['foo' => 'bar', 'baz' => 1]);
        });
    });

    describe('withResource', function () {
        it('stores resources by key and overwrites existing keys', function () {
            $ctx = Context::makeWithDefaults()
                ->withResource('obj', ['id' => 1])
                ->withResource('obj', ['id' => 2])
                ->withResource('list', [1, 2]);

            expect($ctx->resourceArray())->toBe([
                'obj' => ['id' => 2],
                'list' => [1, 2],
            ]);
        });
    });

    describe('withInvokedAction', function () {
        it('sets the invokedAction object', function () {
            $action = new stdClass;
            $ctx = Context::makeWithDefaults()->withInvokedAction($action);

            expect($ctx->invokedAction)->toBe($action);
        });
    });

    describe('addErrorsAndAbort', function () {
        it('adds base error when incoming and current errors are empty, then throws', function () {
            $ctx = Context::makeWithDefaults();

            $caught = null;
            try {
                $ctx->addErrorsAndAbort([]);
            } catch (ContextFailedError $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught)->toBeInstanceOf(ContextFailedError::class);
            /** @var ContextFailedError $caught */
            $caughtCtx = $caught->getContext();

            expect($caughtCtx->errorsArray())->toBe([
                'base' => ['Context failed due to validation or business errors.'],
            ]);
        });

        it('merges provided errors and throws', function () {
            $ctx = Context::makeWithDefaults();

            $caught = null;
            try {
                $ctx->addErrorsAndAbort(['username' => ['taken']]);
            } catch (ContextFailedError $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught)->toBeInstanceOf(ContextFailedError::class);
            /** @var ContextFailedError $caught */
            $caughtCtx = $caught->getContext();

            expect($caughtCtx->errorsArray())->toBe([
                'username' => ['taken'],
            ]);
        });
    });

    describe('formattedErrors', function () {
        it('pretty-prints errors as JSON', function () {
            $ctx = Context::makeWithDefaults()->withErrors(['f' => ['e']]);

            expect($ctx->formattedErrors())->toBe(
                json_encode(['f' => ['e']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        });

        it('pretty-prints empty map when there are no errors', function () {
            $ctx = Context::makeWithDefaults();
            expect($ctx->formattedErrors())->toBe(
                json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        });

        it('includes unicode characters unescaped', function () {
            $ctx = Context::makeWithDefaults()->withErrors(['name' => ['α']]);
            expect($ctx->formattedErrors())->toBe(
                json_encode(['name' => ['α']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        });
    });

    describe('markComplete / markFailed / status', function () {
        it('updates status enum correctly', function () {
            $ctx = Context::makeWithDefaults();

            expect($ctx->status())->toBe(ContextStatus::INCOMPLETE);

            $ctx->markComplete();
            expect($ctx->status())->toBe(ContextStatus::COMPLETE);

            $ctx->markFailed();
            expect($ctx->status())->toBe(ContextStatus::FAILED);
        });
    });

    describe('success() / failure()', function () {
        it('fresh context is successful (no errors, not FAILED)', function () {
            $ctx = Context::makeWithDefaults();
            expect($ctx->success())->toBeTrue()
                ->and($ctx->failure())->toBeFalse();
        });

        it('adding errors flips to failure()', function () {
            $ctx = Context::makeWithDefaults()->withErrors(['f' => ['e']]);
            expect($ctx->success())->toBeFalse()
                ->and($ctx->failure())->toBeTrue();
        });

        it('markFailed() forces failure()', function () {
            $ctx = Context::makeWithDefaults()->markFailed();
            expect($ctx->success())->toBeFalse()
                ->and($ctx->failure())->toBeTrue();
        });

        it('completed without errors remains success()', function () {
            $ctx = Context::makeWithDefaults()->markComplete();
            expect($ctx->success())->toBeTrue()
                ->and($ctx->failure())->toBeFalse();
        });
    });

    describe('setCurrentOrganizer / setCurrentAction', function () {
        it('stores FQCNs and returns short names via organizerName/actionName', function () {
            $ctx = Context::makeWithDefaults()
                ->setCurrentOrganizer('Vendor\\Pkg\\Org\\MyOrganizer')
                ->setCurrentAction('Vendor\\Pkg\\Act\\MyAction');

            expect($ctx->organizerName())->toBe('MyOrganizer')
                ->and($ctx->actionName())->toBe('MyAction');
        });

        it('returns null names when not set', function () {
            $ctx = Context::makeWithDefaults();
            expect($ctx->organizerName())->toBeNull()
                ->and($ctx->actionName())->toBeNull();
        });
    });

    describe('recordRaisedError', function () {
        it('merges errors from exceptions exposing ->errors() and stores errorInfo', function () {
            $ex = new class('bad') extends Exception {
                /** @return array<string, list<string>> */
                public function errors(): array
                {
                    return ['field' => ['oops']];
                }
            };

            $ctx = Context::makeWithDefaults()
                ->setCurrentOrganizer('Foo\\Org\\RealOrganizer')
                ->setCurrentAction('Foo\\Act\\DoThing')
                ->recordRaisedError($ex);

            expect($ctx->errorsArray())->toBe(['field' => ['oops']]);

            $info = $ctx->internalOnly()->get('errorInfo');
            expect($info)->toBeArray();
            /** @var array{organizer?:string,actionName?:string,type?:string,message?:string,exception?:string,backtrace?:string} $info */
            expect($info)->toHaveKeys(['organizer', 'actionName', 'type', 'message', 'exception', 'backtrace']);

            if (isset($info['organizer'])) {
                expect($info['organizer'])->toBe('RealOrganizer');
            }
            if (isset($info['actionName'])) {
                expect($info['actionName'])->toBe('DoThing');
            }
            if (isset($info['type'])) {
                expect($info['type'])->toBe($ex::class);
            }

            // Assert accessor is not null, then narrow type for PHPStan:
            $errorInfo = $ctx->errorInfo();
            expect($errorInfo)->not->toBeNull();

            /** @var \Flowlight\ErrorInfo $errorInfo */
            $errorInfo = $errorInfo;

            expect($errorInfo->type)->toBe($ex::class)
                ->and($errorInfo->message)->toBe('bad');
        });

        it('still records errorInfo when exception has no errors()', function () {
            $ex = new RuntimeException('boom');

            $ctx = Context::makeWithDefaults()->recordRaisedError($ex);

            $info = $ctx->internalOnly()->get('errorInfo');
            expect($info)->toBeArray();
            /** @var array<string, mixed> $info */
            expect($info)->toHaveKeys(['type', 'message', 'exception', 'backtrace']);

            // Assert accessor is not null, then narrow type for PHPStan:
            $errorInfo = $ctx->errorInfo();
            expect($errorInfo)->not->toBeNull()
                ->and($errorInfo->type)->toBe($ex::class)
                ->and($errorInfo->message)->toBe('boom');
        });
    });

    describe('array and collection accessors', function () {
        it('exposes the underlying state as arrays', function () {
            $ctx = Context::makeWithDefaults(['k' => 'v'], [
                'params' => ['p' => 1],
                'meta' => ['m' => true],
                'resource' => ['r' => [1, 2]],
            ]);

            expect($ctx->inputArray())->toBe(['k' => 'v'])
                ->and($ctx->paramsArray())->toBe(['p' => 1])
                ->and($ctx->metaArray())->toBe(['m' => true])
                ->and($ctx->resourceArray())->toBe(['r' => [1, 2]]);
        });

        it('exposes collections directly', function () {
            $ctx = Context::makeWithDefaults(['a' => 1]);
            $input = $ctx->input();

            expect($input->get('a'))->toBe(1)
                ->and($input)->toBeInstanceOf(\Illuminate\Support\Collection::class);
        });

        it('exposes errors/params/resource/meta/extraRules via collection accessors', function () {
            $ctx = Context::makeWithDefaults([], [
                'extraRules' => ['min' => 3],
            ])
                ->withParams(['p' => 1])
                ->withMeta(['m' => true])
                ->withResource('r', ['id' => 9])
                ->withErrors(['e' => ['msg']]);

            $errors = $ctx->errors();
            $params = $ctx->params();
            $resource = $ctx->resource();
            $meta = $ctx->meta();
            $extra = $ctx->extraRules();

            expect($errors)->toBeInstanceOf(\Illuminate\Support\Collection::class)
                ->and($errors->get('e'))->toBe(['msg'])
                ->and($params)->toBeInstanceOf(\Illuminate\Support\Collection::class)
                ->and($params->get('p'))->toBe(1)
                ->and($resource)->toBeInstanceOf(\Illuminate\Support\Collection::class)
                ->and($resource->get('r'))->toBe(['id' => 9])
                ->and($meta)->toBeInstanceOf(\Illuminate\Support\Collection::class)
                ->and($meta->get('m'))->toBe(true)
                ->and($extra)->toBeInstanceOf(\Illuminate\Support\Collection::class)
                ->and($extra->get('min'))->toBe(3);
        });
    });

    describe('successfulActions', function () {
        it('defaults to empty, then appends with de-duplication and preserves order', function () {
            $ctx = Context::makeWithDefaults();

            expect($ctx->successfulActions())->toBe([]);

            $ctx->addSuccessfulAction('A')
                ->addSuccessfulAction('B')
                ->addSuccessfulAction('A');

            expect($ctx->successfulActions())->toBe(['A', 'B']);
        });
    });

    describe('lastFailedContext snapshot', function () {
        it('returns null when unset', function () {
            $ctx = Context::makeWithDefaults();

            expect($ctx->lastFailedContext())->toBeNull();
        });

        it('captures a full snapshot with default label = actionName()', function () {
            $ctx = Context::makeWithDefaults(
                ['in' => 1],
                [
                    'params' => ['p' => 2],
                    'meta' => ['m' => 3],
                    'resource' => ['r' => ['x' => true]],
                ]
            )
                ->setCurrentAction('App\\Actions\\DoThing')
                ->withErrors(['field' => ['bad']])
                ->markFailed();

            $ctx->setLastFailedContext($ctx);

            $snap = $ctx->lastFailedContext();

            expect($snap)->not->toBeNull();
            /** @var array{
             *   label?: string,
             *   input: array<string,mixed>,
             *   params: array<string,mixed>,
             *   meta: array<string,mixed>,
             *   errors: array<string,mixed>,
             *   resource: array<string,mixed>,
             *   status: string
             * } $snap
             */
            expect($snap)->toHaveKeys(['input', 'params', 'meta', 'errors', 'resource', 'status'])
                ->and($snap)->toHaveKey('label', 'DoThing')
                ->and($snap['input'])->toBe(['in' => 1])
                ->and($snap['params'])->toBe(['p' => 2])
                ->and($snap['meta'])->toBe(['m' => 3])
                ->and($snap['errors'])->toBe(['field' => ['bad']])
                ->and($snap['resource'])->toBe(['r' => ['x' => true]])
                ->and($snap['status'])->toBe('FAILED');
        });

        it('allows overriding the label', function () {
            $ctx = Context::makeWithDefaults(['k' => 'v'])
                ->setCurrentAction('App\\Actions\\IgnoredForCustomLabel')
                ->withErrors(['base' => ['oops']])
                ->markFailed();

            $ctx->setLastFailedContext($ctx, 'CustomLabel');

            $snap = $ctx->lastFailedContext();

            expect($snap)
                ->not->toBeNull()
                ->toMatchArray([
                    'label' => 'CustomLabel',
                    'status' => 'FAILED',
                ]);
        });
    });
});
