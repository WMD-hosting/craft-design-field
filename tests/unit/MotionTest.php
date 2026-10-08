<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Motion;
use wmd\designfield\models\Group;

final class MotionTest extends TestCase
{
    public function testKeysMatchAPresetOrAnAlias(): void
    {
        self::assertSame('fade-up', Motion::match('fade-up'));
        self::assertSame('from-left', Motion::match('fromLeft'), 'camelCase keys too');
        self::assertSame('zoom-in', Motion::match('zoom'));
        self::assertSame('fade-through', Motion::match('fadeOutIn'));
        self::assertNull(Motion::match('none'));
        self::assertNull(Motion::match('three-column'));
    }

    public function testAnExplicitMotionWinsAndMustBeAPreset(): void
    {
        $group = Group::fromConfig('effect', [], [
            'fade' => ['label' => 'Crossfade', 'motion' => 'crossfade'],
            'slide' => ['label' => 'Slide'],
            'odd' => ['label' => 'Odd', 'motion' => 'no-such-motion'],
        ]);

        self::assertSame('crossfade', $group->options['fade']['motion']);
        self::assertSame('slide', $group->options['slide']['motion'], 'matched by its key');
        self::assertNull($group->options['odd']['motion'], 'an unknown name is ignored');
    }

    public function testTransitionsShowTwoSlides(): void
    {
        self::assertSame(Motion::TRANSITION, Motion::kind('cube'));
        self::assertSame(Motion::ENTRANCE, Motion::kind('fade-up'));
    }

    public function testMotionTilesNeedTwoMatchesOrOneNamedMotion(): void
    {
        $entrance = Group::fromConfig('motion', [], ['none' => 'x', 'fade-up' => 'y', 'zoom' => 'z']);
        $layouts = Group::fromConfig('variant', [], ['_default' => 'a', 'slide' => 'b', 'grid' => 'c']);
        $named = Group::fromConfig('reveal', [], ['soft' => ['value' => 'a', 'motion' => 'blur-in'], 'hard' => 'b']);

        self::assertContains('motion', $entrance->supportedInputs());
        self::assertNotContains('motion', $layouts->supportedInputs(), 'one "slide" key in a layout list is not motion');
        self::assertContains('motion', $named->supportedInputs());
    }
}
