<?php

declare(strict_types=1);

namespace Tests;

use Flowlight\BaseDto;
use Flowlight\Context;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

describe(BaseDto::class, function () {
    $makeDto = static function (
        ?int $id = null,
        ?string $uuid = null,
        ?string $email = null,
        array $data = [],
        array|Collection|null $rules = null
    ): object {
        /** @var array<string, mixed> $data */
        /** @var array<string, array<int, mixed>>|Collection<string, array<int, mixed>>|null $rules */
        return new class($id, $uuid, $email, $data, $rules) extends BaseDto
        {
            /** @var array<string,mixed> */
            private array $data;

            /** @var Collection<string, array<int, mixed>> */
            private static Collection $rulesOverride;

            /**
             * @param  array<string,mixed>  $data
             * @param  array<string, array<int, mixed>>|Collection<string, array<int, mixed>>|null  $rules
             */
            public function __construct(
                public ?int $id = null,
                public ?string $uuid = null,
                public ?string $email = null,
                array $data = [],
                array|Collection|null $rules = null
            ) {
                $this->data = $data;

                /** @var Collection<string, array<int, mixed>> $default */
                $default = collect([
                    'id' => ['integer', 'min:1'],
                    'uuid' => ['uuid'],
                    'email' => ['required', 'email'],
                ]);

                /** @var Collection<string, array<int, mixed>> $override */
                $override = $rules instanceof Collection
                    ? $rules
                    : (is_array($rules) ? collect($rules) : $default);

                self::$rulesOverride = $override;
            }

            public function toArray(): array
            {
                $scalars = array_filter([
                    'id' => $this->id,
                    'uuid' => $this->uuid,
                    'email' => $this->email,
                ], static fn ($v) => $v !== null);

                return array_merge($this->data, $scalars);
            }

            /** @return Collection<string, array<int, mixed>> */
            protected static function rules(): Collection
            {
                /** @var Collection<string, array<int, mixed>> $r */
                $r = self::$rulesOverride ?? collect();

                return $r;
            }
        };
    };

    describe('validatorForPayload (static)', function () use ($makeDto) {
        it('passes when payload satisfies default rules', function () use ($makeDto) {
            $dto = $makeDto(null, null, 'good@example.com');
            $ctx = Context::makeWithDefaults(); // UPDATE → id/uuid ignored

            /** @var Validator $v */
            $v = $dto::validatorForPayload($dto->toArray(), $ctx);

            expect($v->fails())->toBeFalse()
                ->and($v->errors()->toArray())->toBe([]);
            // Optional: what was accepted
            expect($v->validated())->toHaveKey('email');
        });

        it('fails and exposes errors when invalid (default rules)', function () use ($makeDto) {
            $dto = $makeDto(null, null, ''); // invalid email
            $ctx = Context::makeWithDefaults();

            /** @var Validator $v */
            $v = $dto::validatorForPayload($dto->toArray(), $ctx);

            expect($v->fails())->toBeTrue()
                ->and($v->errors()->toArray())->toHaveKey('email');
        });

        it('applies extra rules shallowly (overwrite base) before validation', function () use ($makeDto) {
            $dto = $makeDto(null, null, 'ab'); // base would fail "email"
            $ctx = Context::makeWithDefaults();

            /** @var array<string, array<int, mixed>> $extra */
            $extra = ['email' => ['string', 'min:2']];

            /** @var Validator $v */
            $v = $dto::validatorForPayload($dto->toArray(), $ctx, $extra);

            expect($v->fails())->toBeFalse()
                ->and($v->errors()->toArray())->toBe([]);
        });

        it('accepts extra rules as Collection equivalently to array', function () use ($makeDto) {
            $dto = $makeDto(null, null, 'ab');
            $ctxA = Context::makeWithDefaults();
            $ctxB = Context::makeWithDefaults();

            /** @var array<string, array<int, mixed>> $extraArray */
            $extraArray = ['email' => ['string', 'min:2']];

            /** @var Collection<string, array<int, mixed>> $extraCol */
            $extraCol = collect(['email' => ['string', 'min:2']]);

            /** @var Validator $vA */
            $vA = $dto::validatorForPayload($dto->toArray(), $ctxA, $extraArray);
            /** @var Validator $vB */
            $vB = $dto::validatorForPayload($dto->toArray(), $ctxB, $extraCol);

            expect($vA->fails())->toBeFalse()
                ->and($vB->fails())->toBeFalse()
                ->and($vA->errors()->toArray())->toBe($vB->errors()->toArray())
                ->and($vA->validated())->toBe($vB->validated());
        });

        it('keeps id/uuid rules under CREATE and validates them', function () use ($makeDto) {
            $dto = $makeDto(id: 0, uuid: 'not-a-uuid', email: 'ok@example.com');
            $ctx = Context::makeWithDefaults()->markCreateOperation(); // CREATE → keep id/uuid

            /** @var Validator $v */
            $v = $dto::validatorForPayload($dto->toArray(), $ctx);

            $errs = $v->errors()->toArray();
            expect($v->fails())->toBeTrue()
                ->and(array_key_exists('id', $errs))->toBeTrue()
                ->and(array_key_exists('uuid', $errs))->toBeTrue();
        });

        it('drops id/uuid rules under UPDATE and does not validate them', function () use ($makeDto) {
            $dto = $makeDto(id: 0, uuid: 'not-a-uuid', email: 'ok@example.com');
            $ctx = Context::makeWithDefaults(); // UPDATE (default) → drop id/uuid

            /** @var Validator $v */
            $v = $dto::validatorForPayload($dto->toArray(), $ctx);

            $errs = $v->errors()->toArray();
            expect($v->fails())->toBeFalse()
                ->and(array_key_exists('id', $errs))->toBeFalse()
                ->and(array_key_exists('uuid', $errs))->toBeFalse();
        });

        it('proves default rules exist via behavior (missing email fails)', function () use ($makeDto) {
            $dto = $makeDto(); // no email
            $ctx = Context::makeWithDefaults();

            /** @var Validator $v */
            $v = $dto::validatorForPayload($dto->toArray(), $ctx);

            expect($v->fails())->toBeTrue()
                ->and($v->errors()->toArray())->toHaveKey('email');
        });

        it('proves rule override via behavior (custom name rule)', function () use ($makeDto) {
            $dto = $makeDto(
                data: ['name' => ''],
                rules: ['name' => ['required', 'string']]
            );

            $ctx = Context::makeWithDefaults();

            /** @var Validator $v */
            $v = $dto::validatorForPayload($dto->toArray(), $ctx);

            expect($v->fails())->toBeTrue()
                ->and($v->errors()->toArray())->toHaveKey('name');
        });

        it('respects CREATE vs UPDATE filtering with explicit override rules', function () use ($makeDto) {
            $dto = $makeDto(
                data: ['id' => null, 'uuid' => null],
                rules: [
                    'id' => ['required', 'integer'],
                    'uuid' => ['required', 'string'],
                ]
            );

            // UPDATE (default) → drop id/uuid rules → PASS
            $ctxUpdate = Context::makeWithDefaults(['id' => null, 'uuid' => null])->markUpdateOperation();
            /** @var Validator $vU */
            $vU = $dto::validatorForPayload($dto->toArray(), $ctxUpdate);
            expect($vU->fails())->toBeFalse();

            // CREATE → keep id/uuid rules → FAIL
            $ctxCreate = Context::makeWithDefaults(['id' => null, 'uuid' => null])->markCreateOperation();
            /** @var Validator $vC */
            $vC = $dto::validatorForPayload($dto->toArray(), $ctxCreate);
            expect($vC->fails())->toBeTrue();
        });
    });
});
