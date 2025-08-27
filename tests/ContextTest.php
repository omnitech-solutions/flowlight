<?php

declare(strict_types=1);

use Flowlight\Context;
use Flowlight\Enums\ContextOperation;
use Flowlight\Enums\ContextStatus;
use Flowlight\ErrorInfo;
use Illuminate\Support\Collection;

describe('::makeWithDefaults', function () {
    it('creates context with defaults and overrides', function () {
        $ctx = Context::makeWithDefaults(['in' => 1], [
            'params' => ['p' => 1],
            'meta' => ['m' => true],
            'resources' => ['r' => [1, 2]],
        ]);

        expect($ctx->inputArray())->toBe(['in' => 1])
            ->and($ctx->paramsArray())->toBe(['p' => 1])
            ->and($ctx->metaArray())->toBe(['operation' => ContextOperation::UPDATE->value, 'm' => true])
            ->and($ctx->resourcesArray())->toBe(['r' => [1, 2]]);
    });

    it('ignores unknown keys without side effects', function () {
        $ctx = Context::makeWithDefaults([], ['unknown' => 'x']);

        expect($ctx->paramsArray())->toBe([])
            ->and($ctx->errorsArray())->toBe([])
            ->and($ctx->resourcesArray())->toBe([])
            ->and($ctx->metaArray())->toBe(['operation' => ContextOperation::UPDATE->value])
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

    it('cannot set invokedAction via overrides when object provided', function () {
        $action = new stdClass;
        $ctx = Context::makeWithDefaults([], ['invokedAction' => $action]);
        expect($ctx->invokedAction)->toBeNull();
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

    it('is a no-op when given an empty params payload', function () {
        $ctx = Context::makeWithDefaults()->withParams(['x' => 1]);
        $same = $ctx->withParams([]); // should not change anything
        expect($same)->toBe($ctx)
            ->and($ctx->paramsArray())->toBe(['x' => 1]);
    });
});

describe('withErrors', function () {
    it('marks context as failed', function () {
        $ctx = Context::makeWithDefaults();
        $ctx->withErrors(['email' => ['invalid']]);
        expect($ctx->status())->toBe(ContextStatus::INCOMPLETE);
    });

    it('merges and deduplicates errors per field while keeping order', function () {
        $ctx = Context::makeWithDefaults();
        $ctx->withErrors(['email' => ['invalid']]);
        $ctx->withErrors(['email' => ['invalid', 'too short']]);

        expect($ctx->errorsArray())->toBe([
            'email' => ['invalid', 'too short'],
        ]);
    });

    it('accepts scalars', function () {
        $ctx = Context::makeWithDefaults();

        $ctx->withErrors(['age' => 0]);
        $ctx->withErrors(['active' => true]);

        expect($ctx->errorsArray())->toBe([
            'age' => 0,
            'active' => true,
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

    it('is a no-op when given empty errors', function () {
        $ctx = Context::makeWithDefaults()->withErrors([]);
        expect($ctx->errorsArray())->toBe([])
            ->and($ctx->aborted())->toBeFalse(); // guard should not flip aborted
    });
});

describe('withMeta', function () {
    it('merges meta values', function () {
        $ctx = Context::makeWithDefaults()->markUpdateOperation();

        $ctx->withMeta(['a' => 1])->withMeta(['b' => 2]);

        // enum-aware assertions
        expect($ctx->operation())->toBe(ContextOperation::UPDATE->value)
            ->and($ctx->meta()->get('operation'))->toBe(ContextOperation::UPDATE->value)
            ->and($ctx->metaArray())->toMatchArray([
                'a' => 1,
                'b' => 2,
                'operation' => ContextOperation::UPDATE->value,
            ]);
    });

    it('withMeta merges alongside operation without coercing to string', function () {
        $ctx = Context::makeWithDefaults()->markUpdateOperation();
        $ctx->withMeta(['foo' => 'bar']);

        expect($ctx->operation())->toBe(ContextOperation::UPDATE->value)
            ->and($ctx->metaArray())->toMatchArray([
                'foo' => 'bar',
                'operation' => ContextOperation::UPDATE->value,
            ]);
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

describe('resources API', function () {
    it('withResource sets by key (overwrites) and withResources merges shallowly', function () {
        $ctx = Context::makeWithDefaults()
            ->withResource('obj', ['id' => 1])
            ->withResource('obj', ['id' => 2]) // overwrite
            ->withResources(['list' => [1]])   // merge
            ->withResources(['list' => [1, 2], 'extra' => true]); // merge+add

        expect($ctx->resourcesArray())->toBe([
            'obj' => ['id' => 2],
            'list' => [1, 2],
            'extra' => true,
        ]);
    });

    it('withResources with empty collection is a no-op merge', function () {
        $ctx = Context::makeWithDefaults();

        $ctx->withResources(collect());
        expect($ctx->resourcesArray())->toBe([]);
    });

    it('resource() supports dotted keys and returns null for missing', function () {
        $ctx = Context::makeWithDefaults()->withResources([
            'a' => ['b' => ['c' => 3]],
        ]);

        expect($ctx->resource('a.b.c'))->toBe(3)
            ->and($ctx->resource('a.b.x'))->toBeNull();
    });

    it('resources() returns the backing Collection and supports onlyKeys via Collection::only()', function () {
        $ctx = Context::makeWithDefaults()->withResources([
            'r' => ['id' => 9],
            's' => 2,
        ]);

        $all = $ctx->resources();
        $only = $ctx->resources()->only(['r']);

        expect($all)->toBeInstanceOf(Collection::class)
            ->and($all->get('r'))->toBe(['id' => 9])
            ->and($only->all())->toBe(['r' => ['id' => 9]]);
    });
});

describe('withInvokedAction', function () {
    it('sets the invokedAction object', function () {
        $action = new stdClass;
        $ctx = Context::makeWithDefaults()->withInvokedAction($action);

        expect($ctx->invokedAction)->toBe($action);
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

describe('markComplete / status', function () {
    it('updates status enum correctly', function () {
        $ctx = Context::makeWithDefaults();

        expect($ctx->status())->toBe(ContextStatus::INCOMPLETE);

        $ctx->markComplete();
        expect($ctx->status())->toBe(ContextStatus::COMPLETE);

        $ctx->abort();
        expect($ctx->aborted())->toBeTrue();
    });
});

describe('isIncomplete', function () {
    it('reflects INCOMPLETE until markComplete is called', function () {
        $ctx = Context::makeWithDefaults();

        expect($ctx->isIncomplete())->toBeTrue();

        $ctx->markComplete();

        expect($ctx->isIncomplete())->toBeFalse();
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

    it('abort() forces failure()', function () {
        $ctx = Context::makeWithDefaults()->abort();
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

    it('returns the same name when action has no namespace separator', function () {
        $ctx = Context::makeWithDefaults()->setCurrentAction('PlainAction');
        expect($ctx->actionName())->toBe('PlainAction');
    });
});

describe('recordRaisedError', function () {
    it('merges errors from exceptions exposing ->errors() and stores errorInfo', function () {
        $ex = new class('bad') extends Exception
        {
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

        /** @var ErrorInfo $errorInfo */
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

        // Narrow type for PHPStan, then access properties
        $errorInfo = $ctx->errorInfo();
        expect($errorInfo)->toBeInstanceOf(ErrorInfo::class);

        /** @var ErrorInfo $errorInfo */
        $errorInfo = $errorInfo;

        expect($errorInfo->type)->toBe($ex::class)
            ->and($errorInfo->message)->toBe('boom');
    });
});

describe('array and collection accessors', function () {
    it('exposes the underlying state as arrays', function () {
        $ctx = Context::makeWithDefaults(['k' => 'v'], [
            'params' => ['p' => 1],
            'meta' => ['m' => true],
            'resources' => ['r' => [1, 2]],
        ]);

        expect($ctx->inputArray())->toBe(['k' => 'v'])
            ->and($ctx->paramsArray())->toBe(['p' => 1])
            ->and($ctx->meta(['m'])->all())->toBe(['m' => true])
            ->and($ctx->resourcesArray())->toBe(['r' => [1, 2]]);
    });

    it('exposes collections directly', function () {
        $ctx = Context::makeWithDefaults(['a' => 1]);
        $input = $ctx->input();

        expect($input->get('a'))->toBe(1)
            ->and($input)->toBeInstanceOf(Collection::class);
    });

    it('exposes errors/params/resources/meta/extraRules via collection accessors', function () {
        $ctx = Context::makeWithDefaults([], [
            'extraRules' => ['min' => 3],
        ])
            ->withParams(['p' => 1])
            ->withMeta(['m' => true])
            ->withResource('r', ['id' => 9])
            ->withErrors(['e' => ['msg']]);

        $errors = $ctx->errors();
        $params = $ctx->params();
        $resources = $ctx->resources();
        $meta = $ctx->meta();
        $extra = $ctx->extraRules();

        expect($errors)->toBeInstanceOf(Collection::class)
            ->and($errors->get('e'))->toBe(['msg'])
            ->and($params)->toBeInstanceOf(Collection::class)
            ->and($params->get('p'))->toBe(1)
            ->and($resources)->toBeInstanceOf(Collection::class)
            ->and($resources->get('r'))->toBe(['id' => 9])
            ->and($meta)->toBeInstanceOf(Collection::class)
            ->and($meta->get('m'))->toBe(true)
            ->and($extra)->toBeInstanceOf(Collection::class)
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

describe('dottedOmitRules overrides and accessors', function () {
    it('accepts dottedOmitRules as array and exposes via accessors', function () {
        $ctx = Context::makeWithDefaults([], [
            'dottedOmitRules' => ['profile.name', 'meta.flag'],
        ]);

        // array accessor
        expect($ctx->dottedOmitRulesArray())->toBe(['profile.name', 'meta.flag']);

        // collection accessor (typed list<int,string>)
        $col = $ctx->dottedOmitRules();
        expect($col)->toBeInstanceOf(\Illuminate\Support\Collection::class)
            ->and($col->all())->toBe(['profile.name', 'meta.flag']);
    });

    it('accepts dottedOmitRules as Collection and as single string', function () {
        // Collection input
        $colInput = collect(['a.b', 'c']);
        $ctxA = Context::makeWithDefaults([], [
            'dottedOmitRules' => $colInput,
        ]);

        expect($ctxA->dottedOmitRulesArray())->toBe(['a.b', 'c'])
            ->and($ctxA->dottedOmitRules()->all())->toBe(['a.b', 'c']);

        // Single string input
        $ctxB = Context::makeWithDefaults([], [
            'dottedOmitRules' => 'one.two',
        ]);

        expect($ctxB->dottedOmitRulesArray())->toBe(['one.two'])
            ->and($ctxB->dottedOmitRules()->all())->toBe(['one.two']);
    });

    it('normalizes invalid dottedOmitRules types to empty list', function () {
        // Pass a type normalizeKeyList does not accept (e.g., int)
        $ctx = Context::makeWithDefaults([], [
            'dottedOmitRules' => 123,
        ]);

        expect($ctx->dottedOmitRulesArray())->toBe([])
            ->and($ctx->dottedOmitRules()->all())->toBe([]);
    });

    it('coexists with other overrides without interference', function () {
        $ctx = Context::makeWithDefaults(
            ['in' => 1],
            [
                'params' => ['p' => 2],
                'meta' => ['m' => true],
                'resources' => ['r' => ['x' => 9]],
                'dottedOmitRules' => ['foo.bar', 'baz'],
            ]
        );

        expect($ctx->paramsArray())->toBe(['p' => 2])
            ->and($ctx->metaArray())->toMatchArray([
                'operation' => ContextOperation::UPDATE->value,
                'm' => true,
            ])
            ->and($ctx->resourcesArray())->toBe(['r' => ['x' => 9]])
            ->and($ctx->dottedOmitRulesArray())->toBe(['foo.bar', 'baz']);
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
                'resources' => ['r' => ['x' => true]],
            ]
        )
            ->setCurrentAction('App\\Actions\\DoThing')
            ->withErrors(['field' => ['bad']])
            ->abort();

        $ctx->setLastFailedContext($ctx);

        $snap = $ctx->lastFailedContext();

        expect($snap)->not->toBeNull();
        /** @var array{
         *   label?: string,
         *   input: array<string,mixed>,
         *   params: array<string,mixed>,
         *   meta: array<string,mixed>,
         *   errors: array<string,mixed>,
         *   resources: array<string,mixed>,
         *   status: string
         * } $snap
         */
        expect($snap)->toHaveKeys(['input', 'params', 'meta', 'errors', 'resources', 'status'])
            ->and($snap)->toHaveKey('label', 'DoThing')
            ->and($snap['input'])->toBe(['in' => 1])
            ->and($snap['params'])->toBe(['p' => 2])
            ->and($snap['meta'])->toBe([
                'operation' => ContextOperation::UPDATE->value,
                'm' => 3,
            ])
            ->and($snap['errors'])->toBe(['field' => ['bad']])
            ->and($snap['resources'])->toBe(['r' => ['x' => true]])
            ->and($snap['status'])->toBe(ContextStatus::INCOMPLETE->value);
    });

    it('allows overriding the label', function () {
        $ctx = Context::makeWithDefaults(['k' => 'v'])
            ->setCurrentAction('App\\Actions\\IgnoredForCustomLabel')
            ->withErrors(['base' => ['oops']])
            ->abort();

        $ctx->setLastFailedContext($ctx, 'CustomLabel');

        $snap = $ctx->lastFailedContext();

        expect($snap)
            ->not->toBeNull()
            ->toMatchArray([
                'label' => 'CustomLabel',
                'status' => ContextStatus::INCOMPLETE->value,
            ]);
    });
});

describe('operation helpers', function () {
    it('defaults to UPDATE on new contexts', function () {
        $ctx = Context::makeWithDefaults(); // ctor marks UPDATE by default
        expect($ctx->operation())->toBe(ContextOperation::UPDATE->value)
            ->and($ctx->meta()->get('operation'))->toBe(ContextOperation::UPDATE->value);
    });

    it('markCreateOperation switches to CREATE', function () {
        $ctx = Context::makeWithDefaults()->markCreateOperation();
        expect($ctx->operation())->toBe(ContextOperation::CREATE->value)
            ->and($ctx->createOperation())->toBeTrue()
            ->and($ctx->updateOperation())->toBeFalse();
    });

    it('markUpdateOperation switches to UPDATE', function () {
        $ctx = Context::makeWithDefaults()->markCreateOperation()->markUpdateOperation();
        expect($ctx->operation())->toBe(ContextOperation::UPDATE->value)
            ->and($ctx->updateOperation())->toBeTrue()
            ->and($ctx->createOperation())->toBeFalse();
    });
});

describe('toCollection / toArray and asCollection fallback', function () {
    it('builds a full snapshot and falls back to empty collections for bad override types', function () {
        // Non-array/collection override triggers asCollection() default branch → empty collect()
        $ctx = Context::makeWithDefaults(['in' => 1], [
            'params' => 'not-an-array',
        ]);

        $bag = $ctx->toCollection();
        $arr = $ctx->toArray();

        // toArray mirrors toCollection()->toArray()
        expect($arr)->toBe($bag->toArray());

        // Basic shape & values
        expect($bag)->toBeInstanceOf(Collection::class)
            ->and($bag->get('input'))->toBe(['in' => 1])
            ->and($bag->get('params'))->toBe([]) // fallback worked
            ->and($bag->get('meta'))->toBe(['operation' => ContextOperation::UPDATE->value])
            ->and($bag->get('errors'))->toBe([])
            ->and($bag->get('resources'))->toBe([])
            ->and($bag->get('errorInfo'))->toBeNull()
            ->and($bag->get('organizer'))->toBeNull()
            ->and($bag->get('action'))->toBeNull()
            // status uses the enum NAME in toCollection()
            ->and($bag->get('status'))->toBe(ContextStatus::INCOMPLETE->name)
            ->and($bag->get('aborted'))->toBeFalse()
            ->and($bag->get('success'))->toBeTrue()
            ->and($bag->get('failure'))->toBeFalse()
            ->and($bag->get('operation'))->toBe(ContextOperation::UPDATE->value);
    });
});

//
// New tests for dotted-key filtering on accessors & arrays,
// and dotted-key snapshot lookups via toCollection()/toArray().
//
describe('dotted-key accessors and arrays', function () {
    it('supports single and multiple dotted keys across all accessors, defaulting missing to null', function () {
        $ctx = Context::makeWithDefaults(
            ['in' => ['a' => 1, 'b' => ['c' => 3]]],
            [
                'params' => ['p' => 2],
                'meta' => ['m' => true],
                'resources' => [
                    'obj' => ['id' => 9],
                    'list' => [0, 1, 2],
                ],
                'errors' => ['email' => ['invalid']],
                'internalOnly' => ['secret' => 42],
            ]
        );

        // Collections
        $inOne = $ctx->input('in.b.c');
        $inMany = $ctx->input(['in.a', 'missing']);
        $prOne = $ctx->params('p');
        $prMany = $ctx->params(['p', 'missing']);
        $erMany = $ctx->errors(['email', 'none']);
        $rsMany = $ctx->resources(['obj.id', 'list.1', 'resources.missing']);
        $mtMany = $ctx->meta(['operation', 'm', 'nope']);
        $ioMany = $ctx->internalOnly(['secret', 'also.missing']);

        expect($inOne)->toBeInstanceOf(Collection::class)
            ->and($inOne->all())->toBe(['in.b.c' => 3]);

        expect($inMany->all())->toBe(['in.a' => 1, 'missing' => null]);
        expect($prOne->all())->toBe(['p' => 2]);
        expect($prMany->all())->toBe(['p' => 2, 'missing' => null]);
        expect($erMany->all())->toBe(['email' => ['invalid'], 'none' => null]);
        expect($rsMany->all())->toBe(['obj.id' => 9, 'list.1' => 1, 'resources.missing' => null]);
        expect($mtMany->all())->toBe([
            'operation' => ContextOperation::UPDATE->value, // default set in ctor
            'm' => true,
            'nope' => null,
        ]);
        expect($ioMany->all())->toBe(['secret' => 42, 'also.missing' => null]);

        // Array counterparts always go through the collection → ->all()
        expect($ctx->inputArray(['in.b.c']))->toBe(['in.b.c' => 3])
            ->and($ctx->paramsArray(['p', 'missing']))->toBe(['p' => 2, 'missing' => null])
            ->and($ctx->errorsArray(['email', 'none']))->toBe(['email' => ['invalid'], 'none' => null])
            ->and($ctx->resourcesArray(['obj.id', 'list.1', 'nope']))->toBe(['obj.id' => 9, 'list.1' => 1, 'nope' => null])
            ->and($ctx->metaArray(['m', 'operation']))->toBe(['m' => true, 'operation' => ContextOperation::UPDATE->value])
            ->and($ctx->internalOnlyArray(['secret', 'k']))->toBe(['secret' => 42, 'k' => null]);
    });
});

describe('toCollection / toArray dotted-key lookups (snapshot-level)', function () {
    it('returns a snapshot filtered by dotted keys (unknowns -> null)', function () {
        $ctx = Context::makeWithDefaults(
            ['in' => ['x' => 10]],
            [
                'params' => ['p' => 7],
                'meta' => ['m' => true],
                'resources' => ['box' => ['w' => ['h' => 5]]],
                'errors' => ['code' => ['E_BAD']],
                'internalOnly' => ['flag' => 'ok'],
            ]
        );

        // Expect these dotted keys to be pulled from the snapshot produced by toCollection():
        $keys = [
            'input.in.x',
            'params.p',
            'meta.m',
            'errors.code',
            'resources.box.w.h',
            'internalOnly.flag', // not directly present in snapshot, but we allow dotted lookup to null
            'status',
            'aborted',
            'operation',
            'missing.path',
        ];

        $snap = $ctx->toCollection($keys);
        $arr = $ctx->toArray($keys);

        expect($snap)->toBeInstanceOf(Collection::class)
            ->and($arr)->toBe($snap->toArray())
            ->and($arr)->toMatchArray([
                'input.in.x' => 10,
                'params.p' => 7,
                'meta.m' => true,
                'errors.code' => ['E_BAD'],
                'resources.box.w.h' => 5,
                'internalOnly.flag' => null, // not exposed in snapshot; should default to null
                'status' => ContextStatus::INCOMPLETE->name,
                'aborted' => false,
                'operation' => ContextOperation::UPDATE->value,
                'missing.path' => null,
            ]);
    });
});
