<?php

declare(strict_types=1);

namespace Flowlight\Utils;

use Illuminate\Support\Collection;
use Throwable;

/**
 * BacktraceCleaner — minimal backtrace cleaner inspired by ActiveSupport::BacktraceCleaner.
 *
 * Concepts
 *  - Filters: transform each trace line (e.g., strip project root prefixes).
 *  - Silencers: predicate functions that remove lines (e.g., vendor frames).
 *
 * Usage
 *  ```php
 *  $cleaner = new BacktraceCleaner();
 *  $root = base_path() . DIRECTORY_SEPARATOR;
 *  $cleaner
 *    ->addFilter(fn(string $line): string => str_replace($root, '', $line))
 *    ->addSilencer(fn(string $line): bool => str_contains($line, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR));
 *  $clean = $cleaner->cleanBacktrace($exception); // Collection<int, string>
 *  ```
 *
 * Static convenience
 *  ```php
 *  $clean = BacktraceCleaner::clean($exception, function (BacktraceCleaner $bc): void {
 *      $bc->removeSilencers(); // show everything
 *  });
 *  ```
 *
 * Kinds
 *  - KIND_SILENT: hide silenced lines (default).
 *  - KIND_NOISE: show only silenced lines (useful for debugging silencers).
 *  - Any other value: show all lines after filters.
 *
 * @phpstan-type BacktraceInput Throwable|string|array<int,string>|Collection<int,string>
 * @phpstan-type Filter callable(string):string
 * @phpstan-type Silencer callable(string):bool
 */
final class BacktraceCleaner
{
    /** Show non-silenced frames (default). */
    public const string KIND_SILENT = 'silent';

    /** Show only silenced frames (inverse view). */
    public const string KIND_NOISE = 'noise';

    /** @var Collection<int, callable(string): string> Filters applied in order to each line. */
    private Collection $filters;

    /** @var Collection<int, callable(string): bool> Silencers evaluated against each (filtered) line. */
    private Collection $silencers;

    public function __construct()
    {
        $this->filters = collect();
        $this->silencers = collect();

        // Default: silence vendor paths
        $this->addSilencer(
            fn (string $line): bool => str_contains($line, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
        );
    }

    /**
     * Add a filter (line transformer).
     *
     * @param  callable(string):string  $filter
     * @return $this
     */
    public function addFilter(callable $filter): self
    {
        $this->filters->push($filter);

        return $this;
    }

    /**
     * Add a silencer (line predicate to remove matches).
     *
     * @param  callable(string):bool  $silencer
     * @return $this
     */
    public function addSilencer(callable $silencer): self
    {
        $this->silencers->push($silencer);

        return $this;
    }

    /**
     * Remove all silencers (show everything).
     *
     * @return $this
     */
    public function removeSilencers(): self
    {
        $this->silencers = collect();

        return $this;
    }

    /**
     * Remove all filters (keep raw frames).
     *
     * @return $this
     */
    public function removeFilters(): self
    {
        $this->filters = collect();

        return $this;
    }

    /**
     * Clean a backtrace-like input.
     *
     * @param  Throwable|string|array<int,string>|Collection<int,string>  $backtrace  Exception or list/blob of frames
     * @param  string  $kind  One of self::KIND_* (see class doc)
     * @return Collection<int,string> Filtered (and possibly silenced) lines
     */
    public function cleanBacktrace(Throwable|string|array|Collection $backtrace, string $kind = self::KIND_SILENT): Collection
    {
        /** @var Collection<int,string> $lines */
        $lines = $this->normalize($backtrace)->map(
            function ($line): string {
                $text = (string) $line;

                /** @var string $reduced */
                $reduced = $this->filters->reduce(
                    fn (string $carry, callable $filter): string => (string) $filter($carry),
                    $text
                );

                return $reduced;
            }
        );

        return match ($kind) {
            self::KIND_SILENT => $lines
                ->reject(fn (string $line): bool => $this->isSilenced($line))
                ->values(),
            self::KIND_NOISE => $lines
                ->filter(fn (string $line): bool => $this->isSilenced($line))
                ->values(),
            default => $lines->values(),
        };
    }

    /**
     * Clean a single frame; returns null if silenced under KIND_SILENT.
     *
     * @param  string  $kind  One of self::KIND_*; non-matching values return the reduced frame
     */
    public function cleanFrame(string $frame, string $kind = self::KIND_SILENT): ?string
    {
        /** @var string $reduced */
        $reduced = $this->filters->reduce(
            fn (string $carry, callable $filter): string => (string) $filter($carry),
            $frame
        );

        return match ($kind) {
            self::KIND_SILENT => $this->isSilenced($reduced) ? null : $reduced,
            self::KIND_NOISE => $this->isSilenced($reduced) ? $reduced : null,
            default => $reduced,
        };
    }

    /**
     * Static convenience wrapper.
     *
     * @param  Throwable|string|array<int,string>|Collection<int,string>  $backtrace  Exception or frames
     * @param  null|callable(BacktraceCleaner):void  $configure  Optional customizer (filters/silencers)
     * @param  string  $kind  One of self::KIND_* (default silent)
     * @return Collection<int,string>
     */
    public static function clean(Throwable|string|array|Collection $backtrace, ?callable $configure = null, string $kind = self::KIND_SILENT): Collection
    {
        $instance = new self;
        if ($configure !== null) {
            $configure($instance);
        }

        return $instance->cleanBacktrace($backtrace, $kind);
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /**
     * Normalize input into a Collection of non-empty strings.
     *
     * Accepted forms:
     *  - Throwable  → Throwable::getTraceAsString() exploded by newlines
     *  - string     → split on newlines
     *  - array      → cast each to string
     *  - Collection → cast each to string
     *
     * @param  Throwable|string|array<int,string>|Collection<int,string>  $input
     * @return Collection<int,string>
     */
    private function normalize(Throwable|string|array|Collection $input): Collection
    {
        if ($input instanceof Throwable) {
            $input = $input->getTraceAsString();
        }

        if (is_string($input)) {
            $input = preg_split('/\R/u', $input) ?: [];
        }

        if ($input instanceof Collection) {
            return $input
                ->map(fn ($line): string => (string) $line)
                ->filter(fn (string $line): bool => $line !== '')
                ->values();
        }

        return collect($input)
            ->map(fn ($line): string => (string) $line)
            ->filter(fn (string $line): bool => $line !== '')
            ->values();
    }

    /**
     * Determine if a line should be silenced by any registered silencer.
     */
    private function isSilenced(string $line): bool
    {
        return $this->silencers->contains(
            fn (callable $silencer): bool => (bool) $silencer($line)
        );
    }
}
