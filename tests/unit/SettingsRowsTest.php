<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\SettingsRows;

final class SettingsRowsTest extends TestCase
{
    private function config(): array
    {
        return SettingsRows::toConfig(
            [
                ['handle' => 'tone', 'label' => 'Section tone', 'tokens' => 'accent', 'auto' => 'Block default', 'input' => '', 'iconsOnly' => ''],
                ['handle' => 'container', 'label' => 'Container', 'default' => 'boxed', 'iconsOnly' => '1'],
                ['handle' => 'variant', 'label' => 'Layout', 'profile' => 'blockTeam', 'input' => 'tiles'],
                ['handle' => 'layoutWidth', 'label' => 'Width', 'variants' => ' cards , '],
                ['handle' => '', 'label' => 'ignored empty row'],
            ],
            [
                ['group' => 'container', 'key' => 'boxed', 'label' => 'Boxed', 'icon' => 'arrows-left-right-to-line', 'value' => 'mx-auto max-w-page'],
                ['group' => 'container', 'key' => 'wide', 'label' => '', 'icon' => '', 'value' => 'w-full'],
                ['group' => 'blockTeam/variant', 'key' => '_default', 'label' => 'Grid'],
                ['group' => 'blockTeam/variant', 'key' => 'cards', 'label' => 'Cards', 'image' => '/t/cards.jpg'],
                ['group' => 'layoutWidth', 'key' => 'full', 'label' => 'Full', 'swatch' => '#112233'],
            ],
            [
                ['profile' => 'blockTeam', 'groups' => 'tone, container=wide'],
                ['profile' => '*', 'groups' => 'tone'],
            ],
        );
    }

    public function testRowsBecomeConfig(): void
    {
        $config = $this->config();

        self::assertSame(['label' => 'Section tone', 'tokens' => 'accent', 'auto' => 'Block default'], $config['groups']['tone']);
        self::assertTrue($config['groups']['container']['iconsOnly']);
        self::assertSame(['label' => 'Wide', 'value' => 'w-full'], $config['groups']['container']['options']['wide']);
        self::assertSame('arrows-left-right-to-line', $config['groups']['container']['options']['boxed']['icon']);
        self::assertArrayNotHasKey('variant', $config['groups'], 'a group with a profile is local');
        self::assertSame(['cards'], $config['groups']['layoutWidth']['variants']);
        self::assertSame('#112233', $config['groups']['layoutWidth']['options']['full']['swatch']);
        self::assertSame('/t/cards.jpg', $config['profiles']['blockTeam']['variant']['options']['cards']['image']);
        self::assertSame('tiles', $config['profiles']['blockTeam']['variant']['input']);
        self::assertSame(['variant', 0, 'container'], array_keys($config['profiles']['blockTeam']), 'unlisted local groups go first');
    }

    public function testConfigBuildsTheSameRegistryAsTheFile(): void
    {
        $registry = Registry::fromConfig($this->config(), static fn() => ['none' => ['label' => 'None', 'bg' => 'bg-bg'], 'primary' => ['bg' => 'bg-primary']]);
        $groups = $registry->groupsFor('blockTeam');

        self::assertSame(['variant', 'tone', 'container'], array_keys($groups));
        self::assertSame('wide', $groups['container']->default);
        self::assertSame(['_default', 'cards'], array_keys($groups['variant']->options));
        self::assertSame('auto', $groups['tone']->default);
        self::assertSame(['tone'], array_keys($registry->groupsFor('blockCta')));
    }

    public function testLocalGroupWithoutProfileRowStillFormsAProfile(): void
    {
        $config = SettingsRows::toConfig(
            [['handle' => 'variant', 'profile' => 'blockCta']],
            [['group' => 'blockCta/variant', 'key' => 'split']],
            [],
        );

        self::assertSame(['variant'], array_keys($config['profiles']['blockCta']));
        self::assertSame('Split', $config['profiles']['blockCta']['variant']['options']['split']['label']);
    }

    public function testPresetRowsGroupByProfileAndName(): void
    {
        $presets = SettingsRows::presets([
            ['profile' => '*', 'name' => 'Dark band', 'group' => 'tone', 'key' => 'inverted'],
            ['profile' => '*', 'name' => 'Dark band', 'group' => 'spacing', 'key' => 'loose'],
            ['profile' => 'blockCta', 'name' => 'Dark band', 'group' => 'tone', 'key' => 'primary'],
            ['profile' => '*', 'name' => '', 'group' => 'tone', 'key' => 'surface'],
            ['profile' => '*', 'name' => 'Half', 'group' => 'tone', 'key' => ' '],
        ]);

        self::assertSame([
            '*' => ['Dark band' => ['tone' => 'inverted', 'spacing' => 'loose']],
            'blockCta' => ['Dark band' => ['tone' => 'primary']],
        ], $presets, 'incomplete rows are skipped');
    }

    public function testOneRowPerPresetRoundTrips(): void
    {
        $rows = [
            ['profile' => '*', 'name' => 'Dark band', 'choices' => 'tone=inverted, spacing = loose'],
            ['profile' => 'blockHeroSlider', 'name' => 'Still hero', 'choices' => 'variant=_default,autoplay=off'],
            ['profile' => '*', 'name' => 'Empty', 'choices' => ''],
        ];
        $presets = SettingsRows::presets($rows);

        self::assertSame([
            '*' => ['Dark band' => ['tone' => 'inverted', 'spacing' => 'loose']],
            'blockHeroSlider' => ['Still hero' => ['variant' => '_default', 'autoplay' => 'off']],
        ], $presets, 'spaces are trimmed; a preset with no choices is skipped');

        self::assertSame([
            ['profile' => '*', 'name' => 'Dark band', 'choices' => 'tone=inverted, spacing=loose'],
            ['profile' => 'blockHeroSlider', 'name' => 'Still hero', 'choices' => 'variant=_default, autoplay=off'],
        ], SettingsRows::presetRows($presets));
    }

    public function testMalformedChoiceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"tone" is not a group=key choice');
        SettingsRows::presets([['profile' => '*', 'name' => 'Oops', 'choices' => 'tone, spacing=loose']]);
    }
}
