<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Enums\ContextOperation;
use Illuminate\Support\Collection;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Data;

abstract class BaseDto extends Data
{
    /**
     * Base rules for this DTO.
     *
     * @return Collection<string, array<int, mixed>>
     */
    protected static function rules(): Collection
    {
        return collect();
    }

    /**
     * Validate an explicit payload against this DTO’s rules.
     * This does NOT instantiate the DTO (avoids Spatie config/container).
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $payload
     * @param  array<string, array<int, mixed>>|Collection<string, array<int, mixed>>  $extraRules
     */
    public static function validatorForPayload(
        array|Collection $payload,
        Context $ctx,
        array|Collection $extraRules = []
    ): Validator {
        $allRules = static::buildRulesForContext($ctx, $extraRules);

        return static::buildValidator($allRules, $payload);
    }

    /**
     * Instance helper used by your tests to inspect merge/except behavior.
     *
     * @param  array<string, array<int, mixed>>|Collection<string, array<int, mixed>>  $extraRules
     * @return Collection<string, array<int, mixed>>
     */
    protected static function buildRulesForContext(Context $ctx, array|Collection $extraRules): Collection
    {
        /** @var Collection<string, array<int, mixed>> $base */
        $base = static::rules();
        /** @var Collection<string, array<int, mixed>> $extra */
        $extra = $extraRules instanceof Collection ? $extraRules : collect($extraRules);

        /** @var Collection<string, array<int, mixed>> $merged */
        $merged = $base->merge($extra);

        if ($ctx->operation() !== ContextOperation::CREATE) {
            return $merged->except(['id', 'uuid']);
        }

        return $merged;
    }

    /**
     * Instance helper to run validation into Context for instance payloads (if needed).
     *
     * @param  Collection<string, array<int, mixed>>  $rules
     * @param  array<string,mixed>|Collection<string,mixed>  $payload
     */
    protected static function buildValidator(
        Collection $rules,
        array|Collection $payload
    ): Validator {
        $validatorFactory = new ValidatorFactory(new Translator(new ArrayLoader, 'en'));

        /** @var array<string, mixed> $asArray */
        $asArray = $payload instanceof Collection ? $payload->toArray() : $payload;

        /** @var array<string, array<int, mixed>> $ruleArray */
        $ruleArray = $rules->toArray();

        return $validatorFactory->make($asArray, $ruleArray);
    }
}
