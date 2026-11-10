<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Field\FieldTypes;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\ContentBlock;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use wmd\designfield\fields\Design;
use wmd\designfield\models\DesignValue;
use wmd\designfield\services\Groups;
use wmd\designfield\variables\DesignFieldVariable;
use wmd\designfield\web\OptionControls;

/**
 * The plugin inside a real Craft 6 install (Testbench, SQLite in memory).
 */
final class PluginTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The config file lives in the project; here it is given directly.
        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return [
                    'groups' => [
                        'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
                        'border' => ['label' => 'Border', 'input' => 'toggle', 'options' => ['off' => '', 'on' => 'border'], 'default' => 'off'],
                        'shade' => ['label' => 'Shade', 'section' => 'Colour', 'options' => ['soft' => 'opacity-50', 'full' => 'opacity-100'], 'showIf' => ['tone' => ['dark']]],
                        'variant' => ['label' => 'Layout', 'input' => 'tiles', 'options' => [
                            '_default' => ['label' => 'Split'],
                            'wide' => ['label' => 'Wide'],
                            'stack' => ['label' => 'Stack', 'image' => '/design-field/blockHero/gone.jpg'],
                        ]],
                    ],
                    'profiles' => ['blockCta' => ['tone', 'border', 'shade'], 'blockHero' => ['variant'], 'blockNews' => ['tone', '_view' => 'panel']],
                ] + parent::config();
            }
        });
    }

    public function testTheFieldTypeIsRegistered(): void
    {
        self::assertContains(Design::class, $this->app->make(FieldTypes::class)->types()->all());
    }

    public function testAValueGoesInAsAMapAndComesBackAsAMap(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        $value = $field->normalizeValue(['tone' => 'dark'], null);
        self::assertInstanceOf(DesignValue::class, $value);
        self::assertSame([], $value->invalid());
        self::assertSame('dark', $field->serializeValue($value, null)['tone']);

        $posted = $field->normalizeValueFromRequest('{"tone":"dark"}', null);
        self::assertSame('dark', $posted->toArray()['tone']);

        self::assertSame(['tone' => 'neon'], $field->normalizeValue(['tone' => 'neon'], null)->invalid());
    }

    public function testASwitchedOffToggleMeansItsFirstOption(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        self::assertSame('off', $field->normalizeValueFromRequest(['tone' => 'dark', 'border' => ''], null)->toArray()['border']);
        self::assertSame('on', $field->normalizeValueFromRequest(['tone' => 'dark', 'border' => 'on'], null)->toArray()['border']);
    }

    public function testTheEditorPostsASwitchAsTrueOrFalse(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        $on = $field->normalizeValueFromRequest(['tone' => 'dark', 'border' => true], null);
        self::assertSame('on', $on->toArray()['border']);
        self::assertSame([], $on->invalid());
        self::assertSame('off', $field->normalizeValueFromRequest(['tone' => 'dark', 'border' => false], null)->toArray()['border']);
    }

    public function testThePanelIsOneControlPerOption(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);
        $control = $field->uiControl(new FieldContext('design', $field->normalizeValue(['tone' => 'light'], null)));

        self::assertInstanceOf(ContentBlock::class, $control);

        $nodes = $control->nestedUis([])[0]['ui']->nodes();
        self::assertInstanceOf(Choice::class, $nodes[0]->getControl());
        self::assertSame('tone', $nodes[0]->getControl()->path());
        self::assertSame('light', $nodes[0]->getControl()->getValue());
        self::assertInstanceOf(Lightswitch::class, $nodes[1]->getControl());
        self::assertFalse($nodes[1]->getControl()->getValue());

        // The section "Colour" holds Shade, which only applies to a dark tone.
        $shade = $nodes[2]->children()[0];
        self::assertFalse($shade->isVisible());

        $dark = $field->uiControl(new FieldContext('design', $field->normalizeValue(['tone' => 'dark'], null)));
        self::assertTrue($dark->nestedUis([])[0]['ui']->nodes()[2]->children()[0]->isVisible());
    }

    public function testCollapsedPutsTheOptionsInAClosedSectionThatSaysHowManyChanged(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta', 'viewMode' => Design::VIEW_COLLAPSED]);
        $entry = new Entry(['id' => 7]);

        $nodes = $field->uiControl(new FieldContext('design', $field->normalizeValue(['tone' => 'dark'], null), $entry))->nestedUis([])[0]['ui']->nodes();
        self::assertCount(1, $nodes);
        self::assertSame('Options · 1 change', $nodes[0]->props()['label']);
        self::assertTrue($nodes[0]->props()['collapsible']);
        self::assertArrayNotHasKey('expanded', $nodes[0]->props());
        self::assertSame('tone', $nodes[0]->children()[0]->getControl()->path());

        // A new element starts open.
        $new = $field->uiControl(new FieldContext('design', $field->normalizeValue([], null)))->nestedUis([])[0]['ui']->nodes();
        self::assertSame('Options · Defaults', $new[0]->props()['label']);
        self::assertTrue($new[0]->props()['expanded']);
    }

    public function testSummarySaysWhatIsSetAboveTheOptions(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta', 'viewMode' => Design::VIEW_SUMMARY]);
        $entry = new Entry(['id' => 7]);

        $nodes = $field->uiControl(new FieldContext('design', $field->normalizeValue(['tone' => 'dark', 'border' => 'on'], null), $entry))->nestedUis([])[0]['ui']->nodes();
        self::assertInstanceOf(MarkdownContent::class, $nodes[0]);
        self::assertStringContainsString('Tone: Dark · Border: On', $nodes[0]->props()['html']);
        self::assertSame('Edit', $nodes[1]->props()['label']);
        self::assertTrue($nodes[1]->props()['collapsible']);

        $defaults = $field->uiControl(new FieldContext('design', $field->normalizeValue([], null), $entry))->nestedUis([])[0]['ui']->nodes();
        self::assertStringContainsString('All options at their defaults.', $defaults[0]->props()['html']);
    }

    public function testAProfileCanSetItsOwnViewMode(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockNews', 'viewMode' => Design::VIEW_COLLAPSED]);

        $nodes = $field->uiControl(new FieldContext('design', $field->normalizeValue([], null), new Entry(['id' => 7])))->nestedUis([])[0]['ui']->nodes();
        self::assertSame('tone', $nodes[0]->getControl()->path());
    }

    public function testOptionLayoutsInlineDropsHeadingsAndSideHalvesTheSections(): void
    {
        $value = (new Design(['handle' => 'design', 'profile' => 'blockCta']))->normalizeValue(['tone' => 'dark'], null);

        $inline = OptionControls::panel($value, layout: Design::LAYOUT_INLINE)->nodes();
        self::assertCount(3, $inline);
        self::assertSame('shade', $inline[2]->getControl()->path());

        $side = OptionControls::panel($value, layout: Design::LAYOUT_SIDE)->nodes();
        self::assertSame(50, $side[2]->props()['width']);
        self::assertArrayNotHasKey('width', OptionControls::panel($value)->nodes()[2]->props());
    }

    public function testTheFieldSettingsOfferViewLayoutAndProfile(): void
    {
        $field = new Design(['handle' => 'design', 'name' => 'Design', 'profile' => 'gone', 'viewMode' => Design::VIEW_SUMMARY]);
        $nodes = $field->settingsUi()->nodes();

        self::assertSame(['viewMode', 'optionLayout', 'profile'], array_map(static fn($node) => $node->getControl()->path(), $nodes));
        self::assertSame(Design::VIEW_SUMMARY, $nodes[0]->getControl()->getValue());
        self::assertSame(['', 'blockCta', 'blockHero', 'blockNews', 'gone'], array_column($nodes[2]->getControl()->props()['options'], 'value'));

        $rules = $field->getRules();
        self::assertTrue(Validator::make(['viewMode' => 'summary', 'optionLayout' => 'side'], ['viewMode' => $rules['viewMode'], 'optionLayout' => $rules['optionLayout']])->passes());
        self::assertTrue(Validator::make(['viewMode' => 'sideways'], ['viewMode' => $rules['viewMode']])->fails());
    }

    public function testALayoutGetsItsPictureFromTheWebRootAndAMissingOneIsLeftOut(): void
    {
        $root = sys_get_temp_dir() . '/design-field-' . uniqid();
        mkdir("$root/design-field/blockHero", 0777, true);
        touch("$root/design-field/blockHero/_default.jpg", 1700000000);
        $this->app->usePublicPath($root);

        $groups = $this->app->make(Groups::class);
        self::assertSame('/design-field/blockHero/_default.jpg?v=1700000000', $groups->tileImage('blockHero', '_default'));
        self::assertNull($groups->tileImage('blockHero', 'wide'));
        self::assertNull($groups->tilePath('../etc', 'passwd'));

        $field = new Design(['handle' => 'design', 'profile' => 'blockHero']);
        $control = $field->uiControl(new FieldContext('design', $field->normalizeValue([], null)));
        $options = $control->nestedUis([])[0]['ui']->nodes()[0]->getControl()->props()['options'];

        self::assertSame(['src' => '/design-field/blockHero/_default.jpg?v=1700000000'], $options[0]['thumbnail']);
        self::assertArrayNotHasKey('thumbnail', $options[1]);
        self::assertArrayNotHasKey('thumbnail', $options[2]);

        unlink("$root/design-field/blockHero/_default.jpg");
        rmdir("$root/design-field/blockHero");
        rmdir("$root/design-field");
        rmdir($root);
    }

    public function testAValueThatIsNoOptionMapIsShownAsText(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        self::assertInstanceOf(Textarea::class, $field->uiControl(new FieldContext('design', $field->normalizeValue('not json', null))));
    }

    public function testCraftDesignFieldIsAvailableInTwig(): void
    {
        $variable = $this->app->make(CraftVariable::class)->designField();

        self::assertInstanceOf(DesignFieldVariable::class, $variable);
        self::assertSame(['tone', 'border', 'shade', 'variant'], array_keys($variable->groups()));
        self::assertSame('dark', $variable->value(['tone' => 'dark'], 'blockCta')->toArray()['tone']);
    }

    public function testTheSchemaCommandPrintsTheOptionModel(): void
    {
        self::assertSame(0, Artisan::call('design-field:schema'));
        self::assertStringContainsString('"tone"', Artisan::output());
    }

    public function testTheCheckCommandFailsOnAProfileThatMatchesNoType(): void
    {
        // The test install has no entry type called blockCta.
        self::assertSame(1, Artisan::call('design-field:check', ['--json' => true]));

        $findings = json_decode(Artisan::output(), true);
        self::assertSame('error', $findings[0]['level']);
        self::assertStringContainsString('blockCta', $findings[0]['message']);
    }

    public function testTheUsageCommandRunsOnAnInstallWithoutDesignFields(): void
    {
        self::assertSame(0, Artisan::call('design-field:usage'));
        self::assertStringContainsString('0 entry types', Artisan::output());
    }
}
