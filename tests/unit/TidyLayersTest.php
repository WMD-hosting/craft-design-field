<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\InputOverrides;
use wmd\designfield\helpers\Registry;

final class TidyLayersTest extends TestCase
{
    private function registry(array $inputs): Registry
    {
        return Registry::fromConfig([
            'groups' => ['container' => ['options' => ['boxed' => 'a', 'wide' => 'b', 'narrow' => 'c'], 'default' => 'wide']],
            'profiles' => ['blockCta' => ['container'], 'blockText' => ['container']],
            'inputs' => $inputs,
        ], static fn() => []);
    }

    public function testMakeItTheDefaultChangesOneBlocksDefault(): void
    {
        $registry = $this->registry(['defaults' => ['blockCta' => ['container' => 'boxed']]]);

        self::assertSame('boxed', $registry->groupsFor('blockCta')['container']->default);
        self::assertSame('wide', $registry->groupsFor('blockText')['container']->default, 'other blocks keep theirs');
    }

    public function testHideIsPerBlockAndOnlyVisual(): void
    {
        $registry = $this->registry(['hidden' => ['blockCta' => ['container' => ['narrow', 'wide']]]]);
        $cta = $registry->groupsFor('blockCta')['container'];

        self::assertSame(['narrow'], $cta->hidden, 'wide is the default, so it is never hidden');
        self::assertSame(['boxed', 'wide', 'narrow'], array_keys($cta->options), 'still an option: saved blocks, presets and validation keep working');
        self::assertSame('narrow', $cta->resolveKey('narrow'));
        self::assertSame([], $registry->groupsFor('blockText')['container']->hidden, 'another block that shares the option is untouched');
    }

    public function testHideAndDefaultOnTheSameKeyKeepTheDefault(): void
    {
        $registry = $this->registry(['defaults' => ['blockCta' => ['container' => 'narrow']], 'hidden' => ['blockCta' => ['container' => ['narrow']]]]);
        $cta = $registry->groupsFor('blockCta')['container'];

        self::assertSame('narrow', $cta->default);
        self::assertSame([], $cta->hidden, 'the default always shows');
    }

    public function testUnknownKeysAreIgnoredNotFatal(): void
    {
        $registry = $this->registry(['defaults' => ['blockCta' => ['container' => 'gone']], 'hidden' => ['blockCta' => ['nope' => ['x'], 'container' => ['gone']]]]);

        self::assertSame('wide', $registry->groupsFor('blockCta')['container']->default);
    }

    public function testCleanKeepsOnlyWellFormedEntries(): void
    {
        $clean = InputOverrides::clean(['defaults' => ['blockCta' => ['container' => 'boxed', 'bad' => ['x']]], 'hidden' => ['blockCta' => ['container' => ['narrow', 3, ''], 'tone' => 'x'], 'old' => 'x']]);

        self::assertSame(['blockCta' => ['container' => 'boxed']], $clean['defaults']);
        self::assertSame(['blockCta' => ['container' => ['narrow', '3']]], $clean['hidden']);
    }

    public function testSaveDropsDefaultsAndHiddenChoicesThatPointAtNothing(): void
    {
        $inputs = InputOverrides::clean([
            'defaults' => ['blockCta' => ['container' => 'boxed', 'tone' => 'x'], 'blockGone' => ['container' => 'boxed']],
            'hidden' => ['blockCta' => ['container' => ['narrow', 'gone']], 'blockGone' => ['container' => ['narrow']]],
        ]);
        $pruned = InputOverrides::prune($inputs, $this->registry($inputs));

        self::assertSame(['blockCta' => ['container' => 'boxed']], $pruned['defaults'], 'an option or block that is gone goes');
        self::assertSame(['blockCta' => ['container' => ['narrow']]], $pruned['hidden']);
    }
}
