<?php

declare(strict_types=1);

namespace Flowlight\Exceptions;

use Flowlight\Context;
use RuntimeException;

/**
 * Exception representing a **failed Flowlight Context**.
 *
 * This error is typically used when a Flowlight pipeline (Organizer/Action)
 * has already recorded failure details into the {@see Context} (errors, snapshots,
 * internal error info, aborted flag, etc.), and the caller wants to **escalate**
 * that failure as an exception (e.g., by calling `withErrorHandler(..., rethrow: true)`).
 *
 * Key points:
 * - The associated {@see Context} is exposed via {@see getContext()} for
 *   programmatic access to errors and diagnostics.
 * - The exception message should remain **human-readable**, while structured
 *   details live on the Context (e.g., `errors()`, `internalOnly()->get('errorInfo')`).
 * - The `$context` property is **readonly** to ensure exception immutability.
 *
 * Example:
 * ```php
 * try {
 *     Organizer::withErrorHandler($ctx, static function (Context $c): void {
 *         // ... pipeline work that may record errors or throw ...
 *     }, rethrow: true);
 * } catch (ContextFailedError $e) {
 *     $ctx = $e->getContext();
 *     $errors = $ctx->errorsArray();
 *     // respond/log using structured context data
 * }
 * ```
 *
 * @api
 */
final class ContextFailedError extends RuntimeException
{
    /**
     * The failed Flowlight context containing structured error details and diagnostics.
     *
     * - Use {@see Context::errorsArray()} or {@see Context::errors()} for user-facing errors.
     * - Use {@see Context::internalOnly()} to access internal diagnostics (e.g., `errorInfo`).
     * - Use {@see Context::aborted()} to check failure state.
     */
    public readonly Context $context;

    /**
     * Create a new {@see ContextFailedError}.
     *
     * The provided {@see Context} should already reflect failure (e.g., `aborted() === true`)
     * with errors and internal diagnostics recorded by the pipeline’s error handling.
     *
     * @param  Context  $context  The failed context capturing errors and diagnostics.
     * @param  string  $message  Human-readable summary (defaults to "Flowlight context failed.").
     * @param  int  $code  Optional error code for upstream compatibility.
     * @param  \Throwable|null  $previous  Optional previous exception for chaining.
     */
    public function __construct(
        Context $context,
        string $message = 'Flowlight context failed.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get the failed {@see Context} associated with this exception.
     *
     * Consumers should prefer reading structured details from the Context instead of
     * parsing the exception message (e.g., `errorsArray()`, `internalOnly()->get('errorInfo')`).
     *
     * @return Context The context that was in a failed/aborted state when this exception was raised.
     */
    public function getContext(): Context
    {
        return $this->context;
    }
}
