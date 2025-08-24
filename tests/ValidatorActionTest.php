<?php

declare(strict_types=1);

namespace Tests;

use Flowlight\BaseDto;
use Flowlight\Context;
use Flowlight\ValidatorAction;
use Illuminate\Support\Collection;

/**
 * A class-string that does NOT exist (for the 'class does not exist' branch).
 *
 * @var class-string
 */
const NON_EXISTENT_CLASS = 'Tests\\__ReallyMissingMapper__';

//
// Small helpers for building anonymous Action classes that can return dynamic
// class-strings from static methods (configured via static setters).
//

/**
 * @param  class-string<BaseDto>  $dtoClass
 * @return object anonymous action instance; call ($action::class)::execute($ctx)
 */
function makeAnonActionWithDto(string $dtoClass): object
{
    $action = new class extends ValidatorAction
    {
        /** @var class-string<BaseDto> */
        protected static string $DTO;

        /** @return class-string<BaseDto> */
        protected static function dtoClass(): string
        {
            return self::$DTO;
        }

        /** @param class-string<BaseDto> $dto */
        public static function setDto(string $dto): void
        {
            self::$DTO = $dto;
        }
    };

    // Configure via static setter (no constructor!)
    /** @phpstan-var class-string<BaseDto> $dtoClass */
    $action::setDto($dtoClass);

    return $action;
}

/**
 * @param  class-string<BaseDto>  $dtoClass
 * @param  class-string|null  $beforeClass
 * @param  class-string|null  $afterClass
 * @return object anonymous action instance; call ($action::class)::execute($ctx)
 */
function makeAnonActionFull(
    string $dtoClass,
    ?callable $beforeCallable = null,
    ?string $beforeClass = null,
    ?callable $afterCallable = null,
    ?string $afterClass = null,
): object {
    $action = new class extends ValidatorAction
    {
        /** @var class-string<BaseDto> */
        protected static string $DTO;

        /** @var (callable(mixed):array<string,mixed>)|null */
        protected static $BEFORE_CALLABLE = null;

        /** @var class-string|null */
        protected static ?string $BEFORE_CLASS = null;

        /** @var (callable(mixed):array<string,mixed>)|null */
        protected static $AFTER_CALLABLE = null;

        /** @var class-string|null */
        protected static ?string $AFTER_CLASS = null;

        /** @return class-string<BaseDto> */
        protected static function dtoClass(): string
        {
            return self::$DTO;
        }

        protected static function beforeValidationMapper(): callable|string|null
        {
            return self::$BEFORE_CLASS ?? self::$BEFORE_CALLABLE;
        }

        protected static function afterValidationMapper(): callable|string|null
        {
            return self::$AFTER_CLASS ?? self::$AFTER_CALLABLE;
        }

        /**
         * @param  class-string<BaseDto>  $dto
         * @param  (callable(mixed):array<string,mixed>)|null  $beforeCallable
         * @param  class-string|null  $beforeClass
         * @param  (callable(mixed):array<string,mixed>)|null  $afterCallable
         * @param  class-string|null  $afterClass
         */
        public static function setConfig(
            string $dto,
            ?callable $beforeCallable,
            ?string $beforeClass,
            ?callable $afterCallable,
            ?string $afterClass,
        ): void {
            self::$DTO = $dto;
            self::$BEFORE_CALLABLE = $beforeCallable;
            self::$BEFORE_CLASS = $beforeClass;
            self::$AFTER_CALLABLE = $afterCallable;
            self::$AFTER_CLASS = $afterClass;
        }
    };

    /** @phpstan-var class-string<BaseDto> $dtoClass */
    $dtoForSet = $dtoClass;
    /** @phpstan-var class-string|null $beforeClass */
    $beforeClassForSet = $beforeClass;
    /** @phpstan-var class-string|null $afterClass */
    $afterClassForSet = $afterClass;

    $action::setConfig(
        $dtoForSet,
        $beforeCallable,
        $beforeClassForSet,
        $afterCallable,
        $afterClassForSet,
    );

    return $action;
}

//
// Anonymous DTO builders
//

/** @return class-string<BaseDto> */
function makeEmailDtoClass(): string
{
    $dto = new class extends BaseDto
    {
        /** @return Collection<string, array<int, mixed>> */
        protected static function rules(): Collection
        {
            return collect([
                'email' => ['required', 'email'],
            ]);
        }
    };

    /** @var class-string<BaseDto> */
    return $dto::class;
}

