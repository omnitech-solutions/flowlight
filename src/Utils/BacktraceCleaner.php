<?php

declare(strict_types=1);

namespace Flowlight\Utils;

use Illuminate\Support\Collection;
use Throwable;

/**
 * BacktraceClear — minimal backtrace cleaner inspired by ActiveSupport::BacktraceCleaner.
 *
 * Filters transform lines; silencers remove them.
 *
 * Usage:
 *   $cleaner = new BacktraceClear();
 *   $root = base_path() . DIRECTORY_SEPARATOR;
 *   $cleaner->addFilter(fn(string $line): string => str_replace($root, '', $line))
 *           ->addSilencer(fn(string $line): bool => str_contains($line, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR));
 *   $clean = $cleaner->clean($exception); // -> Collection<int, string>
 */
final class BacktraceCleaner
{
    public const string KIND_SILENT = 'silent';

    public const string KIND_NOISE = 'noise';

    /** @var Collection<int, callable(string): string> */
    private Collection $filters;

    /** @var Collection<int, callable(string): bool> */
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

    /** Add a filter (transforms lines). */
    public function addFilter(callable $filter): self
    {
        $this->filters->push($filter);

        return $this;
    }

    /** Add a silencer (removes matching lines). */
    public function addSilencer(callable $silencer): self
    {
        $this->silencers->push($silencer);

        return $this;
    }

    /** Remove all silencers (show everything). */
    public function removeSilencers(): self
    {
        $this->silencers = collect();

        return $this;
    }

    /** Remove all filters (keep raw frames). */
    public function removeFilters(): self
    {
        $this->filters = collect();

        return $this;
    }

    /**
     * Clean a backtrace.
     *
     * @param  Throwable|string|array<int,string>|Collection<int,string>  $backtrace
     * @return Collection<int,string>
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
     * Clean a single frame; returns null if silenced under :silent mode.
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
     * Static convenience:
     *   BacktraceClear::clean($e, function (BacktraceClear $bc) {...});
     *
     * @param  Throwable|string|array<int,string>|Collection<int,string>  $backtrace
     * @param  null|callable(BacktraceCleaner):void  $configure
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
     * Normalize input into a collection of strings.
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

    private function isSilenced(string $line): bool
    {
        return $this->silencers->contains(
            fn (callable $silencer): bool => (bool) $silencer($line)
        );
    }
}
