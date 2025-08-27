<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Utils\LangUtils;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ValidatorAction — Action with schema-driven validation and optional mappers.
 *
 * Responsibilities
 *  - Require a DTO class (extending BaseData) via dataClass().
 *  - Optionally transform input before validation (beforeValidationMapper()).
 *  - Validate using BaseData::validatorForPayload() honoring Context operation (create/update)
 *    and extraRules from $context->extraRules().
 *  - On failure: merge validator errors into Context (withErrors) and return (no throw).
 *  - On success: merge validated + (possibly mapped) params into Context via withParams().
 *
 * Mappers
 *  - beforeValidationMapper():   maps raw Context input → array|Collection (pre-validation shape).
 *  - afterValidationMapper():    maps merged (raw+validated) params → array|Collection (post-validation shape).
 *  - Each mapper may be:
 *      * null (no-op; input/result must already be array|Collection),
 *      * callable(mixed $data): array|Collection,
 *      * class-string with static mapFrom(mixed $data): array|Collection.
 *
 * Operations
 *  - Use executeForCreateOperation()/executeForUpdateOperation() helpers to set meta['operation'] first,
 *    or ensure Context already carries the intended operation.
 *
 * Exceptions
 *  - perform() does not throw for validation failures—it records errors on the Context.
 *  - Misconfiguration (bad dataClass/mapper types) raises RuntimeException.
 *  - Action::execute() will still surface unexpected Throwables per its lifecycle.
 */
abstract class ValidatorAction extends Action
{
    /**
     * Subclasses MUST provide a DTO class-string extending BaseData.
     *
     * @return class-string<BaseData>
     */
    abstract protected static function dataClass(): string;

    /**
     * Optional mapper invoked BEFORE validation to reshape input.
     *
     * @phpstan-return (callable(mixed):mixed)|class-string|null
     */
    protected static function beforeValidationMapper(): callable|string|null
    {
        return null;
    }

    /**
     * Optional mapper invoked AFTER validation to finalize params written to Context.
     *
     * @phpstan-return (callable(mixed):mixed)|class-string|null
     */
    protected static function afterValidationMapper(): callable|string|null
    {
        return null;
    }

    /**
     * Execute with meta['operation']='create'.
     *
     * @throws Throwable Per Action::execute semantics if an unexpected exception occurs.
     */
    public static function executeForCreateOperation(Context $ctx): Context
    {
        return static::execute($ctx->markCreateOperation());
    }

    /**
     * Execute with meta['operation']='update'.
     *
     * @throws Throwable Per Action::execute semantics if an unexpected exception occurs.
     */
    public static function executeForUpdateOperation(Context $ctx): Context
    {
        return static::execute($ctx->markUpdateOperation());
    }

    /**
     * Perform validation and parameter mapping.
     *
     * Flow:
     *  1) Resolve dataClass() and assert it extends BaseData.
     *  2) Apply beforeValidationMapper() to Context input (must yield array|Collection).
     *  3) Build validator via BaseData::validatorForPayload(payload, extraRules, operation).
     *  4) If fails → withErrors($errors) and return (no exception).
     *  5) If passes → merge validated with pre-validation base and apply afterValidationMapper();
     *     then write to Context via withParams().
     *
     * Validation failures:
     *  - Recorded as Context errors (ctx->withErrors()); caller can check ctx->failure()/errors().
     *
     * {@inheritDoc}
     *
     * @throws ValidationException Only if surfaced by the underlying validator in unexpected ways.
     */
    protected function perform(Context $context): void
    {
        $dataClass = static::dataClass();

        if (! LangUtils::matchesClass($dataClass, BaseData::class)) {
            throw new \RuntimeException('dataClass() must return a class-string<BaseDto>.');
        }

        $mappedInput = self::applyMapperExpectMap(static::beforeValidationMapper(), $context->inputArray());

        /** @var class-string<BaseData> $dataClass */
        $validator = $dataClass::validatorForPayload(
            payload: $mappedInput,
            /** @phpstan-ignore-next-line */
            extraRules: $context->extraRules(),
            dottedOmitRules: $context->dottedOmitRulesArray(),
            operation: $context->operation(),
        );

        if ($validator->fails()) {
            /** @var array<string, array<int, string>> $errors */
            $errors = $validator->errors()->toArray();
            $context->withErrors($errors);

            return;
        }

        $validated = $validator->validated();

        $base = $mappedInput instanceof Collection ? $mappedInput->toArray() : $mappedInput;
        $allParams = array_replace($base, $validated);
        $mappedParams = self::applyMapperExpectMap(static::afterValidationMapper(), $allParams);

        $context->withParams($mappedParams);
    }

    /**
     * Apply a mapper and REQUIRE an array|Collection result.
     *
     * Accepted forms for $mapper:
     *  - null → identity (input must already be array|Collection),
     *  - callable(mixed): array|Collection,
     *  - class-string with static mapFrom(mixed): array|Collection.
     *
     * @return array<string,mixed>|Collection<string,mixed>
     *
     * @throws \RuntimeException If mapper is invalid or result is not array|Collection.
     */
    private static function applyMapperExpectMap(callable|string|null $mapper, mixed $data): array|Collection
    {
        // Identity: only accept array|Collection when no mapper provided
        if ($mapper === null) {
            if (\is_array($data) || $data instanceof Collection) {
                /** @var array<string,mixed>|Collection<string,mixed> */
                return $data;
            }
            throw new \RuntimeException('No-op mapper requires input to be array|Collection.');
        }

        // Callable mapper
        if (\is_callable($mapper)) {
            $out = $mapper($data);
            if (\is_array($out) || $out instanceof Collection) {
                /** @var array<string,mixed>|Collection<string,mixed> */
                return $out;
            }
            throw new \RuntimeException('Callable mapper must return array|Collection.');
        }

        // Class-string mapper with static mapFrom()
        if (! \class_exists($mapper)) {
            throw new \RuntimeException('Mapper must be a callable or a class-string with static mapFrom(mixed $data).');
        }
        if (! \method_exists($mapper, 'mapFrom')) {
            throw new \RuntimeException('Class-string mapper must define static mapFrom(mixed $data): array|Collection.');
        }

        /** @var mixed $out */
        $out = $mapper::mapFrom($data);
        if (\is_array($out) || $out instanceof Collection) {
            /** @var array<string,mixed>|Collection<string,mixed> */
            return $out;
        }

        throw new \RuntimeException('Class-string mapper mapFrom() must return array|Collection.');
    }
}
