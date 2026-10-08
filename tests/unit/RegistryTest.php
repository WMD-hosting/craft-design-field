<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Registry;
use wmd\designfield\models\Group;

final class RegistryTest extends TestCase
{
    private function registry(): Registry
    {
        $tokens = [
            'accent' => [
                'none' => ['label' => 'Transparent', 'bg' => 'bg-transparent', 'text' => 'text-fg'],
                'primary' => ['label' => 'Brand primary', 'bg' => 'bg-primary', 'text' => 'text-primary-fg'],
            ],
            'spacing' => ['tight' => 'section-y-tight', 'standard' => 'section-y', 'loose' => 'section-y-spacious'],
        ];

        return Registry::fromConfig([
            'groups' => [
                'tone' => ['label' => 'Tone', 'tokens' => 'accent', 'default' => 'none'],
                'spacing' => ['tokens' => 'spacing', 'default' => 'standard', 'aliases' => ['spacious' => 'loose'], 'instructions' => 'Room above and below.'],
                'align' => ['options' => ['left' => 'text-left', 'center' => 'text-center']],
            ],
            'profiles' => [
                'blockCta' => ['tone', 'spacing' => 'loose'],
                '*' => ['spacing'],
            ],
        ], static fn(string $name) => $tokens[$name]);
    }

    public function testStringAndObjectOptionsNormalize(): void
    {
        $groups = $this->registry()->groups;

        self::assertSame(['value' => 'section-y-tight'], $groups['spacing']->options['tight']['parts']);
        self::assertSame('Tight', $groups['spacing']->options['tight']['label']);
        self::assertSame(['bg' => 'bg-primary', 'text' => 'text-primary-fg'], $groups['tone']->options['primary']['parts']);
        self::assertSame('Brand primary', $groups['tone']->options['primary']['label']);
        self::assertSame('Spacing', $groups['spacing']->label);
        self::assertSame('left', $groups['align']->default);
    }

    public function testProfileOverridesDefaultAndFallsBack(): void
    {
        $registry = $this->registry();

        self::assertSame(['tone', 'spacing'], array_keys($registry->groupsFor('blockCta')));
        self::assertSame('loose', $registry->groupsFor('blockCta')['spacing']->default);
        self::assertSame('Room above and below.', $registry->groupsFor('blockCta')['spacing']->instructions, 'default overrides keep instructions');
        self::assertSame(['spacing'], array_keys($registry->groupsFor('unknownType')));
        self::assertSame('standard', $registry->groupsFor(null)['spacing']->default);
    }

    public function testMostSpecificCandidateWins(): void
    {
        $registry = Registry::fromConfig([
            'groups' => ['spacing' => ['options' => ['a' => 'py-4', 'b' => 'py-8']]],
            'profiles' => [
                'heroBuilder:blockTeam' => ['spacing' => 'b'],
                'blockTeam' => ['spacing'],
            ],
        ], static fn() => []);

        self::assertSame('b', $registry->groupsFor(['heroBuilder:blockTeam', 'blockTeam'])['spacing']->default);
        self::assertSame('a', $registry->groupsFor(['pageBuilder:blockTeam', 'blockTeam'])['spacing']->default);
        self::assertSame($registry->groups, $registry->groupsFor([]), 'no candidate and no * falls back to every group');
    }

    public function testValueResolvesKeysAliasesAndDefaults(): void
    {
        $value = $this->registry()->value(['tone' => 'primary', 'spacing' => 'spacious'], 'blockCta');

        self::assertSame('bg-primary', $value->tone->get('bg'));
        self::assertSame('loose', $value->spacing->key);
        self::assertSame('bg-primary text-primary-fg section-y-spacious', (string)$value);
        self::assertSame('section-y-spacious', $value->classes('spacing'));
        self::assertSame([], $value->invalid());
    }

    public function testUnknownKeyFallsBackAndIsReported(): void
    {
        $value = $this->registry()->value(['tone' => 'neon'], 'blockCta');

        self::assertSame('none', $value->tone->key);
        self::assertTrue($value->tone->isDefault);
        self::assertSame(['tone' => 'neon'], $value->invalid());
    }

