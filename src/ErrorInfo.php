<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Utils\BacktraceCleaner;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Wraps a Throwable with structured, typed error information and
 * a cleaned backtrace powered by BacktraceClear.
 */
final readonly class ErrorInfo
{
    public Throwable $error;

    public string $type;

    public string $message;

    public string $title;

    public function __construct(Throwable $error, ?string $message = null)
    {
        $this->error = $error;
        $this->type = $error::class;
        $this->message = $message ?? $error->getMessage();
        $this->title = sprintf('%s : %s', $this->type, $error->getMessage());
    }

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
     * Return a standardized error-map as a Collection.
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
     * Structured error info for logs/UI.
     *
     * Keys: "type" (string), "message" (?string), "exception" (string), "backtrace" (string).
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
     * Raw backtrace lines.
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
     * Cleaned backtrace using BacktraceClear.
     *
     * @return Collection<int,string>
     */
    public function cleanBacktrace(): Collection
    {
        return BacktraceCleaner::clean($this->error);
    }
}
