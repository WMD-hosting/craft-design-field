<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Support\Facades\I18N;
use Illuminate\Support\Facades\File;
use wmd\designfield\fields\Design;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;
use wmd\designfield\web\OptionControls;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\Message\Php\MessageSource;

/**
 * The panel in the editor's language: the site's names for its options and choices from
 * its `site` category, the panel's own words from the plugin's `design-field` category.
 */
final class TranslatedPanelTest extends PluginTestCase
{
    private string $lang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return [
                    'groups' => [
                        'variant' => ['label' => 'Layout', 'section' => 'Layout', 'options' => [
                            'split' => ['label' => 'Split'],
                            'stack' => ['label' => 'Stack'],
                        ]],
                        'tone' => ['label' => 'Tone', 'section' => 'Colour & style', 'auto' => 'Block default', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'instructions' => 'The band behind the block.'],
                        'border' => ['label' => 'Border', 'input' => 'toggle', 'options' => ['off' => '', 'on' => 'border'], 'default' => 'off'],
                        'width' => ['label' => 'Width', 'input' => 'select', 'options' => array_combine(
                            ['w1', 'w2', 'w3', 'w4', 'w5', 'w6', 'w7', 'w8', 'w9', 'w10'],
                            ['max-w-1', 'max-w-2', 'max-w-3', 'max-w-4', 'max-w-5', 'max-w-6', 'max-w-7', 'max-w-8', 'max-w-9', 'max-w-10'],
                        )],
                    ],
                    'profiles' => ['blockCta' => ['variant', 'tone', 'border', 'width']],
                    'presets' => ['blockCta' => ['Dark band' => ['tone' => 'dark', 'border' => 'on']]],
                ] + parent::config();
            }
        });

        // The site's Croatian, as a site has it in lang/hr/site.php.
        $this->lang = sys_get_temp_dir() . '/design-field-lang-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists("$this->lang/hr");
        File::put("$this->lang/hr/site.php", '<?php return ' . var_export([
            'Layout' => 'Izgled',
            'Split' => 'Podijeljeno',
            'Stack' => 'Jedno ispod drugog',
            'Tone' => 'Ton',
            'Colour & style' => 'Boja i stil',
            'Block default' => 'Zadano za blok',
            'Light' => 'Svijetli',
            'Dark' => 'Tamni',
            'The band behind the block.' => 'Traka iza bloka.',
            'Border' => 'Rub',
            'Off' => 'Isključeno',
            'On' => 'Uključeno',
            'Dark band' => 'Tamna traka',
        ], true) . ';');
        I18N::addCategorySources(new CategorySource('site', new MessageSource($this->lang), new IntlMessageFormatter()));
        $this->app->setLocale('hr');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->lang);

        parent::tearDown();
    }

    public function testThePanelNamesOptionsChoicesAndSectionsInTheEditorsLanguage(): void
    {
        $nodes = $this->panel(['tone' => 'dark']);

        self::assertSame('Izgled', $nodes[0]->props()['label'], 'the section heading');
        self::assertSame('Izgled', $nodes[0]->children()[0]->props()['label'], 'the option');
        self::assertSame(['Podijeljeno', 'Jedno ispod drugog'], array_column($nodes[0]->children()[0]->getControl()->props()['options'], 'label'));
        self::assertSame(['split', 'stack'], array_column($nodes[0]->children()[0]->getControl()->props()['options'], 'value'), 'keys stay');

        // Options without a section come next, then the other sections.
        $tone = $nodes[3]->children()[0];
        self::assertSame('Boja i stil', $nodes[3]->props()['label']);
        self::assertSame('Ton', $tone->props()['label']);
        self::assertSame('Traka iza bloka.', $tone->props()['instructions']);
        self::assertSame(['Zadano za blok', 'Svijetli', 'Tamni'], array_column($tone->getControl()->props()['options'], 'label'));
    }

    public function testASwitchAndANameWithoutATranslationRead(): void
    {
        $nodes = $this->panel([]);
        $border = $nodes[1];

        self::assertSame('Rub', $border->props()['label']);
        self::assertSame(['Uključeno', 'Isključeno'], [$border->getControl()->props()['onLabel'], $border->getControl()->props()['offLabel']]);
        self::assertSame('Width', $nodes[2]->props()['label'], 'a name the site does not translate stays as the config has it');
    }

    public function testThePanelsOwnWordsAreThePlugins(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta', 'viewMode' => Design::VIEW_COLLAPSED]);
        $label = fn(array $keys) => $field->uiControl(new FieldContext('design', $field->normalizeValue($keys, null), new Entry(['id' => 7])))->nestedUis([])[0]['ui']->nodes()[0]->props()['label'];

        self::assertSame('Opcije · zadane', $label([]));
        self::assertSame('Opcije · 1 promjena', $label(['tone' => 'dark']));
        self::assertSame('Opcije · 2 promjene', $label(['tone' => 'dark', 'border' => 'on']));
        self::assertSame('Opcije · 4 promjene', $label(['variant' => 'stack', 'tone' => 'dark', 'border' => 'on', 'width' => 'w2']), 'Croatian has a form for two to four');

        $summary = new Design(['handle' => 'design', 'profile' => 'blockCta', 'viewMode' => Design::VIEW_SUMMARY]);
        $nodes = $summary->uiControl(new FieldContext('design', $summary->normalizeValue(['tone' => 'dark', 'border' => 'on'], null), new Entry(['id' => 7])))->nestedUis([])[0]['ui']->nodes();
        self::assertStringContainsString('Ton: Tamni · Rub: Uključeno', $nodes[0]->props()['html']);
        self::assertSame('Uredi', $nodes[1]->props()['label']);

        $defaults = $summary->uiControl(new FieldContext('design', $summary->normalizeValue([], null), new Entry(['id' => 7])))->nestedUis([])[0]['ui']->nodes();
        self::assertStringContainsString('Sve opcije imaju zadane vrijednosti.', $defaults[0]->props()['html']);
    }

    public function testMostUsedIsInTheEditorsLanguage(): void
    {
        $group = $this->app->make(Groups::class)->getRegistry()->groupsFor('blockCta')['width'];
        $options = OptionControls::options($group, 'w1', ['w3' => 9, 'w5' => 4, 'w7' => 2]);

        self::assertSame(['Najčešće birano', 'Najčešće birano', 'Najčešće birano', 'Svi izbori'], array_slice(array_column($options, 'group'), 0, 4));
    }

    public function testAPresetIsNamedInTheEditorsLanguage(): void
    {
        $registry = $this->app->make(Groups::class)->getRegistry();
        $groups = $registry->groupsFor('blockCta');
        $presets = OptionControls::presets($registry->presetsFor('blockCta', $groups), $groups);

        self::assertSame('Tamna traka', $presets[0]['label']);
        self::assertSame('Ton: Tamni · Rub: Uključeno', $presets[0]['summary']);
        self::assertSame(['tone' => 'dark', 'border' => true], $presets[0]['values']);
    }

    public function testValuesAndTheSchemaKeepTheConfigsWords(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);
        $value = $field->normalizeValue(['tone' => 'dark'], null);

        self::assertSame('Tone', $value->groups()['tone']->label);
        self::assertSame('Dark', $value->groups()['tone']->options['dark']['label']);
        self::assertSame('Tone', $field->getInputSchema()['properties']['tone']['title']);
    }

    public function testInEnglishThePanelReadsAsTheConfig(): void
    {
        $this->app->setLocale('en-US');

        $nodes = $this->panel(['tone' => 'dark']);

        self::assertSame('Layout', $nodes[0]->props()['label']);
        self::assertSame(['Split', 'Stack'], array_column($nodes[0]->children()[0]->getControl()->props()['options'], 'label'));
    }

    /**
     * @param array<string,string> $keys
     * @return list<mixed>
     */
    private function panel(array $keys): array
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        return $field->uiControl(new FieldContext('design', $field->normalizeValue($keys, null)))->nestedUis([])[0]['ui']->nodes();
    }
}
