<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\ControlPayload;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\UiControlTypes;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\Ui\UiHtmlRenderer;
use CraftCms\Cms\Ui\UiResolver;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\Registry;
use wmd\designfield\services\Groups;
use wmd\designfield\web\controls\Grid;
use wmd\designfield\web\controls\Rendered;
use wmd\designfield\web\controls\Steps;
use wmd\designfield\web\OptionControls;

/**
 * What the panel draws for each input style: the slider, position grids, colour chips,
 * "Aa" samples, width bars and drawn icons, and how a slider's post comes back.
 */
final class OptionLooksTest extends PluginTestCase
{
    public static string $stylesheet = '/build/site.css';

    public const BARS = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="1" y="4" width="18" height="12"/><script>alert(1)</script></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindGroups();
    }

    /**
     * The test config as the Groups service (again after changing self::$stylesheet).
     */
    private function bindGroups(): void
    {
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
                        'sizes' => ['label' => 'Sizes', 'auto' => 'Auto', 'options' => [
                            'xs' => ['value' => 'a', 'sample' => '12px'], 'sm' => ['value' => 'b', 'sample' => '14px'], 'md' => ['value' => 'c', 'sample' => '16px'],
                            'lg' => ['value' => 'd', 'sample' => '20px'], 'xl' => ['value' => 'e', 'sample' => '24px'],
                        ]],
                        'fit' => ['label' => 'Fit', 'input' => 'position', 'columns' => 2, 'options' => ['cover' => 'object-cover', 'contain' => 'object-contain', 'fill' => 'object-fill', 'none' => 'object-none']],
                        'styled' => ['label' => 'Styled', 'iconsOnly' => true, 'options' => ['a' => ['value' => 'x', 'icon' => '<svg viewBox="0 0 20 20"><style>craft-button{display:none}</style><rect width="20" height="20"/></svg>'], 'b' => 'y']],
                        'plain' => ['label' => 'Plain', 'textOnly' => true, 'options' => ['sm' => ['value' => 'text-sm', 'sample' => '13px'], 'lg' => ['value' => 'text-lg', 'sample' => '19px']]],
                        'radius' => ['label' => 'Corner radius', 'input' => 'rendered', 'preview' => true, 'previewBase' => 'border', 'auto' => 'Block default', 'options' => ['none' => 'rounded-none', 'lg' => 'rounded-lg']],
                    ],
                    'previewStylesheet' => OptionLooksTest::$stylesheet,
                    'previewAttributes' => ['data-brand' => 'warm'],
                    'profiles' => ['blockLooks' => ['spacing', 'focus', 'align', 'tone', 'size', 'width', 'cols', 'plain', 'sizes', 'fit', 'styled', 'radius']],
                ] + parent::config();
            }
        });
    }

    public function testASliderNamesEveryStopAndPostsTheKey(): void
    {
        $control = OptionControls::control($this->group('spacing'), 'tight');

        self::assertInstanceOf(Steps::class, $control);
        self::assertSame('spacing', $control->path(), 'posts the key under the option, like the buttons');
        self::assertSame('tight', $control->getValue());
        self::assertSame('design-field:steps', $control->component());
        self::assertSame(
            ['auto', 'flush', 'tight', 'standard', 'loose'],
            array_column($control->props()['steps'], 'value'),
            'Block default first, then the options in order',
        );
        self::assertSame('Block default', $control->props()['steps'][0]['label']);
        self::assertTrue($control->isReactive(), 'other options may wait for this one (showIf)');
        self::assertSame(['type' => 'string', 'enum' => ['auto', 'flush', 'tight', 'standard', 'loose']], $control->inputSchema());
    }

    public function testOurControlsAreRegisteredWithTheControlPanel(): void
    {
        $types = $this->app->make(UiControlTypes::class)->types();

        self::assertTrue($types->contains(Steps::class));
        self::assertTrue($types->contains(Grid::class));
        self::assertTrue($types->contains(Rendered::class));
    }

    public function testTheSliderDrawsADropdownWhereThePanelIsNotVue(): void
    {
        $payload = new ControlPayload(
            type: Steps::class,
            component: 'design-field:steps',
            props: ['steps' => [['value' => 'a', 'label' => 'A'], ['value' => 'b"<', 'label' => 'B & <b>']]],
            path: ['spacing'],
            mode: ControlMode::Editable,
            deltaGroup: [],
        );
        $renderer = $this->app->make(UiHtmlRenderer::class);
        $html = Steps::renderHtml($payload, 'b"<', ['id' => 'f-spacing', 'name' => 'fields[design][spacing]', 'disabled' => false, 'readonly' => false], $renderer);

        self::assertStringContainsString('<select id="f-spacing" name="fields[design][spacing]">', $html);
        self::assertStringContainsString('<option value="b&quot;&lt;" selected>B &amp; &lt;b&gt;</option>', $html);
        self::assertStringContainsString('<option value="a">A</option>', $html);

        $locked = Steps::renderHtml($payload, 'a', ['id' => 'x', 'name' => null, 'disabled' => false, 'readonly' => true], $renderer);
        self::assertStringContainsString('<select id="x" disabled>', $locked);
    }

    public function testASliderPostComesBackAsItsKey(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockLooks']);

        // The slider posts the key itself; places under the old slider key still map.
        self::assertSame('standard', $field->normalizeValueFromRequest(['spacing' => 'standard'], null)->toArray()['spacing']);
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
        self::assertInstanceOf(Steps::class, OptionControls::control($group, 'standard'));
        self::assertSame(['auto', 'tight', 'standard', 'loose'], array_column(OptionControls::control($group, 'standard')->props()['steps'], 'value'));
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

    public function testManySamplesStayButtonsInTheEditorToo(): void
    {
        $group = $this->group('sizes');

        self::assertSame('buttons', $group->input);
        self::assertSame(ChoicePresentation::Buttons, OptionControls::presentation($group), 'a dropdown would show names only, no "Aa"');
    }

    public function testANinePointGridHasAutoAboveAndADotPerCell(): void
    {
        $control = OptionControls::control($this->group('focus'), 'top-left');

        self::assertInstanceOf(Grid::class, $control);
        self::assertSame('focus', $control->path(), 'posts the key under the option, like the buttons');
        self::assertSame('top-left', $control->getValue());
        self::assertSame('design-field:grid', $control->component());
        self::assertTrue($control->isReactive());

        $props = $control->props();
        self::assertSame(3, $props['columns']);
        self::assertSame(['value' => 'auto', 'label' => 'Auto'], $props['text']);
        self::assertSame(
            ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'],
            array_column($props['cells'], 'value'),
        );
        self::assertSame('Top left', $props['cells'][0]['label']);
        self::assertStringContainsString('df-dot', $props['cells'][0]['html']);
        self::assertSame(
            ['type' => 'string', 'enum' => ['auto', 'top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right']],
            $control->inputSchema(),
        );
    }

    public function testARowOfPositionsDrawsWhereEachCellSits(): void
    {
        $props = OptionControls::control($this->group('align'), 'start')->props();

        self::assertSame(0, $props['columns'], 'a row set: all cells in one row');
        self::assertNull($props['text'], 'Align has no Auto');
        self::assertStringContainsString('df-mark--row-0', $props['cells'][0]['html']);
        self::assertStringContainsString('df-mark--row-2', $props['cells'][2]['html']);
    }

    public function testAHiddenCellKeepsItsPlaceInTheGrid(): void
    {
        $group = $this->group('focus')->withHidden(['center']);
        $cells = OptionControls::control($group, 'auto')->props()['cells'];

        self::assertSame('center', $cells[4]['value'], 'the middle cell stays the fifth cell of the grid');
        self::assertTrue($cells[4]['gap']);
        self::assertSame('right', $cells[5]['value']);
        self::assertNotContains('center', OptionControls::control($group, 'auto')->inputSchema()['enum'], 'a gap cannot be picked');

        $chosen = OptionControls::control($group, 'center')->props()['cells'];
        self::assertArrayNotHasKey('gap', $chosen[4], 'a hidden choice that is chosen stays a choice');
    }

    public function testAHiddenChoiceOutsideAGridLeavesNoGap(): void
    {
        $cells = OptionControls::control($this->group('align')->withHidden(['center']), 'start')->props()['cells'];

        self::assertSame(['start', 'end'], array_column($cells, 'value'));
    }

    public function testCellsOutsideAPositionSetShowTheirNames(): void
    {
        $props = OptionControls::control($this->group('fit'), 'cover')->props();

        self::assertSame(2, $props['columns']);
        self::assertSame('Cover', $props['cells'][0]['label']);
        self::assertNull($props['cells'][0]['html'], 'no icon and no position: the cell shows its name');
    }

    public function testTheGridDrawsADropdownWhereThePanelIsNotVue(): void
    {
        $control = OptionControls::control($this->group('focus')->withHidden(['center']), 'top');
        $payload = new ControlPayload(
            type: Grid::class,
            component: 'design-field:grid',
            props: $control->props(),
            path: ['focus'],
            mode: ControlMode::Editable,
            deltaGroup: [],
        );
        $html = Grid::renderHtml($payload, 'top', ['id' => 'f-focus', 'name' => 'fields[design][focus]', 'disabled' => false, 'readonly' => false], $this->app->make(UiHtmlRenderer::class));

        self::assertStringStartsWith('<select id="f-focus" name="fields[design][focus]"><option value="auto">Auto</option>', $html);
        self::assertStringContainsString('<option value="top" selected>Top</option>', $html);
        self::assertStringNotContainsString('value="center"', $html, 'gaps are left out');
    }

    public function testARenderedOptionIsDrawnWithTheSiteStylesheet(): void
    {
        $control = OptionControls::control($this->group('radius'), 'lg');

        self::assertInstanceOf(Rendered::class, $control);
        self::assertSame('radius', $control->path());
        self::assertSame('lg', $control->getValue());
        self::assertTrue($control->isReactive());

        $props = $control->props();
        self::assertSame('/build/site.css', $props['stylesheet']);
        self::assertSame(['data-brand' => 'warm'], $props['attributes']);
        self::assertSame(['auto', 'none', 'lg'], array_column($props['choices'], 'value'));
        self::assertNull($props['choices'][0]['preview'], 'Block default is a card with its name');
        self::assertSame('border rounded-lg', $props['choices'][2]['preview'], 'the base, then the choice');
        self::assertSame(['type' => 'string', 'enum' => ['auto', 'none', 'lg']], $control->inputSchema());

        $html = Rendered::renderHtml(
            new ControlPayload(type: Rendered::class, component: 'design-field:rendered', props: $props, path: ['radius'], mode: ControlMode::Editable, deltaGroup: []),
            'lg',
            ['id' => 'r', 'name' => 'fields[design][radius]', 'disabled' => false, 'readonly' => false],
            $this->app->make(UiHtmlRenderer::class),
        );
        self::assertStringContainsString('<option value="lg" selected>', $html);
    }

    public function testWithoutASiteStylesheetARenderedOptionStaysRadios(): void
    {
        self::$stylesheet = '';

        try {
            $this->bindGroups();
            $control = OptionControls::control($this->group('radius'), 'lg');
        } finally {
            self::$stylesheet = '/build/site.css';
        }

        self::assertInstanceOf(Choice::class, $control);
        self::assertSame(ChoicePresentation::Radios, OptionControls::presentation($this->group('radius')));
    }

    public function testTheWholePanelResolvesAsTheControlPanelDoes(): void
    {
        // The resolver checks what the browser gets (JSON-safe props, registered controls).
        $groups = $this->app->make(Groups::class)->getRegistry()->groupsFor('blockLooks');
        $keys = array_map(static fn($group) => $group->default, $groups);
        $payload = $this->app->make(UiResolver::class)->resolve(OptionControls::ui($groups, $keys), new UiContext(namespace: 'design', values: $keys));
        $json = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true);
        $components = [];

        array_walk_recursive($json, static function($value, $key) use (&$components) {
            if ($key === 'component' && is_string($value) && str_starts_with($value, 'design-field:')) {
                $components[$value] = true;
            }
        });

        self::assertSame(['design-field:steps', 'design-field:grid', 'design-field:rendered'], array_keys($components));
    }

    public function testStyleRulesInsideAnSvgIconAreDropped(): void
    {
        $options = OptionControls::options($this->group('styled'), 'a');

        self::assertStringContainsString('<rect', $options[0]['labelHtml']);
        self::assertStringNotContainsString('style>', $options[0]['labelHtml']);
        self::assertStringNotContainsString('display:none', $options[0]['labelHtml']);
    }

    public function testThePanelUsesTheSliderInTheEditor(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockLooks']);
        $control = $field->uiControl(new FieldContext('design', $field->normalizeValue(['spacing' => 'loose'], null)));
        $nodes = $control->nestedUis([])[0]['ui']->nodes();

        self::assertInstanceOf(Steps::class, $nodes[0]->getControl());
        self::assertSame('loose', $nodes[0]->getControl()->getValue());
    }

    private function group(string $handle): \wmd\designfield\models\Group
    {
        return $this->app->make(Groups::class)->getRegistry()->groupsFor('blockLooks')[$handle];
    }
}
