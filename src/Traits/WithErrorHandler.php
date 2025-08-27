<?php

declare(strict_types=1);

namespace Flowlight\Traits;

use Flowlight\Context;
use Throwable;

/**
 * Execute a block with unified error handling into Context.
 *
 * - Accepts either a callable block (normal path) OR a Throwable (proxy path used by tests)
 * - On exception: record detailed error info, add a 'base' error with the message,
 *   snapshot last failed context, and abort the Context.
 */
trait WithErrorHandler
{
    /**
     * Wrap a block, convert thrown exceptions to context errors + abort.
     * Optionally rethrow for callers that want to escalate.
     *
     * @param  bool  $rethrow  default=false keeps current behavior
     *
     * @throws Throwable
     */
    protected static function withErrorHandler(
        Context $ctx,
        callable|Throwable $blockOrThrowable,
        bool $rethrow = false
    ): void {
        $block = $blockOrThrowable instanceof Throwable
            ? static function (Context $c) use ($blockOrThrowable): void {
                throw $blockOrThrowable;
            }
        : $blockOrThrowable;

        try {
            $block($ctx);
        } catch (Throwable $e) {
            self::captureExceptionIntoContext($ctx, $e);

            if ($rethrow) {
                throw $e;
            }
        }
    }

    private static function captureExceptionIntoContext(Context $ctx, Throwable $e): void
    {
        $ctx->recordRaisedError($e);
        $ctx->withErrors(['base' => ['An unexpected error occurred.']]);
        $ctx->setLastFailedContext($ctx);
        $ctx->abort();
    }
}