/** @return class-string<BaseDto> */
function makeIdUuidDtoClass(): string
{
    $dto = new class extends BaseDto
    {
        /** @return Collection<string, array<int, mixed>> */
        protected static function rules(): Collection
        {
            return collect([
                'id' => ['required', 'integer', 'min:1'],
                'uuid' => ['required', 'uuid'],
            ]);
        }
    };

    /** @var class-string<BaseDto> */
    return $dto::class;
}

//
// Anonymous mappers with static mapFrom
//

/** @return class-string */
function makeBeforeMapperClass(): string
{
    $mapper = new class
    {
        /** @return array<string,mixed> */
        public static function mapFrom(mixed $data): array
        {
            $in = is_array($data) ? $data : (array) $data;

            return [
                'email' => $in['user_email'] ?? '',
                'id' => $in['id'] ?? null,   // pass through
                'uuid' => $in['uuid'] ?? null,   // pass through
                'keep' => $in['keep'] ?? null,
            ];
        }
    };

    return $mapper::class;
}

/** @return class-string */
function makeAfterMapperClass(): string
{
    $mapper = new class
    {
        /** @return array<string,mixed> */
        public static function mapFrom(mixed $data): array
        {
            $in = is_array($data) ? $data : (array) $data;

            // Build a string-keyed array, and only lowercase if email is a string
            $out = [];
            foreach ($in as $k => $v) {
                if (is_string($k)) {
                    $out[$k] = $v;
                }
            }

            $normalized = null;
            if (isset($out['email']) && is_string($out['email'])) {
                $normalized = mb_strtolower($out['email']);
            }
            $out['normalized_email'] = $normalized;

            /** @var array<string,mixed> */
            return $out;
        }
    };

    return $mapper::class;
}

/**
 * @return callable(mixed): array<string,mixed>
 */
function makeBadBeforeCallable(): callable
{
    /** @phpstan-ignore-next-line */
    return static function (mixed $_ = null) {

        return 'nope';
    };
}

/**
 * @return callable(mixed): array<string,mixed>
 */
function makeBadAfterCallable(): callable
{
    /** @phpstan-ignore-next-line */
    return static function (mixed $_ = null) {
        return 123;
    };
}

/** @return class-string */
function makeMapperClassWithoutMapFrom(): string
{
    // anonymous class with no mapFrom
    $mapper = new class
    {
        // intentionally empty: no static mapFrom method
    };

    /** @var class-string */
    return $mapper::class;
}

/** @return class-string */
function makeMapperClassWithBadMapFromReturn(): string
{
    // anonymous class with mapFrom returning a non-array/non-Collection
    $mapper = new class
    {
        /** @return mixed */
        public static function mapFrom(mixed $data)
        {
            return 'nope'; // wrong type on purpose
        }
    };

    /** @var class-string */
    return $mapper::class;
}

