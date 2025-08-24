<?php

/** @codeCoverageIgnore  */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;

require_once __DIR__.'/../vendor/autoload.php';

/**
 * Minimal Laravel-ish container + helpers to satisfy libraries
 * that call app()/config()/validator() in tests.
 */

// ----------------- Container -----------------
$container = new Container;
Container::setInstance($container);

// ----------------- Config -----------------
$configRepo = new Repository([
    // Spatie laravel-data expects these keys:
    'data' => [
        'max_transformation_depth' => 64,
        'throw_when_max_transformation_depth_reached' => false,
        // Optional defaults used by the package in various places:
        'date_format' => 'Y-m-d\TH:i:sP',
        'casts' => [],
        'transformers' => [],
        'wrap' => null,
        'enabled_casters' => [],
    ],
]);

$container->instance('config', $configRepo);

// ----------------- Translator + Validator (if something asks for validator()) -----------------
$translator = new Translator(new ArrayLoader, 'en');
$validatorFactory = new ValidatorFactory($translator);
$container->instance(ValidatorFactory::class, $validatorFactory);
$container->instance('validator', $validatorFactory);

// ----------------- Global helpers (define only if missing) -----------------
if (! function_exists('app')) {
    /**
     * @template T
     *
     * @param  class-string<T>|string|null  $abstract
     * @param  array<string,mixed>  $parameters
     * @return ($abstract is null ? Container : (T|mixed))
     */
    function app(?string $abstract = null, array $parameters = [])
    {
        $container = Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->make($abstract, $parameters);
    }
}

if (! function_exists('config')) {
    /**
     * Laravel-like config() helper for tests.
     *
     * @param  string|array|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    function config($key = null, $default = null)
    {
        /** @var Repository $repo */
        $repo = app('config');

        if ($key === null) {
            return $repo;
        }

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $repo->set($k, $v);
            }

            return true;
        }

        return $repo->get($key, $default);
    }
}

if (! function_exists('validator')) {
    /**
     * Optional: provide validator() helper if something calls it.
     */
    function validator(array $data = [], array $rules = [], array $messages = [], array $attributes = [])
    {
        /** @var ValidatorFactory $vf */
        $vf = app(ValidatorFactory::class);

        return $vf->make($data, $rules, $messages, $attributes);
    }
}