    public function testGroupsOutsideProfileResolveToDefaultAndSurviveSave(): void
    {
        $value = $this->registry()->value(['align' => 'center', 'tone' => 'primary'], 'blockCta');

        self::assertFalse($value->has('align'));
        self::assertSame('left', $value->align->key, 'outside the profile the stored key is ignored');
        self::assertSame('', (string)$value->nonexistent);
        self::assertTrue(isset($value->nonexistent), 'Twig reads unknown groups as empty tokens instead of throwing');
        self::assertFalse(isset($value->classes), 'method names stay methods for Twig');
        self::assertSame('fallback', $value->nonexistent->keyOr('fallback'));
        self::assertSame(['align' => 'center', 'tone' => 'primary', 'spacing' => 'loose'], $value->toArray());
    }

    public function testInvalidConfigThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Registry::fromConfig(['groups' => ['tone' => ['options' => ['a' => 'x'], 'default' => 'b']]], static fn() => []);
    }

    public function testUnknownProfileGroupThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Registry::fromConfig(['groups' => [], 'profiles' => ['x' => ['tone']]], static fn() => []);
    }

    public function testReservedHandleThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Registry::fromConfig(['groups' => ['classes' => ['options' => ['a' => 'x']]]], static fn() => []);
    }

    public function testProfileLocalGroupShadowsNothingShared(): void
    {
        $registry = Registry::fromConfig([
            'groups' => ['spacing' => ['options' => ['a' => 'py-4', 'b' => 'py-8']]],
            'profiles' => [
                'blockTeam' => ['variant' => ['options' => ['_default' => ['label' => 'Grid'], 'cards' => ['label' => 'Cards']]], 'spacing'],
                'blockCta' => ['variant' => ['options' => ['_default' => ['label' => 'Centered'], 'split' => ['label' => 'Split']]]],
            ],
        ], static fn() => []);

        self::assertSame(['_default', 'cards'], array_keys($registry->groupsFor('blockTeam')['variant']->options));
        self::assertSame(['_default', 'split'], array_keys($registry->groupsFor('blockCta')['variant']->options));
        self::assertSame('cards', $registry->value(['variant' => 'cards'], 'blockTeam')->variant->key);
        self::assertSame('_default', $registry->value(['variant' => 'cards'], 'blockCta')->variant->key);
        self::assertArrayNotHasKey('variant', $registry->groups);
    }

    public function testAutoOptionLetsTemplateDecide(): void
    {
        $group = Group::fromConfig('shape', ['auto' => 'Variant default'], ['round' => ['label' => 'Round'], 'square' => ['label' => 'Square']]);

        self::assertSame(Group::AUTO, $group->default);
        self::assertSame('Variant default', $group->options[Group::AUTO]['label']);
        self::assertTrue($group->token(null)->isAuto());
        self::assertSame('round', $group->token(null)->keyOr('round'));
        self::assertSame('square', $group->token('square')->keyOr('round'));
        self::assertSame('', (string)$group->token('square'), 'label-only options carry no classes');
        self::assertSame('fallback', $group->token(null)->classOr('fallback'), 'auto uses the layout fallback');
        self::assertSame('', $group->token('square')->classOr('fallback'), 'an explicit choice without classes stays empty');
    }

    public function testIconsOnlyFitsMoreButtons(): void
    {
        $options = [];
        foreach (['round' => 'circle', 'square' => 'square', 'portrait' => 'rectangle-vertical', 'landscape' => 'rectangle-wide', 'book' => 'book'] as $key => $icon) {
            $options[$key] = ['label' => ucfirst($key) . ' shape', 'icon' => $icon];
        }

        $group = Group::fromConfig('shape', ['iconsOnly' => true, 'auto' => ['label' => 'Auto', 'icon' => 'wand-magic-sparkles']], $options);

        self::assertSame(Group::INPUT_BUTTONS, $group->input, 'six icons fit where six labels would not');
        self::assertSame('wand-magic-sparkles', $group->options[Group::AUTO]['icon']);
        self::assertSame(Group::INPUT_SELECT, Group::fromConfig('shape', [], $options)->input);
    }

    public function testLegacyReadsAreLenient(): void
    {
        $value = $this->registry()->legacyValue(['tone' => 'neon', 'align' => 'center', 'spacing' => ''], 'blockCta', 'blockCta');

        self::assertSame('neon', $value->tone->key, 'a legacy or mock key outside the options passes through');
        self::assertSame('center', $value->align->key, 'a group outside the profile is still read');
        self::assertSame('loose', $value->spacing->key, 'an empty value keeps the profile default');
        self::assertSame('left', $this->registry()->value(['align' => 'center'], 'blockCta')->align->key, 'strict values ignore groups outside the profile');
    }

    public function testProfileOverridesAndConditions(): void
    {
        $registry = Registry::fromConfig([
            'groups' => [
                'layoutWidth' => ['options' => ['contained' => ['label' => 'Contained'], 'full' => ['label' => 'Full']]],
                'tone' => ['input' => 'swatches', 'options' => ['primary' => ['label' => 'Primary', 'swatch' => '#4f46e5', 'bg' => 'bg-primary']]],
            ],
            'profiles' => [
                'blockTeam' => [
                    'variant' => ['options' => ['_default' => ['label' => 'Grid'], 'vertical-images' => ['label' => 'Vertical', 'image' => '/t/v.png']], 'input' => 'tiles'],
                    'layoutWidth' => ['variants' => ['vertical-images'], 'default' => 'full'],
                    'tone',
                ],
            ],
        ], static fn() => []);

        $groups = $registry->groupsFor('blockTeam');
        self::assertSame('full', $groups['layoutWidth']->default, 'an array without options overrides the shared group');
        self::assertSame(['variant' => ['vertical-images']], $groups['layoutWidth']->showIf);
        self::assertFalse($groups['layoutWidth']->appliesTo(['variant' => '_default']));
        self::assertTrue($groups['layoutWidth']->appliesTo(['variant' => 'vertical-images']));
        self::assertSame([], $registry->groups['layoutWidth']->showIf, 'the shared group itself is unchanged');
        self::assertSame('tiles', $groups['variant']->input);
        self::assertSame('/t/v.png', $groups['variant']->options['vertical-images']['image']);
        self::assertSame('#4f46e5', $groups['tone']->options['primary']['swatch']);
        self::assertSame(['bg' => 'bg-primary'], $groups['tone']->options['primary']['parts'], 'swatch is meta, not a class part');
    }

    public function testOptionMetaDecoratesOptionsFromElsewhere(): void
    {
        $group = Group::fromConfig('tone', ['input' => 'swatches', 'optionMeta' => ['primary' => ['swatch' => '#4f46e5'], 'missing' => ['swatch' => '#000']]], ['primary' => ['bg' => 'bg-primary']]);

        self::assertSame('#4f46e5', $group->options['primary']['swatch']);
        self::assertArrayNotHasKey('missing', $group->options, 'meta never adds options');
        self::assertSame(['bg' => 'bg-primary'], $group->options['primary']['parts']);
    }

    public function testGroupAndProfileOverrides(): void
    {
        $registry = Registry::fromConfig([
            'groups' => ['columns' => ['options' => ['2' => ['label' => '2'], '4' => ['label' => '4']], 'input' => 'slider']],
            'profiles' => ['blockTeam' => ['columns'], 'blockCta' => ['columns']],
            'groupOverrides' => ['columns' => ['label' => 'Cols']],
            'profileOverrides' => ['blockTeam' => ['columns' => ['input' => 'buttons', 'default' => '4']]],
        ], static fn() => []);

        self::assertSame('Cols', $registry->groups['columns']->label, 'groupOverrides apply everywhere');
        self::assertSame('buttons', $registry->groupsFor('blockTeam')['columns']->input, 'profileOverrides apply to one block');
        self::assertSame('4', $registry->groupsFor('blockTeam')['columns']->default);
        self::assertSame('slider', $registry->groupsFor('blockCta')['columns']->input);
    }

    public function testProfileOverrideForAGroupTheProfileLacksThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Registry::fromConfig([
            'groups' => ['columns' => ['options' => ['2' => '']]],
            'profiles' => ['blockTeam' => []],
            'profileOverrides' => ['blockTeam' => ['columns' => ['input' => 'buttons']]],
        ], static fn() => []);
    }

    public function testToggleNeedsExactlyTwoOptionsAndSampleIsMeta(): void
    {
        $two = Group::fromConfig('width', ['input' => 'toggle'], ['contained' => ['label' => 'Contained'], 'full' => ['label' => 'Full bleed']]);
        self::assertSame(Group::INPUT_TOGGLE, $two->input);

        $withAuto = Group::fromConfig('width', ['input' => 'toggle', 'auto' => true], ['contained' => ['label' => 'Contained'], 'full' => ['label' => 'Full']]);
        self::assertNotSame(Group::INPUT_TOGGLE, $withAuto->input, 'three options (with auto) cannot be a toggle');

        $sized = Group::fromConfig('headingSize', ['optionMeta' => ['lg' => ['sample' => '1.25rem']]], ['sm' => 'text-sm', 'lg' => 'text-lg']);
        self::assertSame('1.25rem', $sized->options['lg']['sample']);
        self::assertSame(['value' => 'text-lg'], $sized->options['lg']['parts']);
    }

    public function testPresetsKeepOnlyWhatTheBlockHas(): void
    {
        $tokens = ['spacing' => ['tight' => 'py-8', 'standard' => 'py-16', 'loose' => 'py-24']];
        $registry = Registry::fromConfig([
            'groups' => [
                'spacing' => ['tokens' => 'spacing', 'aliases' => ['spacious' => 'loose']],
                'align' => ['options' => ['left' => 'text-left', 'center' => 'text-center']],
            ],
            'profiles' => ['blockCta' => ['spacing', 'align'], '*' => ['spacing']],
            'presets' => [
                'blockCta' => ['Centered' => ['align' => 'center', 'spacing' => 'loose'], 'Airy' => ['spacing' => 'tight']],
                '*' => ['Airy' => ['spacing' => 'spacious'], 'Compact' => ['spacing' => 'tight', 'align' => 'left'], 'Broken' => ['align' => 'middle']],
            ],
        ], static fn(string $name) => $tokens[$name]);

        $cta = $registry->presetsFor(['pageBuilder:blockCta', 'blockCta'], $registry->groupsFor('blockCta'));
        self::assertSame(['Centered', 'Airy', 'Compact'], array_column($cta, 'label'), 'block presets first; a `*` preset with no valid choice is dropped');
        self::assertSame(['spacing' => 'tight'], $cta[1]['values'], 'the block\'s own preset wins over a `*` one with the same label');
        self::assertSame(['spacing' => 'tight', 'align' => 'left'], $cta[2]['values']);

        $other = $registry->presetsFor('blockText', $registry->groupsFor('blockText'));
        self::assertSame(['Airy', 'Compact'], array_column($other, 'label'));
        self::assertSame(['spacing' => 'loose'], $other[0]['values'], 'aliases resolve');
        self::assertSame(['spacing' => 'tight'], $other[1]['values'], 'groups the block does not show are left out');
    }

    public function testInvalidPresetChoicesAreReported(): void
    {
        $registry = Registry::fromConfig([
            'groups' => ['align' => ['options' => ['left' => 'text-left', 'center' => 'text-center']]],
            'profiles' => ['blockCta' => ['align', 'variant' => ['options' => ['_default' => ['label' => 'Grid']]]]],
            'presets' => ['*' => ['Ok' => ['align' => 'center', 'variant' => '_default'], 'Typos' => ['align' => 'middle', 'colour' => 'red']]],
        ], static fn() => []);

        self::assertSame([
            'Preset "Typos" (*) sets "align" to "middle", which is not one of its options.',
            'Preset "Typos" (*) sets "colour", which is not a design group.',
        ], $registry->invalidPresetChoices(), 'profile-local groups count as known');
    }

    public function testMalformedPresetThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Registry::fromConfig(['groups' => [], 'profiles' => [], 'presets' => ['*' => ['Empty' => []]]], static fn() => []);
    }

    public function testManyOptionsDefaultToSelect(): void
    {
        $group = Group::fromConfig('cols', [], array_fill_keys(['1', '2', '3', '4', '5', '6'], 'x'));

        self::assertSame(Group::INPUT_SELECT, $group->input);
    }
}
