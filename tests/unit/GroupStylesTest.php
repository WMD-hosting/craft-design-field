<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\StyleChoices;
use wmd\designfield\models\Group;

final class GroupStylesTest extends TestCase
{
    private function group(array $options, array $config = []): Group
    {
        return Group::fromConfig('g', $config, $options);
    }

    public function testButtonsAndSelectAlwaysFit(): void
    {
        $inputs = $this->group(['a' => 'x', 'b' => 'y', 'c' => 'z'])->supportedInputs();

        self::assertSame(['buttons', 'select', 'slider'], $inputs);
    }

    public function testToggleOnlyWithTwoOptions(): void
    {
        self::assertContains('toggle', $this->group(['off' => '', 'on' => 'x'])->supportedInputs());
        self::assertNotContains('toggle', $this->group(['a' => 'x', 'b' => 'y', 'c' => 'z'])->supportedInputs());
    }

    public function testSliderNeedsTwoOptions(): void
    {
        self::assertNotContains('slider', $this->group(['only' => 'x'])->supportedInputs());
    }

    public function testSwatchesNeedEverySwatchAutoAside(): void
    {
        $all = ['a' => ['swatch' => '#000', 'value' => 'x'], 'b' => ['swatch' => '#fff', 'value' => 'y']];
        $some = ['a' => ['swatch' => '#000', 'value' => 'x'], 'b' => 'y'];

        self::assertContains('swatches', $this->group($all, ['auto' => 'Block default'])->supportedInputs());
        self::assertNotContains('swatches', $this->group($some)->supportedInputs());
    }

    public function testTilesNeedAnImageAndPositionNeedsColumns(): void
    {
        $tiles = $this->group(['a' => ['image' => '/a.png', 'value' => 'x'], 'b' => 'y']);
        self::assertContains('tiles', $tiles->supportedInputs());
        self::assertNotContains('tiles', $this->group(['a' => 'x', 'b' => 'y'])->supportedInputs());

        self::assertContains('position', $this->group(['tl' => 'x', 'tr' => 'y'], ['columns' => 2])->supportedInputs());
        self::assertNotContains('position', $this->group(['tl' => 'x', 'tr' => 'y'])->supportedInputs());
    }

    public function testPositionAlsoFitsARowOfIcons(): void
    {
        $icons = $this->group(['1' => ['icon' => 'a', 'value' => 'x'], '2' => ['icon' => 'b', 'value' => 'y']], ['auto' => 'Block default']);

        self::assertContains('position', $icons->supportedInputs(), 'one row of icon cells, as config groups use it');
    }

    public function testWithOverridesKeepsTheInputWhenToggleDoesNotFit(): void
    {
        $group = $this->group(['a' => 'x', 'b' => 'y', 'c' => 'z'], ['input' => 'select']);

        self::assertSame('select', $group->withOverrides(['input' => 'toggle'])->input);
        self::assertSame('slider', $group->withOverrides(['input' => 'slider'])->input);
    }

    public function testIconsAreAChoiceOnlyWhenEveryOptionHasOne(): void
    {
        $icons = $this->group(['1' => ['icon' => 'a', 'value' => 'x'], '2' => ['icon' => 'b', 'value' => 'y'], '3' => ['icon' => 'c', 'value' => 'z']], ['auto' => 'Block default']);
        $plain = $this->group(['1' => 'x', '2' => 'y', '3' => 'z']);

        self::assertSame(['icons', 'buttons', 'labels', 'select', 'slider', 'position'], StyleChoices::values($icons));
        self::assertSame(['buttons', 'select', 'slider'], StyleChoices::values($plain));
    }

    public function testStyleValueTellsIconsFromButtons(): void
    {
        self::assertSame('icons', StyleChoices::value(['input' => 'buttons', 'iconsOnly' => true]));
        self::assertSame('buttons', StyleChoices::value(['input' => 'buttons', 'iconsOnly' => false]));
        self::assertSame('select', StyleChoices::value(['input' => 'select', 'iconsOnly' => true]));
        self::assertSame('labels', StyleChoices::value(['input' => 'buttons', 'iconsOnly' => false, 'textOnly' => true]));
        self::assertSame(['input' => 'buttons', 'iconsOnly' => false, 'textOnly' => true], StyleChoices::style('labels'));
        self::assertSame(['input' => 'buttons', 'iconsOnly' => false, 'textOnly' => false], StyleChoices::style('buttons'));
    }

    public function testLabelsLookOnlyWhenButtonsShowMoreThanText(): void
    {
        $sizes = $this->group(['sm' => ['sample' => '0.75rem', 'value' => 'x'], 'lg' => ['sample' => '1.1rem', 'value' => 'y']]);
        $plain = $this->group(['1' => 'x', '2' => 'y', '3' => 'z']);

        self::assertContains('labels', StyleChoices::values($sizes), 'Aa samples can become plain text');
        self::assertNotContains('labels', StyleChoices::values($plain), 'plain buttons already are text');
        self::assertTrue($sizes->withOverrides(StyleChoices::style('labels'))->textOnly);
        self::assertFalse($sizes->withOverrides(StyleChoices::style('labels'))->withOverrides(StyleChoices::style('buttons'))->textOnly);
    }

    public function testChipIsAnOnOffLookLikeToggle(): void
    {
        $onOff = $this->group(['off' => '', 'on' => 'x']);
        $three = $this->group(['a' => 'x', 'b' => 'y', 'c' => 'z']);

        self::assertContains('chip', $onOff->supportedInputs());
        self::assertNotContains('chip', $three->supportedInputs());
        self::assertSame('chip', $onOff->withOverrides(['input' => 'chip'])->input);
        self::assertSame('buttons', $three->withOverrides(['input' => 'chip'])->input, 'a chip needs exactly two options');
        self::assertContains('chip', StyleChoices::values($onOff));
        self::assertSame('Chip', StyleChoices::LABELS['chip']);
    }
}
