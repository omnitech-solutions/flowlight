<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Utils\LangUtils;
use Illuminate\Support\Collection;
use Throwable;

abstract class ValidatorAction extends Action
{
    /**
     * Subclasses MUST provide a DTO class-string extending BaseDto.
     *
     * @return class-string<BaseDto>
     */
    abstract protected static function dtoClass(): string;

    /**
     * Optional mapper before validation.
     * Return:
     *  - null (no-op)
     *  - callable(mixed $data): array|Collection
     *  - class-string with static mapFrom(mixed $data): array|Collection
     */
    protected static function beforeValidationMapper(): callable|string|null
    {
        return null;
    }

    /**
     * Optional mapper after validation.
     * Return:
     *  - null (no-op)
     *  - callable(mixed $data): array|Collection
     *  - class-string with static mapFrom(mixed $data): array|Collection
     */
    protected static function afterValidationMapper(): callable|string|null
    {
        return null;
    }

    /**
     * @throws Throwable
     */
    public static function executeForCreateOperation(Context $ctx): Context
    {
        return static::execute($ctx->markCreateOperation());
    }

    /**
     * @throws Throwable
     */
    public static function executeForUpdateOperation(Context $ctx): Context
    {
        return static::execute($ctx->markUpdateOperation());
    }

    /** {@inheritDoc} */
    protected function perform(Context $context): void
    {
        $dtoClass = static::dtoClass();

        if (! LangUtils::matchesClass($dtoClass, BaseDto::class)) {
            throw new \RuntimeException('dtoClass() must return a class-string<BaseDto>.');
        }

        $mappedInput = self::applyMapperExpectMap(static::beforeValidationMapper(), $context->inputArray());

        /** @var class-string<BaseDto> $dtoClass */
        $validator = $dtoClass::validatorForPayload($mappedInput, $context);

        if ($validator->fails()) {
            /** @var array<string, array<int, string>> $errors */
            $errors = $validator->errors()->toArray();
            $context->withErrors($errors)->markFailed();

            return;
        }

        $validated = $validator->validated();

        $base = $mappedInput instanceof Collection ? $mappedInput->toArray() : $mappedInput;
        $allParams = array_replace($base, $validated);
        $mappedParams = self::applyMapperExpectMap(static::afterValidationMapper(), $allParams);

        $context->withParams($mappedParams);
    }

    /**
     * Apply a mapper and REQUIRE it to return array|Collection.
     *
     * @param  callable|string|null  $mapper  callable(mixed): array|Collection OR class-string::mapFrom(mixed): array|Collection
     * @return array<string,mixed>|Collection<string,mixed>
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
