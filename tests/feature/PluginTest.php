<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Field\FieldTypes;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\ContentBlock;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Textarea;
use Illuminate\Support\Facades\Artisan;
use wmd\designfield\fields\Design;
use wmd\designfield\models\DesignValue;
use wmd\designfield\services\Groups;
use wmd\designfield\variables\DesignFieldVariable;

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
                    ],
                    'profiles' => ['blockCta' => ['tone', 'border', 'shade']],
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

    public function testAValueThatIsNoOptionMapIsShownAsText(): void
    {
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        self::assertInstanceOf(Textarea::class, $field->uiControl(new FieldContext('design', $field->normalizeValue('not json', null))));
    }

    public function testCraftDesignFieldIsAvailableInTwig(): void
    {
        $variable = $this->app->make(CraftVariable::class)->designField();

        self::assertInstanceOf(DesignFieldVariable::class, $variable);
        self::assertSame(['tone', 'border', 'shade'], array_keys($variable->groups()));
        self::assertSame('dark', $variable->value(['tone' => 'dark'], 'blockCta')->toArray()['tone']);
    }

    public function testTheSchemaCommandPrintsTheOptionModel(): void
    {
        self::assertSame(0, Artisan::call('design-field:schema'));
        self::assertStringContainsString('"tone"', Artisan::output());
    }
}
