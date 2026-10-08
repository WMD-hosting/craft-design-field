<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\InputOverrides;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\StyleChoices;

final class InputOverridesTest extends TestCase
{
    private function registry(array $inputs = [], array $extra = []): Registry
    {
        return Registry::fromConfig(array_replace_recursive([
            'groups' => [
                'columns' => ['options' => ['1' => 'a', '2' => 'b', '3' => 'c', '4' => 'd'], 'input' => 'buttons'],
                'tone' => ['options' => ['light' => 'x', 'dark' => 'y', 'brand' => 'z']],
                'container' => ['options' => ['boxed' => ['icon' => 'a', 'value' => 'x'], 'full' => ['icon' => 'b', 'value' => 'y']], 'iconsOnly' => true],
            ],
            'profiles' => [
                'blockTeam' => ['columns', 'tone', 'container'],
                'blockCta' => ['tone'],
                '*' => ['tone'],
            ],
            'inputs' => $inputs,
        ], $extra), static fn() => []);
    }

    public function testCleanKeepsOnlyKnownStylesAndTwoKeys(): void
    {
        $clean = InputOverrides::clean([
            'groups' => ['columns' => ['input' => 'select', 'label' => 'X'], 'tone' => ['input' => 'nope']],
            'profiles' => ['blockTeam' => ['columns' => ['input' => 'buttons', 'iconsOnly' => '1']]],
            '*' => ['tone' => ['input' => 'select']],
        ]);

        self::assertSame([
            'styles' => [],
            'groups' => ['columns' => ['input' => 'select', 'iconsOnly' => false]],
            'profiles' => ['blockTeam' => ['columns' => ['input' => 'buttons', 'iconsOnly' => true]]],
            'meta' => [],
            'sections' => [],
            'defaults' => [],
            'hidden' => [],
        ], $clean);
        self::assertSame(['styles' => [], 'groups' => [], 'profiles' => [], 'meta' => [], 'sections' => [], 'defaults' => [], 'hidden' => []], InputOverrides::clean('garbage'));
    }

    public function testEverywhereThenPerBlockAndCpBeatsConfig(): void
    {
        $registry = $this->registry(
            ['groups' => ['columns' => ['input' => 'select']], 'profiles' => ['blockTeam' => ['tone' => ['input' => 'slider']]]],
            ['profileOverrides' => ['blockTeam' => ['columns' => ['input' => 'slider']]]],
        );

        self::assertSame('select', $registry->groupsFor('blockTeam')['columns']->input, 'CP everywhere beats config profileOverrides');
        self::assertSame('slider', $registry->groupsFor('blockTeam')['tone']->input, 'CP per block applies');
        self::assertSame('buttons', $registry->groupsFor('blockCta')['tone']->input, 'other blocks keep their style');
        self::assertSame('slider', $registry->configInputs['blockTeam']['columns']['input'], 'config style before the CP layers');
    }

    public function testPerBlockBeatsEverywhere(): void
    {
        $registry = $this->registry([
            'groups' => ['tone' => ['input' => 'select']],
            'profiles' => ['blockCta' => ['tone' => ['input' => 'buttons', 'iconsOnly' => false]]],
        ]);

        self::assertSame('buttons', $registry->groupsFor('blockCta')['tone']->input);
        self::assertSame('select', $registry->groupsFor('blockTeam')['tone']->input);
    }

    public function testUnsupportedEverywhereStyleIsSkippedPerGroupAndNoted(): void
    {
        $registry = $this->registry(['groups' => ['columns' => ['input' => 'toggle']]]);

        self::assertSame('buttons', $registry->groupsFor('blockTeam')['columns']->input);
        self::assertSame('unsupported', $registry->inputNotes[0]['kind']);
        self::assertSame('groups', $registry->inputNotes[0]['scope']);
    }