describe(ValidatorAction::class, function () {
    describe('execute', function () {
        it('validates successfully and sets params (no mappers)', function () {
            $emailDto = makeEmailDtoClass();
            $actionObj = makeAnonActionWithDto($emailDto);

            $ctx = Context::makeWithDefaults(['email' => 'Good@Example.com', 'extra' => 'keep-me']);

            $out = $actionObj::class::execute($ctx);
            /** @var Context $out */
            expect($out->success())->toBeTrue()
                ->and($out->paramsArray())->toMatchArray([
                    'email' => 'Good@Example.com',
                    'extra' => 'keep-me',
                ]);
        });

        it('merges errors into context and marks failed on validation failure', function () {
            $emailDto = makeEmailDtoClass();
            $actionObj = makeAnonActionWithDto($emailDto);

            $ctx = Context::makeWithDefaults(['email' => '']); // invalid

            $out = $actionObj::class::execute($ctx);
            /** @var Context $out */
            expect($out->failure())->toBeTrue()
                ->and(array_key_exists('email', $out->errorsArray()))->toBeTrue()
                ->and($out->paramsArray())->toBe([]); // no params on failure
        });

        it('supports beforeValidationMapper as callable', function () {
            $emailDto = makeEmailDtoClass();

            /** @return array<string,mixed> */
            $beforeCallable = static function (mixed $data): array {
                $in = is_array($data) ? $data : (array) $data;

                return [
                    // map to the DTO field
                    'email' => $in['user_email'] ?? '',
                    // explicitly pass through id/uuid
                    'id' => $in['id'] ?? null,
                    'uuid' => $in['uuid'] ?? null,
                    'keep' => $in['keep'] ?? null,
                ];
            };

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                beforeCallable: $beforeCallable,
            );

            $ctx = Context::makeWithDefaults([
                'user_email' => 'User@Example.com',
                'keep' => 'X',
                'id' => 123,
                'uuid' => '9b4e64e5-9d2f-4b3b-a84c-8c0d9d1a1111',
            ]);

            $out = $actionObj::class::execute($ctx);
            /** @var Context $out */
            expect($out->success())->toBeTrue()
                ->and($out->paramsArray())->toMatchArray([
                    'email' => 'User@Example.com',
                    'keep' => 'X',
                    'id' => 123,
                    'uuid' => '9b4e64e5-9d2f-4b3b-a84c-8c0d9d1a1111',
                ]);
        });

        it('supports beforeValidationMapper as class-string', function () {
            $emailDto = makeEmailDtoClass();
            $beforeMapper = makeBeforeMapperClass();

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                beforeClass: $beforeMapper,
            );

            $ctx = Context::makeWithDefaults([
                'user_email' => 'User@Example.com',
                'keep' => 'Y',
                'id' => 7,
                'uuid' => '11111111-2222-3333-4444-555555555555',
            ]);

            $out = $actionObj::class::execute($ctx);
            /** @var Context $out */
            expect($out->success())->toBeTrue()
                ->and($out->paramsArray())->toMatchArray([
                    'email' => 'User@Example.com',
                    'keep' => 'Y',
                    'id' => 7,
                    'uuid' => '11111111-2222-3333-4444-555555555555',
                ]);
        });

        it('supports afterValidationMapper as callable', function () {
            $emailDto = makeEmailDtoClass();

            /** @return array<string,mixed> */
            $afterCallable = static function (mixed $data): array {
                $in = is_array($data) ? $data : (array) $data;

                // build string-keyed array
                $out = [];
                foreach ($in as $k => $v) {
                    if (is_string($k)) {
                        $out[$k] = $v;
                    }
                }

                $normalized = null;
                if (isset($out['email']) && is_string($out['email'])) {
                    $normalized = mb_strtolower($out['email']);
                }
                $out['normalized_email'] = $normalized;

                /** @var array<string,mixed> */
                return $out;
            };

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                afterCallable: $afterCallable,
            );

            $ctx = Context::makeWithDefaults(['email' => 'MiXed@Example.com']);

            $out = $actionObj::class::execute($ctx);
            /** @var Context $out */
            expect($out->success())->toBeTrue()
                ->and($out->paramsArray())->toMatchArray([
                    'email' => 'MiXed@Example.com',
                    'normalized_email' => 'mixed@example.com',
                ]);
        });

        it('supports afterValidationMapper as class-string', function () {
            $emailDto = makeEmailDtoClass();
            $afterMapper = makeAfterMapperClass();

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                afterClass: $afterMapper,
            );

            $ctx = Context::makeWithDefaults(['email' => 'MiXed@Example.com']);

            $out = $actionObj::class::execute($ctx);
            /** @var Context $out */
            expect($out->success())->toBeTrue()
                ->and($out->paramsArray())->toMatchArray([
                    'email' => 'MiXed@Example.com',
                    'normalized_email' => 'mixed@example.com',
                ]);
        });

        it('throws when beforeValidationMapper returns a non-array/Collection', function () {
            $emailDto = makeEmailDtoClass();

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                beforeCallable: makeBadBeforeCallable()
            );

            $ctx = Context::makeWithDefaults(['user_email' => 'ok@example.com']);

            /** @phpstan-ignore-next-line */
            expect(fn () => $actionObj::class::execute($ctx))->toThrow(\RuntimeException::class);
        });

        it('throws when afterValidationMapper returns a non-array/Collection', function () {
            $emailDto = makeEmailDtoClass();

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                afterCallable: makeBadAfterCallable()
            );

            $ctx = Context::makeWithDefaults(['email' => 'ok@example.com']);

            /** @phpstan-ignore-next-line */
            expect(fn () => $actionObj::class::execute($ctx))->toThrow(\RuntimeException::class);
        });

        it('throws when dtoClass is not a BaseDto subclass', function () {
            // Build an action that lies about dtoClass()
            $action = new class extends ValidatorAction
            {
                /** @return class-string<BaseDto> */
                protected static function dtoClass(): string
                {
                    /** @phpstan-ignore-next-line */
                    return \stdClass::class; // invalid
                }
            };

            /** @var class-string $cls */
            $cls = $action::class;
            $ctx = Context::makeWithDefaults(['email' => 'ok@example.com']);

            /** @phpstan-ignore-next-line */
            expect(fn () => $cls::execute($ctx))->toThrow(\RuntimeException::class);
        });

        it('merges validated values over the pre-mapped input (array_replace)', function () {
            $emailDto = makeEmailDtoClass();

            /** @return array<string,mixed> */
            $before = static function (mixed $data): array {
                $in = is_array($data) ? $data : (array) $data;

                return [
                    'email' => $in['email'] ?? 'fallback@example.com',
                    'keep' => 'BEFORE',
                ];
            };

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                beforeCallable: $before,
            );
            $ctx = Context::makeWithDefaults(['email' => 'GOOD@EXAMPLE.COM']);
            $out = $actionObj::class::execute($ctx);
            /** @var Context $out */

            // validator->validated() preserves 'email', merged over base input
            expect($out->success())->toBeTrue()
                ->and($out->paramsArray())->toMatchArray([
                    'email' => 'GOOD@EXAMPLE.COM',
                    'keep' => 'BEFORE',
                ]);
        });

        it('throws when beforeValidationMapper is a non-existent class-string', function () {
            $emailDto = makeEmailDtoClass();

            /** @phpstan-ignore-next-line */
            $actionObj = makeAnonActionFull(dtoClass: $emailDto, beforeClass: 'Tests\\__DefinitelyNotAClass__');

            $ctx = Context::makeWithDefaults(['user_email' => 'ok@example.com']);

            /** @var class-string&literal-string $actionClass */
            $actionClass = $actionObj::class;

            /** @var \Closure(): void $thunk */
            $thunk = static function () use ($actionClass, $ctx): void {
                $actionClass::execute($ctx);
            };

            expect($thunk)->toThrow(
                \RuntimeException::class,
                'Mapper must be a callable or a class-string with static mapFrom(mixed $data).'
            );
        });

        it('throws when beforeValidationMapper class-string lacks static mapFrom', function () {
            $emailDto = makeEmailDtoClass();
            $badClass = makeMapperClassWithoutMapFrom();

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                beforeClass: $badClass,
            );
            $actionCls = $actionObj::class;

            $ctx = Context::makeWithDefaults(['user_email' => 'ok@example.com']);

            /** @phpstan-ignore-next-line */
            expect(fn () => $actionCls::execute($ctx))
                ->toThrow(\RuntimeException::class, 'Class-string mapper must define static mapFrom(mixed $data): array|Collection.');
        });

        it('throws when beforeValidationMapper::mapFrom returns non-array/Collection', function () {
            $emailDto = makeEmailDtoClass();
            $badReturnCls = makeMapperClassWithBadMapFromReturn();

            $actionObj = makeAnonActionFull(
                dtoClass: $emailDto,
                beforeClass: $badReturnCls,
            );
            $actionCls = $actionObj::class;

            $ctx = Context::makeWithDefaults(['user_email' => 'ok@example.com']);

            /** @phpstan-ignore-next-line */
            expect(fn () => $actionCls::execute($ctx))
                ->toThrow(\RuntimeException::class, 'Class-string mapper mapFrom() must return array|Collection.');
        });

        it('throws for no-op mapper when input is not array/Collection (private helper)', function () {
            $emailDto = makeEmailDtoClass();
            $actionObj = makeAnonActionFull($emailDto); // mappers default to null

            // Call the private static applyMapperExpectMap(null, <non-array>) via reflection
            $ref = new \ReflectionMethod($actionObj::class, 'applyMapperExpectMap');
            $ref->setAccessible(true);

            expect(fn () => $ref->invoke(null, null, 123)) // mapper=null, data=int
                ->toThrow(\RuntimeException::class, 'No-op mapper requires input to be array|Collection.');
        });
    });

    describe('executeForCreateOperation', function () {
        it('keeps id/uuid rules for CREATE (fails on missing/invalid)', function () {
            $idUuidDto = makeIdUuidDtoClass();
            $actionObj = makeAnonActionWithDto($idUuidDto);

            // No id/uuid in input → should fail under CREATE
            $ctx = Context::makeWithDefaults(['something' => 1]);

            $out = $actionObj::class::executeForCreateOperation($ctx);
            /** @var Context $out */
            $errors = $out->errorsArray();
            expect($out->failure())->toBeTrue()
                ->and(array_key_exists('id', $errors))->toBeTrue()
                ->and(array_key_exists('uuid', $errors))->toBeTrue();
        });
    });

    describe('executeForUpdateOperation', function () {
        it('drops id/uuid rules for UPDATE (passes without them)', function () {
            $idUuidDto = makeIdUuidDtoClass();
            $actionObj = makeAnonActionWithDto($idUuidDto);

            $ctx = Context::makeWithDefaults(['something' => 1]);

            $out = $actionObj::class::executeForUpdateOperation($ctx);
            /** @var Context $out */
            $errors = $out->errorsArray();

            expect($out->success())->toBeTrue()
                ->and(array_key_exists('id', $errors))->toBeFalse()
                ->and(array_key_exists('uuid', $errors))->toBeFalse();
        });
    });
});
