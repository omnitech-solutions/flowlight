<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Utils\BacktraceCleaner;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ErrorInfo — lightweight wrapper around a Throwable with structured fields and cleaned backtraces.
 *
 * Responsibilities
 *  - Normalize exception metadata for logs/UI: type, message, title.
 *  - Provide a standardized error map (field → list of messages), with native support
 *    for Laravel's ValidationException.
 *  - Produce cleaned backtraces via BacktraceCleaner for more readable diagnostics.
 */
final readonly class ErrorInfo
{
    /** The original error that was captured. */
    public Throwable $error;

    /** Fully-qualified exception class name. */
    public string $type;

    /** Human-readable message; may be overridden via constructor. */
    public string $message;

    /** Concise title combining type and original exception message. */
    public string $title;

    /**
     * @param  Throwable  $error  The exception to wrap.
     * @param  ?string  $message  Optional override for the displayed message (defaults to $error->getMessage()).
     */
    public function __construct(Throwable $error, ?string $message = null)
    {
        $this->error = $error;
        $this->type = $error::class;
        $this->message = $message ?? $error->getMessage();
        $this->title = sprintf('%s : %s', $this->type, $error->getMessage());
    }

    /**
     * Render a multi-line summary suitable for logs or diagnostics with a cleaned backtrace.
     */
    public function errorSummary(): string
    {
        $trace = $this->cleanBacktrace()->implode("\n");

        return <<<TEXT
=========== SERVER ERROR FOUND: {$this->title} ===========

FULL STACK TRACE
{$trace}

========================================================
TEXT;
    }

    /**
     * Return a standardized error map.
     *
     * - If the underlying error is a ValidationException, returns its native
     *   array<string, array<int, string>> as a Collection.
     * - Otherwise returns a fallback shape: ['base' => [<message>]].
     *
     * @return Collection<string, array<int,string>>
     */
    public function errors(): Collection
    {
        // ValidationException already returns array<string, array<int, string>>
        if ($this->error instanceof ValidationException) {
            /** @var array<string, array<int, string>> $errors */
            $errors = $this->error->errors();

            /** @var Collection<string, array<int,string>> */
            return collect($errors);
        }

        // Fallback: ensure array-of-strings shape
        $message = $this->message !== '' ? $this->message : 'Unexpected error';

        /** @var Collection<string, array<int,string>> */
        return collect([
            'base' => [$message],
        ]);
    }

    /**
     * Structured error info for logs/UI (truncated cleaned backtrace).
     *
     * Keys:
     *  - type: string (FQCN of the exception)
     *  - message: string (possibly overridden)
     *  - exception: string (title combining type and original message)
     *  - backtrace: string (first 5 lines of the cleaned backtrace, newline-separated)
     *
     * @return Collection<string, mixed>
     */
    public function toCollection(): Collection
    {
        return collect([
            'type' => $this->type,
            'message' => $this->message,
            'exception' => $this->title,
            'backtrace' => $this->cleanBacktrace()->take(5)->implode("\n"),
        ]);
    }

    /**
     * Raw backtrace lines from Throwable::getTraceAsString(), split and normalized.
     *
     * @return Collection<int,string>
     */
    public function backtrace(): Collection
    {
        return collect(preg_split('/\R/u', $this->error->getTraceAsString()) ?: [])
            ->map(static fn ($line): string => (string) $line)
            ->filter(static fn (string $line): bool => $line !== '')
            ->values();
    }

    /**
     * Cleaned backtrace using BacktraceCleaner to remove framework noise and improve readability.
     *
     * @return Collection<int,string>
     */
    public function cleanBacktrace(): Collection
    {
        return BacktraceCleaner::clean($this->error);
    }
}
