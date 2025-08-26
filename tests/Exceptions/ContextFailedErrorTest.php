<?php

declare(strict_types=1);

use Flowlight\Context;
use Flowlight\Exceptions\ContextFailedError;

//
// ::__construct
//
describe('::__construct', function () {
    it('initializes message, code, previous and stores context', function () {
        $ctx = Context::makeWithDefaults(['foo' => 'bar']);
        $prev = new RuntimeException('prev');

        $e = new ContextFailedError($ctx, 'boom', 42, $prev);

        expect($e)->toBeInstanceOf(ContextFailedError::class)
            ->and($e->getMessage())->toBe('boom')
            ->and($e->getCode())->toBe(42)
            ->and($e->getPrevious())->toBe($prev)
            ->and($e->getContext())->toBe($ctx)
            ->and($e->context)->toBe($ctx); // readonly property is set
    });

    it('uses default message when not provided', function () {
        $ctx = Context::makeWithDefaults();

        $e = new ContextFailedError($ctx);

        expect($e->getMessage())->toBe('Flowlight context failed.');
    });
});

//
// getContext
//
describe('getContext', function () {
    it('returns the same Context instance passed to the constructor', function () {
        $ctx = Context::makeWithDefaults(['k' => 'v']);

        $e = new ContextFailedError($ctx);

        expect($e->getContext())->toBe($ctx);
    });
});
