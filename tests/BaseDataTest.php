<?php

declare(strict_types=1);

namespace Tests;

use Flowlight\BaseData;
use Flowlight\Enums\ContextOperation;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

$makeDto = static function (
    ?int $id = null,
    ?string $uuid = null,
    ?string $email = null,
    array $data = [],
    array|Collection|null $rules = null
): object {
    /** @var array<string, mixed> $data */
    /** @var array<string, array<int, mixed>>|Collection<string, array<int, mixed>>|null $rules */
    return new class($id, $uuid, $email, $data, $rules) extends BaseData
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

//
// ::rules
//
describe('::rules', function () {
    it('uses BaseData::rules() default (empty) when subclass does not override', function () {
        $Bare = new class extends BaseData
        {
            /** @return Collection<string, array<int, mixed>> */
            public static function rulesProxy(): Collection
            {
                return parent::rules();
            }
        };

        $rules = $Bare::rulesProxy();

        expect($rules)->toBeInstanceOf(Collection::class)
            ->and($rules->isEmpty())->toBeTrue();
    });

    it('returns empty base rules by default', function () {
        $Bare = new class extends BaseData
        {
            /** @return Collection<string, array<int, mixed>> */
            public static function rulesProxy(): Collection
            {
                return parent::rules();
            }
        };

        $rules = $Bare::rulesProxy();

        expect($rules)->toBeInstanceOf(Collection::class)
            ->and($rules->isEmpty())->toBeTrue();
    });
});

//
// ::buildValidator
//
describe('::buildValidator', function () {
    it('accepts payload as Collection equivalently to array', function () {
        $Proxy = new class extends BaseData
        {
            /**
             * @param  Collection<string, array<int, mixed>>  $rules
             * @param  array<string, mixed>|Collection<string, mixed>  $payload
             */
            public static function buildValidatorProxy(Collection $rules, array|Collection $payload): Validator
            {
                return parent::buildValidator($rules, $payload);
            }
        };

        /** @var Collection<string, array<int, mixed>> $rules */
        $rules = collect(['email' => ['required', 'email']]);

        $arrayPayload = ['email' => 'ok@example.com'];
        $collectionPayload = collect($arrayPayload);

        $vA = $Proxy::buildValidatorProxy($rules, $arrayPayload);
        $vB = $Proxy::buildValidatorProxy($rules, $collectionPayload);

        expect($vA->fails())->toBe($vB->fails())
            ->and($vA->errors()->toArray())->toBe($vB->errors()->toArray())
            ->and($vA->validated())->toBe($vB->validated());
    });

    it('falls back to local ValidatorFactory when Container is unavailable', function () {
        // Force Container::getInstance() to be null so the try{} path throws and fallback is used
        $prev = Container::getInstance();
        Container::setInstance(null);

        try {
            $Bare = new class extends BaseData {};
            /** @var Validator $v */
            $v = $Bare::validatorForPayload(
                payload: ['any' => 'thing'],  // no rules => ok
                operation: ContextOperation::UPDATE->value
            );

            expect($v)->toBeInstanceOf(Validator::class)
                ->and($v->fails())->toBeFalse()
                ->and($v->errors()->toArray())->toBe([]);
        } finally {
            // Restore to avoid side effects on other tests
            Container::setInstance($prev);
        }
    });

});

//
// ::validatorForPayload
//
describe('::validatorForPayload', function () use ($makeDto) {
    it('passes when payload satisfies default rules', function () use ($makeDto) {
        $dto = $makeDto(null, null, 'good@example.com');

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: ContextOperation::UPDATE->value
        );

        expect($v->fails())->toBeFalse()
            ->and($v->errors()->toArray())->toBe([])
            ->and($v->validated())->toHaveKey('email');
    });

    it('fails and exposes errors when invalid (default rules)', function () use ($makeDto) {
        $dto = $makeDto(null, null, ''); // invalid email

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: ContextOperation::UPDATE->value
        );

        expect($v->fails())->toBeTrue()
            ->and($v->errors()->toArray())->toHaveKey('email');
    });

    it('applies extra rules shallowly (overwrite base) before validation', function () use ($makeDto) {
        $dto = $makeDto(null, null, 'ab'); // base would fail "email"

        /** @var array<string, array<int, mixed>> $extra */
        $extra = ['email' => ['string', 'min:2']];

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $dto->toArray(),
            extraRules: $extra,
            operation: ContextOperation::UPDATE->value
        );

        expect($v->fails())->toBeFalse()
            ->and($v->errors()->toArray())->toBe([]);
    });

    it('accepts extra rules as Collection equivalently to array', function () use ($makeDto) {
        $dto = $makeDto(null, null, 'ab');

        /** @var array<string, array<int, mixed>> $extraArray */
        $extraArray = ['email' => ['string', 'min:2']];

        /** @var Collection<string, array<int, mixed>> $extraCol */
        $extraCol = collect(['email' => ['string', 'min:2']]);

        /** @var Validator $vA */
        $vA = $dto::validatorForPayload(
            payload: $dto->toArray(),
            extraRules: $extraArray,
            operation: ContextOperation::UPDATE->value
        );

        /** @var Validator $vB */
        $vB = $dto::validatorForPayload(
            payload: $dto->toArray(),
            extraRules: $extraCol,
            operation: ContextOperation::UPDATE->value
        );

        expect($vA->fails())->toBeFalse()
            ->and($vB->fails())->toBeFalse()
            ->and($vA->errors()->toArray())->toBe($vB->errors()->toArray())
            ->and($vA->validated())->toBe($vB->validated());
    });

    it('proves default rules exist via behavior (missing email fails)', function () use ($makeDto) {
        $dto = $makeDto(); // no email

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: ContextOperation::UPDATE->value
        );

        expect($v->fails())->toBeTrue()
            ->and($v->errors()->toArray())->toHaveKey('email');
    });

    it('proves rule override via behavior (custom name rule)', function () use ($makeDto) {
        $dto = $makeDto(
            data: ['name' => ''],
            rules: ['name' => ['required', 'string']]
        );

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: ContextOperation::UPDATE->value
        );

        expect($v->fails())->toBeTrue()
            ->and($v->errors()->toArray())->toHaveKey('name');
    });

    it('omits nested dotted rules (array input) so missing dotted fields do not fail', function () use ($makeDto) {
        // Override rules to ONLY test dotted keys (no email/id/uuid noise)
        $dto = $makeDto(
            data: ['profile' => []],
            rules: [
                'profile' => ['array'],
                'profile.name' => ['required', 'string'],
                'profile.address.city' => ['required', 'string'],
            ]
        );

        // No dotted fields present in payload; omit them via dottedOmitRules
        $payload = ['profile' => []];

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $payload,
            dottedOmitRules: ['profile.name', 'profile.address.city'],
            operation: ContextOperation::UPDATE->value
        );

        expect($v->fails())->toBeFalse()
            ->and($v->errors()->toArray())->toBe([]);
    });

    it('omits scalar rule by key (e.g., uuid) under UPDATE so invalid/missing values pass', function () use ($makeDto) {
        // Rules: uuid must be valid, name required
        $dto = $makeDto(
            data: ['name' => 'Ok'],
            rules: [
                'uuid' => ['required', 'uuid'],
                'name' => ['required', 'string'],
            ]
        );

        // Payload has name OK, but uuid missing/invalid; dottedOmitRules removes 'uuid' rule
        $payload = ['name' => 'Ok'];

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $payload,
            dottedOmitRules: ['uuid'],
            operation: ContextOperation::UPDATE->value
        );

        expect($v->fails())->toBeFalse()
            ->and($v->errors()->toArray())->toBe([]);
    });

    it('accepts dottedOmitRules as a Collection equally to array', function () use ($makeDto) {
        $dto = $makeDto(
            data: ['profile' => []],
            rules: [
                'profile' => ['array'],
                'profile.name' => ['required', 'string'],
                'profile.address.city' => ['required', 'string'],
            ]
        );

        $payload = ['profile' => []];

        $omitAsArray = ['profile.name', 'profile.address.city'];
        $omitAsCollection = collect($omitAsArray);

        /** @var Validator $vA */
        $vA = $dto::validatorForPayload(
            payload: $payload,
            dottedOmitRules: $omitAsArray,
            operation: ContextOperation::UPDATE->value
        );

        /** @var Validator $vB */
        $vB = $dto::validatorForPayload(
            payload: $payload,
            extraRules: [],
            dottedOmitRules: $omitAsCollection,
            operation: ContextOperation::UPDATE->value
        );

        expect($vA->fails())->toBeFalse()
            ->and($vB->fails())->toBeFalse()
            ->and($vA->errors()->toArray())->toBe($vB->errors()->toArray());
    });

    it('applies dotted omits under CREATE as well (independent of op-specific filtering)', function () use ($makeDto) {
        // NOTE: This test asserts that dotted omits are applied even for CREATE.
        // If your BaseData::rulesForOperation returns early on CREATE, update it to apply
        // dotted omits before returning.
        $dto = $makeDto(
            data: ['meta' => []],
            rules: [
                // pretend meta.flag is required on CREATE, but we want to omit it
                'meta.flag' => ['required', 'boolean'],
            ]
        );

        $payload = ['meta' => []];

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $payload,
            dottedOmitRules: ['meta.flag'],
            operation: ContextOperation::CREATE->value
        );

        expect($v->errors()->toArray())->toBe([])
            ->and($v->fails())->toBeFalse();
    });
});