    public function testHiddenConfigStyleIsNotedOnlyWhenConfigSetOne(): void
    {
        $registry = $this->registry(['groups' => ['columns' => ['input' => 'select'], 'tone' => ['input' => 'select']]]);
        $hidden = array_values(array_filter($registry->inputNotes, static fn($n) => $n['kind'] === 'hidden'));

        self::assertCount(1, $hidden, 'tone had no config input, so nothing is hidden there');
        self::assertSame(['columns', 'buttons'], [$hidden[0]['group'], $hidden[0]['config']]);
    }

    public function testPruneDropsStaleEntriesButKeepsEverywhereSkips(): void
    {
        $inputs = InputOverrides::clean([
            'groups' => ['columns' => ['input' => 'toggle'], 'gone' => ['input' => 'select']],
            'profiles' => [
                'blockCta' => ['columns' => ['input' => 'select'], 'tone' => ['input' => 'toggle']],
                'blockDeleted' => ['tone' => ['input' => 'select']],
                'blockTeam' => ['tone' => ['input' => 'select']],
            ],
        ]);
        $pruned = InputOverrides::prune($inputs, $this->registry($inputs));

        self::assertSame(['columns' => ['input' => 'toggle', 'iconsOnly' => false]], $pruned['groups']);
        self::assertSame(['blockTeam' => ['tone' => ['input' => 'select', 'iconsOnly' => false]]], $pruned['profiles']);
    }

    public function testSharedGroupsTakeTheEverywhereLayer(): void
    {
        $registry = Registry::fromConfig([
            'groups' => ['tone' => ['options' => ['a' => 'x', 'b' => 'y', 'c' => 'z']]],
            'inputs' => ['groups' => ['tone' => ['input' => 'select']]],
        ], static fn() => []);

        self::assertSame('select', $registry->groupsFor(null)['tone']->input, 'no profiles: shared groups are used');
    }

    public function testCleanKeepsOnlyRealStyleSwaps(): void
    {
        $clean = InputOverrides::clean(['styles' => ['icons' => 'buttons', 'nope' => 'select', 'select' => 'select', 'slider' => 'x', 'position' => 'select']]);

        self::assertSame(['icons' => 'buttons', 'position' => 'select'], $clean['styles']);
    }

    public function testStyleSwapAppliesSiteWide(): void
    {
        $registry = $this->registry(['styles' => ['icons' => 'buttons', 'buttons' => 'select']]);
        $team = $registry->groupsFor('blockTeam');

        self::assertSame(['buttons', false], [$team['container']->input, $team['container']->iconsOnly], 'icons become labelled buttons');
        self::assertSame('select', $team['columns']->input, 'buttons become a dropdown');
        self::assertSame('select', $registry->groupsFor('blockCta')['tone']->input);
        self::assertSame('select', $registry->configInputs['blockTeam']['columns']['input'], 'Default names the style after the swap');
        self::assertSame(['container'], $registry->styleUse['icons'], 'options per style, counted before the swap');
        self::assertSame(['columns', 'tone'], $registry->styleUse['buttons']);
    }

    public function testPerOptionPicksBeatTheStyleSwap(): void
    {
        $registry = $this->registry([
            'styles' => ['buttons' => 'select'],
            'groups' => ['columns' => ['input' => 'slider']],
            'profiles' => ['blockCta' => ['tone' => ['input' => 'buttons']]],
        ]);

        self::assertSame('slider', $registry->groupsFor('blockTeam')['columns']->input);
        self::assertSame('buttons', $registry->groupsFor('blockCta')['tone']->input);
        self::assertSame('select', $registry->groupsFor('blockTeam')['tone']->input);
    }

    public function testStyleSwapIsSkippedWhereItDoesNotFitAndNoted(): void
    {
        $registry = $this->registry(['styles' => ['buttons' => 'toggle']]);
        $skipped = array_values(array_filter($registry->inputNotes, static fn($n) => $n['scope'] === 'styles'));

        self::assertSame('buttons', $registry->groupsFor('blockTeam')['columns']->input, 'four options cannot be a toggle');
        self::assertSame(['unsupported', 'columns', 'buttons', 'toggle'], [$skipped[0]['kind'], $skipped[0]['group'], $skipped[0]['from'], $skipped[0]['input']]);
        self::assertSame(['buttons' => 'toggle'], InputOverrides::prune(InputOverrides::clean(['styles' => ['buttons' => 'toggle']]), $registry)['styles'], 'a swap is never pruned');
    }

