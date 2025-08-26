<?php

declare(strict_types=1);

use Flowlight\Utils\BacktraceCleaner;
use Illuminate\Support\Collection;

describe('::cleanBacktrace', function () {
    it('applies default vendor silencer (silent mode)', function () {
        $cleaner = new BacktraceCleaner;

        $frames = [
            '/app/Http/Controllers/HomeController.php:10',
            '/project/vendor/symfony/console/Application.php:123',
            '/app/Services/DoThing.php:42',
            '/project/vendor/laravel/framework/src/Illuminate/Support/Collection.php:789',
        ];

        $result = $cleaner->cleanBacktrace($frames);
        expect($result->all())->toBe([
            '/app/Http/Controllers/HomeController.php:10',
            '/app/Services/DoThing.php:42',
        ]);
    });

    it('can reveal only noise (vendor) with KIND_NOISE', function () {
        $cleaner = new BacktraceCleaner;

        $frames = [
            '/app/Thing.php:1',
            '/vendor/acme/lib/File.php:2',
            '/vendor/acme/lib/Other.php:3',
            '/app/OtherThing.php:4',
        ];

        $onlyNoise = $cleaner->cleanBacktrace($frames, BacktraceCleaner::KIND_NOISE);
        expect($onlyNoise->values()->all())->toBe([
            '/vendor/acme/lib/File.php:2',
            '/vendor/acme/lib/Other.php:3',
        ]);
    });

    it('applies filters before silencers and supports chaining', function () {
        $cleaner = new BacktraceCleaner;

        // Strip a fake project root and normalize slashes
        $projectRoot = '/home/user/myapp/';
        $frames = [
            $projectRoot.'app/Service.php:12',
            $projectRoot.'vendor/lib/Class.php:34',
        ];

        $cleaner
            ->addFilter(fn (string $line): string => str_replace($projectRoot, '', $line))
            ->addFilter(fn (string $line): string => str_replace('\\', '/', $line))
            // Silence anything under vendor after filtering
            ->addSilencer(fn (string $line): bool => str_starts_with($line, 'vendor/'));

        $result = $cleaner->cleanBacktrace($frames);
        expect($result->values()->all())->toBe([
            'app/Service.php:12',
        ]);
    });

    it('removing silencers keeps all frames visible', function () {
        $cleaner = new BacktraceCleaner;

        $frames = [
            '/app/A.php:1',
            '/vendor/pkg/B.php:2',
        ];

        $cleaner->removeSilencers();
        $result = $cleaner->cleanBacktrace($frames);

        expect($result->values()->all())->toBe($frames);
    });

    it('removing filters keeps raw frames', function () {
        $cleaner = new BacktraceCleaner;

        $frames = [
            '/root/app/A.php:1',
            '/root/app/B.php:2',
        ];

        // Add a filter that would otherwise strip "/root/"
        $cleaner->addFilter(fn (string $line): string => str_replace('/root/', '', $line));
        $cleanWithFilter = $cleaner->cleanBacktrace($frames)->values()->all();

        expect($cleanWithFilter)->toBe([
            'app/A.php:1',
            'app/B.php:2',
        ]);

        $cleaner->removeFilters();
        $cleanRaw = $cleaner->cleanBacktrace($frames)->values()->all();

        expect($cleanRaw)->toBe($frames);
    });

    describe('normalization', function () {
        it('accepts array<string>', function () {
            $cleaner = new BacktraceCleaner;
            $frames = [
                '/app/X.php:10',
                '/app/Y.php:20',
            ];
            $result = $cleaner->cleanBacktrace($frames);
            expect($result)->toBeInstanceOf(Collection::class)
                ->and($result->values()->all())->toBe($frames);
        });

        it('accepts Collection<string>', function () {
            $cleaner = new BacktraceCleaner;
            $frames = collect(['/app/A.php:1', '/app/B.php:2']);
            $result = $cleaner->cleanBacktrace($frames);
            expect($result)->toBeInstanceOf(Collection::class)
                ->and($result->values()->all())->toBe(['/app/A.php:1', '/app/B.php:2']);
        });

        it('accepts string with line breaks', function () {
            $cleaner = new BacktraceCleaner;

            $text = "/app/C.php:3\n/vendor/lib/D.php:4\n/app/E.php:5";
            $result = $cleaner->cleanBacktrace($text);

            // vendor line removed by default silencer
            expect($result->values()->all())->toBe(['/app/C.php:3', '/app/E.php:5']);
        });

        it('accepts Throwable by using its getTraceAsString()', function () {
            $traceString = "/app/foo.php:10\n/vendor/pkg/bar.php:20\n/app/baz.php:30";

            $cleaner = new BacktraceCleaner;
            $cleaner->removeSilencers();

            $fromString = $cleaner->cleanBacktrace($traceString)->all();
            $fromArray = $cleaner->cleanBacktrace([
                '/app/foo.php:10',
                '/vendor/pkg/bar.php:20',
                '/app/baz.php:30',
            ])->all();

            expect($fromString)->toBe($fromArray);
        });
    });
});

