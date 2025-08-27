<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Enums\ContextOperation;
use Flowlight\Utils\ObjectUtils;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * BaseData for DTO validation without requiring Data instantiation.
 *
 * Purpose
 *  - Provide per-DTO validation rules via static rules().
 *  - Build Laravel Validator instances for arbitrary payloads (array/Collection) without
 *    constructing a Spatie Data object—useful for tests and lightweight validation.
 *
 * Operations & Rule Handling
 *  - rules(): base rules for the DTO.
 *  - rulesForOperation(extraRules, dottedOmitRules, operation):
 *      * Merges base rules with $extraRules (right-biased on duplicate keys).
 *      * Applies dotted-key omissions via ObjectUtils::dottedOmit().
 *      * For CREATE operations, excludes identifiers ('id', 'uuid').
 *  - validatorForPayload(payload, extraRules, dottedOmitRules, operation):
 *      * Computes effective rules (respecting operation) and returns a Validator.
 *
 * Container Fallback
 *  - If a ValidatorFactory cannot be resolved from the container (e.g., non-Laravel context),
 *    a local ValidatorFactory is created with a minimal Translator (en).
 */
abstract class BaseData extends Data
{
    /**
     * Base validation rules keyed by attribute.
     *
     * @return Collection<string, array<int, mixed>> Map of attribute => list of validation rules
     */
    protected static function rules(): Collection
    {
        return collect();
    }

    /**
     * Build a Validator for an explicit payload using this DTO’s rules (plus optional extras).
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $payload
     * @param  array<string, array<int, mixed>>|Collection<string, array<int, mixed>>  $extraRules
     * @param  array<int,string>|Collection<int,string>  $dottedOmitRules
     * @param  string  $operation  One of ContextOperation::*->value
     */
    public static function validatorForPayload(
        array|Collection $payload,
        array|Collection $extraRules = [],
        array|Collection $dottedOmitRules = [],
        string $operation = ContextOperation::UPDATE->value
    ): Validator {
        $allRules = static::rulesForOperation($extraRules, $dottedOmitRules, $operation);

        return static::buildValidator($allRules, $payload);
    }

    /**
     * Compute effective rules for a given operation by merging base + extra, applying dotted omits,
     * and filtering identifiers for CREATE.
     *
     * @param  array<string, array<int, mixed>>|Collection<string, array<int, mixed>>  $extraRules
     * @param  array<int,string>|Collection<int,string>  $dottedOmitRules
     * @param  string  $operation  One of ContextOperation::*->value
     * @return Collection<string, array<int, mixed>>
     */
    protected static function rulesForOperation(
        array|Collection $extraRules,
        array|Collection $dottedOmitRules = [],
        string $operation = ContextOperation::UPDATE->value
    ): Collection {
        /** @var Collection<string, array<int, mixed>> $base */
        $base = static::rules();

        /** @var Collection<string, array<int, mixed>> $extra */
        $extra = $extraRules instanceof Collection ? $extraRules : collect($extraRules);

        /** @var Collection<string, array<int, mixed>> $merged */
        $merged = $base->merge($extra);

        // Normalize omit keys to array<int,string> for ObjectUtils::dottedOmit
        /** @var array<int, mixed> $omitRaw */
        $omitRaw = $dottedOmitRules instanceof Collection ? $dottedOmitRules->all() : (array) $dottedOmitRules;

        /** @var array<int,string> $omitKeys */
        $omitKeys = array_values(
            array_map(
                static fn (mixed $k): string => (string) $k,
                array_filter($omitRaw, static fn (mixed $k): bool => is_scalar($k))
            )
        );

        if (! empty($omitKeys)) {
            $merged = collect(
                ObjectUtils::dottedOmit($merged->toArray(), $omitKeys)
            );
        }

        $op = strtolower(trim($operation));
        if ($op === strtolower(ContextOperation::CREATE->value)) {
            // also exclude identifiers on CREATE
            $merged = $merged->except(['id', 'uuid']);
        }

        return $merged;
    }

    /**
     * Build a Laravel Validator instance from a normalized ruleset and payload.
     *
     * @param  Collection<string, array<int, mixed>>  $rules
     * @param  array<string,mixed>|Collection<string,mixed>  $payload
     */
    protected static function buildValidator(
        Collection $rules,
        array|Collection $payload
    ): Validator {
        $factory = self::resolveValidatorFactory();

        /** @var array<string, mixed> $asArray */
        $asArray = $payload instanceof Collection ? $payload->toArray() : $payload;

        /** @var array<string, array<int, mixed>> $ruleArray */
        $ruleArray = $rules->toArray();

        return $factory->make($asArray, $ruleArray);
    }

    /**
     * Resolve a ValidatorFactory from the IoC container, or create a minimal standalone factory.
     *
     * Resolution order:
     *  1) Container::getInstance()->make(ValidatorFactory::class)
     *  2) Fallback: new ValidatorFactory(new Translator(new ArrayLoader, 'en'))
     */
    private static function resolveValidatorFactory(): ValidatorFactory
    {
        try {
            /** @var ValidatorFactory $factory */
            $factory = Container::getInstance()->make(ValidatorFactory::class);

            return $factory;
        } catch (Throwable) {
            // Fallback for non-Laravel/standalone contexts (keeps tests and CLI tools self-contained)
            return new ValidatorFactory(new Translator(new ArrayLoader, 'en'));
        }
    }
}
