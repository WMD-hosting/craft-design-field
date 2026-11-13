<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Range;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\Registry;
use wmd\designfield\services\Groups;
use wmd\designfield\web\OptionControls;

/**
 * What the panel draws for each input style: the slider, position grids, colour chips,
 * "Aa" samples, width bars and drawn icons, and how a slider's post comes back.
 */
final class OptionLooksTest extends PluginTestCase
{
    public const BARS = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="1" y="4" width="18" height="12"/><script>alert(1)</script></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return [
                    'groups' => [
                        'spacing' => ['label' => 'Spacing', 'input' => 'slider', 'auto' => 'Block default', 'options' => ['flush' => 'py-0', 'tight' => 'py-8', 'standard' => 'py-16', 'loose' => 'py-24']],
                        'focus' => ['label' => 'Focal point', 'input' => 'position', 'auto' => 'Auto', 'options' => [
                            'center' => 'object-center', 'top-left' => 'object-left-top', 'top' => 'object-top', 'top-right' => 'object-right-top',
                            'left' => 'object-left', 'right' => 'object-right', 'bottom-left' => 'object-left-bottom', 'bottom' => 'object-bottom', 'bottom-right' => 'object-right-bottom',
                        ]],
                        'align' => ['label' => 'Align', 'input' => 'position', 'options' => ['start' => 'text-left', 'center' => 'text-center', 'end' => 'text-right']],
                        'tone' => ['label' => 'Tone', 'input' => 'swatches', 'auto' => 'Block default', 'options' => [
                            'ink' => ['value' => 'bg-black text-white', 'label' => 'Ink', 'swatch' => '#111827', 'swatchText' => '#ffffff'],
                            'duo' => ['value' => 'bg-duo', 'label' => 'Duo', 'swatch' => '#4f46e5|#0ea5e9'],
                            'none' => ['value' => '', 'label' => 'None', 'swatch' => 'transparent'],
                            'odd' => ['value' => 'x', 'label' => 'Odd', 'swatch' => 'red;background:url(x)'],
                        ]],
                        'size' => ['label' => 'Size', 'options' => ['sm' => ['value' => 'text-sm', 'sample' => '13px'], 'lg' => ['value' => 'text-lg', 'sample' => '19px']]],
                        'width' => ['label' => 'Width', 'options' => ['narrow' => ['value' => 'max-w-md', 'label' => 'Narrow', 'percent' => '50'], 'wide' => ['value' => 'max-w-5xl', 'label' => 'Wide', 'percent' => '75%']]],
                        'cols' => ['label' => 'Columns', 'options' => ['1' => ['value' => 'grid-cols-1', 'icon' => OptionLooksTest::BARS], '2' => ['value' => 'grid-cols-2', 'icon' => 'table-columns']]],
                        'plain' => ['label' => 'Plain', 'textOnly' => true, 'options' => ['sm' => ['value' => 'text-sm', 'sample' => '13px'], 'lg' => ['value' => 'text-lg', 'sample' => '19px']]],
                    ],
                    'profiles' => ['blockLooks' => ['spacing', 'focus', 'align', 'tone', 'size', 'width', 'cols', 'plain']],
                ] + parent::config();
            }
        });
    }

    public function testASliderStepsThroughTheOptionsAndShowsTheChoice(): void
    {
        $control = OptionControls::control($this->group('spacing'), 'tight');

        self::assertInstanceOf(Range::class, $control);
        self::assertSame([Registry::SLIDER_KEY, 'spacing'], $control->path());
        self::assertSame(2, $control->getValue(), 'Block default, Flush, Tight: Tight is the third step');
        self::assertSame(['inputType' => 'range', 'min' => 0, 'max' => 4, 'step' => 1, 'suffix' => 'Tight'], array_intersect_key($control->props(), array_flip(['inputType', 'min', 'max', 'step', 'suffix'])));
    }

    public function testASliderPostComesBackAsItsKey(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockLooks']);

        // The editor posts the range as a string under the slider key.
        $value = $field->normalizeValueFromRequest(['tone' => 'ink', Registry::SLIDER_KEY => ['spacing' => '2']], null);
        self::assertSame('tight', $value->toArray()['spacing']);
        self::assertSame([], $value->invalid());
        self::assertArrayNotHasKey(Registry::SLIDER_KEY, $value->toArray());

        // A place off the scale changes nothing; a plain key still works.
        self::assertSame('auto', $field->normalizeValue([Registry::SLIDER_KEY => ['spacing' => '9']], null)->toArray()['spacing']);
        self::assertSame('loose', $field->normalizeValue(['spacing' => 'loose'], null)->toArray()['spacing']);
    }

    public function testASliderWhoseChoiceItLeavesOutKeepsButtons(): void
    {
        $group = $this->group('spacing')->withHidden(['flush']);

        self::assertInstanceOf(Choice::class, OptionControls::control($group, 'flush'), 'Flush is chosen but not on the scale');
        self::assertSame(['auto', 'tight', 'standard', 'loose'], $group->sliderKeys());
        self::assertInstanceOf(Range::class, OptionControls::control($group, 'standard'));
    }

    public function testANinePointGridLaysItselfOutWithAutoOnTop(): void
    {
        $group = $this->group('focus');
        $options = OptionControls::options($group, 'auto');

        self::assertSame(ChoicePresentation::Buttons, OptionControls::presentation($group));
        self::assertSame(
            ['auto', 'top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'],
            array_column($options, 'value'),
        );
        self::assertStringContainsString('df-cell--text', $options[0]['labelHtml']);
        self::assertStringContainsString('data-df-columns="3"', $options[1]['labelHtml']);
        self::assertStringContainsString('df-dot', $options[1]['labelHtml']);
        self::assertStringContainsString('title="Top left"', $options[1]['labelHtml']);
        self::assertStringContainsString('<span class="df-sr">Top left</span>', $options[1]['labelHtml']);
    }

    public function testARowOfPositionsDrawsWhereEachCellSits(): void
    {
        $options = OptionControls::options($this->group('align'), 'start');

        self::assertStringContainsString('data-df-columns="0"', $options[0]['labelHtml']);
        self::assertStringContainsString('df-mark--row-0', $options[0]['labelHtml']);
        self::assertStringContainsString('df-mark--row-2', $options[2]['labelHtml']);
    }

    public function testSwatchesAreChipsAPairShowsAaAndASplitHalves(): void
    {
        $group = $this->group('tone');
        $options = array_column(OptionControls::options($group, 'auto'), null, 'value');

        self::assertSame(ChoicePresentation::Buttons, OptionControls::presentation($group));
        self::assertArrayNotHasKey('labelHtml', $options['auto'], 'Block default has no colour: its name');
        self::assertStringContainsString('>Aa</span>', $options['ink']['labelHtml']);
        self::assertStringContainsString('color: #ffffff', $options['ink']['labelHtml']);
        self::assertStringContainsString('linear-gradient(135deg, #4f46e5 50%, #0ea5e9 50%)', $options['duo']['labelHtml']);
        self::assertStringContainsString('df-swatch--empty', $options['none']['labelHtml']);
        self::assertArrayNotHasKey('labelHtml', $options['odd'], 'a colour that could break out of the style is not drawn');
        self::assertArrayNotHasKey('color', $options['ink']);
    }

    public function testSamplesBarsAndDrawnIcons(): void
    {
        $size = OptionControls::options($this->group('size'), 'sm');
        self::assertStringContainsString('font-size: 13px', $size[0]['labelHtml']);
        self::assertStringContainsString('<span class="df-sr">Sm</span>', $size[0]['labelHtml']);

        $width = OptionControls::options($this->group('width'), 'narrow');
        self::assertStringContainsString('width: 50%', $width[0]['labelHtml']);
        self::assertStringContainsString('width: 75%', $width[1]['labelHtml']);
        self::assertStringContainsString('<span class="df-text">Wide</span>', $width[1]['labelHtml']);

        $cols = OptionControls::options($this->group('cols'), '1');
        self::assertStringContainsString('<rect', $cols[0]['labelHtml']);
        self::assertStringNotContainsString('script', $cols[0]['labelHtml'], 'inline SVG is sanitised');
        self::assertArrayNotHasKey('icon', $cols[0], 'Craft icons take names, not markup');
        self::assertSame('table-columns', $cols[1]['icon']);
        self::assertArrayNotHasKey('labelHtml', $cols[1]);

        $plain = OptionControls::options($this->group('plain'), 'sm');
        self::assertArrayNotHasKey('labelHtml', $plain[0], 'textOnly keeps plain names');
    }

    public function testThePanelUsesTheSliderInTheEditor(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockLooks']);
        $control = $field->uiControl(new FieldContext('design', $field->normalizeValue(['spacing' => 'loose'], null)));
        $nodes = $control->nestedUis([])[0]['ui']->nodes();

        self::assertInstanceOf(Range::class, $nodes[0]->getControl());
        self::assertSame(4, $nodes[0]->getControl()->getValue());
    }

    private function group(string $handle): \wmd\designfield\models\Group
    {
        return $this->app->make(Groups::class)->getRegistry()->groupsFor('blockLooks')[$handle];
    }
}