describe('::cleanFrame', function () {
    it('returns null when frame is silenced in silent mode', function () {
        $cleaner = new BacktraceCleaner;
        $silenced = $cleaner->cleanFrame('/vendor/acme/lib/File.php:123', BacktraceCleaner::KIND_SILENT);
        expect($silenced)->toBeNull();
    });

    it('returns the frame when it is not silenced in silent mode', function () {
        $cleaner = new BacktraceCleaner;
        $visible = $cleaner->cleanFrame('/app/Service.php:77', BacktraceCleaner::KIND_SILENT);
        expect($visible)->toBe('/app/Service.php:77');
    });

    it('returns the frame only if silenced in noise mode', function () {
        $cleaner = new BacktraceCleaner;
        $noise = $cleaner->cleanFrame('/vendor/acme/lib/File.php:123', BacktraceCleaner::KIND_NOISE);
        $notNoise = $cleaner->cleanFrame('/app/Service.php:77', BacktraceCleaner::KIND_NOISE);

        expect($noise)->toBe('/vendor/acme/lib/File.php:123')
            ->and($notNoise)->toBeNull();
    });

    it('applies filters to the frame before checking silencers', function () {
        $cleaner = new BacktraceCleaner;
        $projectRoot = '/home/user/myapp/';

        $cleaner->addFilter(fn (string $line): string => str_replace($projectRoot, '', $line));
        $cleaner->addSilencer(fn (string $line): bool => str_starts_with($line, 'vendor/'));

        // After filter: "vendor/pkg/Foo.php:9" -> silenced
        $frame = $projectRoot.'vendor/pkg/Foo.php:9';
        $result = $cleaner->cleanFrame($frame, BacktraceCleaner::KIND_SILENT);

        expect($result)->toBeNull();
    });
});

describe('::clean (static convenience)', function () {
    it('allows configuration via callback and returns a Collection', function () {
        $frames = [
            '/root/app/A.php:10',
            '/root/vendor/lib/B.php:11',
            '/root/app/C.php:12',
        ];

        $result = BacktraceCleaner::clean($frames, function (BacktraceCleaner $cleaner): void {
            $cleaner
                ->addFilter(fn (string $line): string => str_replace('/root/', '', $line))
                ->addSilencer(fn (string $line): bool => str_starts_with($line, 'vendor/'));
        });

        expect($result)->toBeInstanceOf(Collection::class)
            ->and($result->values()->all())->toBe(['app/A.php:10', 'app/C.php:12']);
    });

    it('can return only noise in KIND_NOISE when configured', function () {
        $frames = [
            '/root/app/A.php:10',
            '/root/vendor/lib/B.php:11',
            '/root/app/C.php:12',
        ];

        $onlyNoise = BacktraceCleaner::clean(
            $frames,
            function (BacktraceCleaner $cleaner): void {
                $cleaner
                    ->addFilter(fn (string $line): string => str_replace('/root/', '', $line))
                    ->addSilencer(fn (string $line): bool => str_starts_with($line, 'vendor/'));
            },
            BacktraceCleaner::KIND_NOISE
        );

        expect($onlyNoise->values()->all())->toBe(['vendor/lib/B.php:11']);
    });
});
