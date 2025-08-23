<?php

declare(strict_types=1);

namespace Tests\Utils;

use Flowlight\Action;
use Flowlight\Context;
use Flowlight\Organizer;
use Flowlight\Utils\LangUtils;

describe(LangUtils::class, function () {
    // Local fixtures
    $Base = new class extends Action
    {
        protected function perform(Context $ctx): void {}
    };

    $ChildActionClass = $Base::class;

    $AnonOrganizer = new class extends Organizer
    {
        protected static function steps(): array
        {
            return [];
        }
    };

    $OrganizerClass = $AnonOrganizer::class;

    it('instance vs class-string works (Action)', function () use ($Base) {
        expect(LangUtils::matchesClass($Base, Action::class))->toBeTrue()
            ->and(LangUtils::matchesClass($Base, Organizer::class))->toBeFalse();
    });

    it('class-string vs class-string works (Action <: Action)', function () use ($ChildActionClass) {
        expect(LangUtils::matchesClass($ChildActionClass, Action::class))->toBeTrue()
            ->and(LangUtils::matchesClass($ChildActionClass, Organizer::class))->toBeFalse();
    });

    it('string that is not a class returns false', function () {
        expect(LangUtils::matchesClass('not-a-class', Action::class))->toBeFalse()
            ->and(LangUtils::matchesClass(Action::class, 'not-a-class'))->toBeFalse();
    });

    it('instance vs class-string works (Organizer)', function () use ($AnonOrganizer) {
        expect(LangUtils::matchesClass($AnonOrganizer, Organizer::class))->toBeTrue()
            ->and(LangUtils::matchesClass($AnonOrganizer, Action::class))->toBeFalse();
    });

    it('class-string vs instance works in both directions', function () use ($OrganizerClass, $AnonOrganizer) {
        expect(LangUtils::matchesClass($OrganizerClass, $AnonOrganizer))->toBeTrue()
            ->and(LangUtils::matchesClass($AnonOrganizer, $OrganizerClass))->toBeTrue();
    });

    it('exact class equality short-circuits to true', function () use ($ChildActionClass) {
        expect(LangUtils::matchesClass($ChildActionClass, $ChildActionClass))->toBeTrue();
    });
});
