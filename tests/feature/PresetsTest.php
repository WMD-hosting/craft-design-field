<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\Ui\UiControlTypes;
use CraftCms\Cms\Ui\UiResolver;
use wmd\designfield\fields\Design;
use wmd\designfield\services\Groups;
use wmd\designfield\web\controls\PresetBlock;
use wmd\designfield\web\OptionControls;

/**
 * Presets in the Craft 6 panel: the row above the options, what each preset carries
 * to the browser, and how the map it sets comes back on save.
 */
final class PresetsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return [
                    'groups' => [
                        'tone' => ['label' => 'Tone', 'auto' => 'Block default', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black']],
                        'spacing' => ['label' => 'Spacing', 'input' => 'slider', 'auto' => 'Block default', 'options' => ['tight' => 'py-8', 'standard' => 'py-16', 'loose' => 'py-24']],
                        'autoplay' => ['label' => 'Autoplay', 'input' => 'toggle', 'default' => 'off', 'aliases' => ['1' => 'on'], 'options' => ['off' => 'Off', 'on' => 'On']],
                        'delay' => ['label' => 'Time per slide', 'input' => 'slider', 'default' => '6', 'showIf' => ['autoplay' => ['on']], 'options' => ['3' => ['value' => 'delay-3', 'label' => '3 s'], '6' => ['value' => 'delay-6', 'label' => '6 s'], '8' => ['value' => 'delay-8', 'label' => '8 s']]],
                    ],
                    'profiles' => [
                        'slider' => ['tone', 'spacing', 'autoplay', 'delay'],
                        'plain' => ['tone', 'spacing'],
                        'bare' => ['delay'],
                    ],
                    'presets' => [
                        'slider' => [
                            'Calm' => ['autoplay' => 'on', 'delay' => '8', 'spacing' => 'loose'],
                            'Dark band' => ['tone' => 'dark'],
                        ],
                        '*' => [
                            'Dark band' => ['tone' => 'dark', 'spacing' => 'loose'],
                            'Compact' => ['tone' => 'auto', 'spacing' => 'tight', 'autoplay' => '1'],
                        ],
                    ],
                ] + parent::config();
            }
        });
    }

    public function testThePresetRowIsRegisteredWithTheControlPanel(): void
    {
        self::assertTrue($this->app->make(UiControlTypes::class)->types()->contains(PresetBlock::class));
    }

    public function testAPresetCarriesEachOptionAsItsControlHoldsIt(): void
    {
        $presets = $this->presetsFor('slider');

        self::assertSame(['Calm', 'Dark band', 'Compact'], array_column($presets, 'label'), 'the block\'s own first, then the `*` ones');
        self::assertSame(['autoplay' => true, 'delay' => '8', 'spacing' => 'loose'], $presets[0]['values'], 'a switch holds true/false, not its key');
        self::assertSame('Autoplay: On · Time per slide: 8 s · Spacing: Loose', $presets[0]['summary']);
        self::assertSame(['tone' => 'dark'], $presets[1]['values'], 'the block\'s own preset wins over a `*` one with its label');
        self::assertSame(['tone' => 'auto', 'spacing' => 'tight', 'autoplay' => true], $presets[2]['values'], 'aliases followed (1 is on)');
    }

    public function testAPresetSetsOnlyTheOptionsTheBlockHas(): void
    {
        $presets = $this->presetsFor('plain');

        self::assertSame(['Dark band', 'Compact'], array_column($presets, 'label'));
        self::assertSame(['tone' => 'auto', 'spacing' => 'tight'], $presets[1]['values'], 'Autoplay is no option here');
        self::assertSame([], $this->presetsFor('bare'), 'a preset left with nothing to set is dropped');
    }

    public function testTheFieldDrawsThePresetRowOverCoresContentBlock(): void
    {
        $control = $this->control('slider', ['autoplay' => 'off']);
        $props = $control->props([]);

        self::assertInstanceOf(PresetBlock::class, $control);
        self::assertSame('design-field:presets', $control->component());
        self::assertTrue($control->isReactive(), 'a pick refreshes the panel once, so options waiting for another follow');
        self::assertSame(['Calm', 'Dark band', 'Compact'], array_column($props['presets'], 'label'));
        self::assertSame('grouped', $props['viewMode'], 'core\'s Content Block props stay');
        self::assertNull($props['clearLabel'], 'the panel cannot be emptied');
        self::assertTrue($control->nestsUis());
        self::assertSame(
            ['tone', 'spacing', 'autoplay', 'delay'],
            array_map(static fn($node) => $node->getControl()->path(), $control->nestedUis([])[0]['ui']->nodes()),
            'the options are core\'s own fields, as before',
        );
    }

    public function testWithoutPresetsThePanelIsCoresContentBlock(): void
    {
        $control = $this->control('bare', []);

        self::assertSame('craft:content-block', $control->component());
        self::assertArrayNotHasKey('presets', $control->props([]));
        self::assertFalse($control->isReactive(), 'nothing on it changes the whole map');
    }

    public function testThePanelWithPresetsResolvesAsTheControlPanelDoes(): void
    {
        // The resolver checks what the browser gets: JSON-safe props, a registered control.
        $keys = ['tone' => 'auto', 'spacing' => 'auto', 'autoplay' => 'off', 'delay' => '6'];
        $payload = $this->app->make(UiResolver::class)->resolve(
            Ui::make([Field::make('Design', $this->control('slider', $keys))]),
            new UiContext(values: ['design' => $keys], refreshable: true),
        );
        $json = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true);
        $control = $json['nodes'][0]['control'];

        self::assertSame('design-field:presets', $control['component']);
        self::assertSame(['design'], $control['path']);
        self::assertTrue($control['reactive']);
        self::assertSame(['autoplay' => true, 'delay' => '8', 'spacing' => 'loose'], $control['props']['presets'][0]['values'], 'stays an object with a boolean');
        self::assertSame(['design', 'delay'], $control['uis'][0]['nodes'][3]['control']['path'], 'the options sit under the map the preset sets');
    }

    public function testTheMapAPresetSetsIsSavedAsKeys(): void
    {
        // What the editor posts after "Calm": the panel's map with the preset's values in it.
        $field = new Design(['handle' => 'design', 'profile' => 'slider']);
        $value = $field->normalizeValueFromRequest(['tone' => 'auto', 'spacing' => 'loose', 'autoplay' => true, 'delay' => '8'], null);

        self::assertSame(['tone' => 'auto', 'spacing' => 'loose', 'autoplay' => 'on', 'delay' => '8'], $value->keys());
    }

    /**
     * @return list<array{label:string,summary:string,values:array<string,string|bool>}>
     */
    private function presetsFor(string $profile): array
    {
        $registry = $this->app->make(Groups::class)->getRegistry();
        $groups = $registry->groupsFor($profile);

        return OptionControls::presets($registry->presetsFor($profile, $groups), $groups);
    }

    private function control(string $profile, array $keys): PresetBlock
    {
        $field = new Design(['handle' => 'design', 'profile' => $profile]);
        $control = $field->uiControl(new FieldContext('design', $field->normalizeValue($keys, null)));
        self::assertInstanceOf(PresetBlock::class, $control);

        return $control;
    }
}