//
// ::rulesForOperation (indirect via ::validatorForPayload)
//
describe('::rulesForOperation', function () use ($makeDto) {
    it('drops id/uuid rules under CREATE (does not validate them)', function () use ($makeDto) {
        $dto = $makeDto(id: 0, uuid: 'not-a-uuid', email: 'ok@example.com');

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: ContextOperation::CREATE->value
        );

        $errs = $v->errors()->toArray();
        expect($v->fails())->toBeFalse()
            ->and(array_key_exists('id', $errs))->toBeFalse()
            ->and(array_key_exists('uuid', $errs))->toBeFalse();
    });

    it('keeps id/uuid rules under UPDATE (validates them)', function () use ($makeDto) {
        $dto = $makeDto(id: 0, uuid: 'not-a-uuid', email: 'ok@example.com');

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: ContextOperation::UPDATE->value
        );

        $errs = $v->errors()->toArray();
        expect($v->fails())->toBeTrue()
            ->and(array_key_exists('id', $errs))->toBeTrue()
            ->and(array_key_exists('uuid', $errs))->toBeTrue();
    });

    it('respects CREATE vs UPDATE filtering with explicit override rules', function () use ($makeDto) {
        $dto = $makeDto(
            data: ['id' => null, 'uuid' => null],
            rules: [
                'id' => ['required', 'integer'],
                'uuid' => ['required', 'string'],
            ]
        );

        // UPDATE → keep id/uuid rules → FAIL
        /** @var Validator $vU */
        $vU = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: ContextOperation::UPDATE->value
        );
        expect($vU->fails())->toBeTrue();

        // CREATE → drop id/uuid rules → PASS
        /** @var Validator $vC */
        $vC = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: ContextOperation::CREATE->value
        );
        expect($vC->fails())->toBeFalse();
    });

    it('normalizes operation: "CREATE" and " create " → drops id/uuid rules', function () use ($makeDto) {
        $dto = $makeDto(id: 0, uuid: 'not-a-uuid', email: 'ok@example.com');

        foreach (['CREATE', ' create '] as $op) {
            /** @var Validator $v */
            $v = $dto::validatorForPayload(
                payload: $dto->toArray(),
                operation: $op
            );

            $errs = $v->errors()->toArray();
            expect($v->fails())->toBeFalse()
                ->and(array_key_exists('id', $errs))->toBeFalse()
                ->and(array_key_exists('uuid', $errs))->toBeFalse();
        }
    });

    it('normalizes operation: mixed-case "UpDaTe" → keeps id/uuid rules', function () use ($makeDto) {
        $dto = $makeDto(id: 0, uuid: 'not-a-uuid', email: 'ok@example.com');

        /** @var Validator $v */
        $v = $dto::validatorForPayload(
            payload: $dto->toArray(),
            operation: 'UpDaTe'
        );

        $errs = $v->errors()->toArray();
        expect($v->fails())->toBeTrue()
            ->and(array_key_exists('id', $errs))->toBeTrue()
            ->and(array_key_exists('uuid', $errs))->toBeTrue();
    });
});
