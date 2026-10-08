<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\InputOverrides;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\Sections;

final class SectionsTest extends TestCase
{
    private function registry(array $inputs = []): Registry
    {
        return Registry::fromConfig([
            'groups' => [
                'tone' => ['options' => ['a' => 'x', 'b' => 'y'], 'section' => 'Colour'],
                'spacing' => ['options' => ['a' => 'x', 'b' => 'y']],
                'motion' => ['options' => ['a' => 'x', 'b' => 'y']],
            ],
            'groupOverrides' => ['spacing' => ['section' => 'Spacing']],
            'profiles' => [
                'blockCta' => ['variant' => ['options' => ['_default' => 'a', 'split' => 'b']], 'tone', 'spacing', 'motion'],
            ],
            'inputs' => $inputs,
        ], static fn() => []);
    }

    public function testSectionComesFromConfigOverridesAndTheSettingsPage(): void
    {
        $groups = $this->registry(['sections' => ['motion' => 'Motion', 'spacing' => 'Layout']])->groupsFor('blockCta');

        self::assertSame('Colour', $groups['tone']->section);
        self::assertSame('Layout', $groups['spacing']->section, 'the settings page wins over groupOverrides');
        self::assertSame('Motion', $groups['motion']->section);
        self::assertSame('', $groups['variant']->section);
    }

    public function testArrangeKeepsUnsectionedFirstThenSectionsInOrderOfFirstUse(): void
    {
        $groups = $this->registry(['sections' => ['motion' => 'Colour']])->groupsFor('blockCta');

        self::assertSame([
            ['label' => '', 'groups' => ['variant']],
            ['label' => 'Colour', 'groups' => ['tone', 'motion']],
            ['label' => 'Spacing', 'groups' => ['spacing']],
        ], Sections::arrange($groups));
    }

    public function testInlineIsOneListInSectionOrder(): void
    {
        $groups = $this->registry(['sections' => ['motion' => 'Colour']])->groupsFor('blockCta');

        self::assertSame(
            [['label' => '', 'groups' => ['variant', 'tone', 'motion', 'spacing']]],
            Sections::arrange($groups, false),
            'no headings, but related options still sit together',
        );
    }

    public function testNoSectionsAtAllIsOnePlainList(): void
    {
        $groups = Registry::fromConfig(['groups' => ['tone' => ['options' => ['a' => 'x']], 'motion' => ['options' => ['a' => 'x']]]], static fn() => [])->groupsFor(null);

        self::assertSame([['label' => '', 'groups' => ['tone', 'motion']]], Sections::arrange($groups));
    }

    public function testCleanKeepsShortSectionNames(): void
    {
        $clean = InputOverrides::clean(['sections' => ['tone' => '  Colour ', 'spacing' => '', 'motion' => str_repeat('x', 50), 'bad' => ['x']]]);

        self::assertSame(['tone' => 'Colour', 'motion' => str_repeat('x', 40)], $clean['sections']);
    }

    public function testConfigSectionsReachEveryBlocksOwnOptions(): void
    {
        $registry = Registry::fromConfig([
            'groups' => ['tone' => ['options' => ['a' => 'x', 'b' => 'y']]],
            'profiles' => ['blockCta' => ['variant' => ['options' => ['_default' => 'a', 'split' => 'b']], 'tone']],
            'sections' => ['variant' => 'Layout', 'tone' => 'Colour'],
            'inputs' => ['sections' => ['tone' => 'Look']],
        ], static fn() => []);
        $groups = $registry->groupsFor('blockCta');

        self::assertSame(['Layout', 'Look'], [$groups['variant']->section, $groups['tone']->section], 'a block\'s own Layout list too; the settings page wins');
    }
}