    public function testOptionRowsSumUpEachOptionAcrossBlocks(): void
    {
        $rows = StyleChoices::rows($this->registry(['groups' => ['tone' => ['input' => 'select']]], [
            'profileOverrides' => ['blockCta' => ['tone' => ['input' => 'slider']]],
        ]));
        $byHandle = array_column($rows, null, 'handle');

        self::assertSame('tone', $rows[0]['handle'], 'most used first');
        self::assertSame(3, $byHandle['tone']['blocks']);
        self::assertSame('', $byHandle['tone']['base'], 'blocks differ before the picks: varies');
        self::assertSame('buttons', $byHandle['columns']['base']);
        self::assertSame(['icons', 'buttons', 'labels', 'select', 'slider', 'toggle', 'chip', 'position'], $byHandle['container']['values']);
    }

    public function testCleanKeepsTextOnlyAndValidOptionTexts(): void
    {
        $clean = InputOverrides::clean([
            'groups' => ['tone' => ['input' => 'buttons', 'textOnly' => '1']],
            'meta' => [
                'tone' => ['light' => ['label' => ' L ', 'icon' => 'sun'], 'dark' => ['label' => '', 'icon' => 'bad name!'], 'brand' => ['label' => str_repeat('x', 80)]],
                'columns' => 'nope',
            ],
        ]);

        self::assertSame(['input' => 'buttons', 'iconsOnly' => false, 'textOnly' => true], $clean['groups']['tone']);
        self::assertSame(['tone' => ['light' => ['label' => 'L', 'icon' => 'sun'], 'brand' => ['label' => str_repeat('x', 60)]]], $clean['meta']);
    }

    public function testOptionTextsApplyOnEveryBlockAndKeepTheOriginals(): void
    {
        $registry = $this->registry(['meta' => ['tone' => ['light' => ['label' => 'L', 'icon' => 'sun']]]]);

        foreach (['blockTeam', 'blockCta'] as $block) {
            self::assertSame(['L', 'sun'], [$registry->groupsFor($block)['tone']->options['light']['label'], $registry->groupsFor($block)['tone']->options['light']['icon']]);
        }
        self::assertSame('Dark', $registry->groupsFor('blockCta')['tone']->options['dark']['label'], 'untouched options keep theirs');
        self::assertSame(['label' => 'Light', 'icon' => null], $registry->baseOptions['tone']['light']);
        self::assertSame('L', $registry->groupsFor(null)['tone']->options['light']['label'] ?? null, 'shared groups too');
    }

    public function testIconsFromTheSettingsPageMakeTheIconsLookAvailable(): void
    {
        $withIcons = $this->registry([
            'meta' => ['tone' => ['light' => ['icon' => 'sun'], 'dark' => ['icon' => 'moon'], 'brand' => ['icon' => 'star']]],
            'styles' => ['buttons' => 'icons'],
        ]);
        $without = $this->registry(['styles' => ['buttons' => 'icons']]);

        self::assertTrue($withIcons->groupsFor('blockCta')['tone']->iconsOnly, 'every option now has an icon');
        self::assertFalse($without->groupsFor('blockCta')['tone']->iconsOnly, 'no icons, no icons-only look');
    }

    public function testPruneDropsTextsForOptionsThatAreGone(): void
    {
        $inputs = InputOverrides::clean(['meta' => ['tone' => ['light' => ['label' => 'L'], 'old' => ['label' => 'O']], 'gone' => ['x' => ['label' => 'X']]]]);

        self::assertSame(['tone' => ['light' => ['label' => 'L']]], InputOverrides::prune($inputs, $this->registry($inputs))['meta']);
    }
}
