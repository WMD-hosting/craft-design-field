<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Field\FieldTypes;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Twig\Variables\CraftVariable;
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
                    ],
                    'profiles' => ['blockCta' => ['tone']],
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
        self::assertSame(['tone' => 'dark'], $field->serializeValue($value, null));

        $posted = $field->normalizeValueFromRequest('{"tone":"dark"}', null);
        self::assertSame(['tone' => 'dark'], $posted->toArray());

        self::assertSame(['tone' => 'neon'], $field->normalizeValue(['tone' => 'neon'], null)->invalid());
    }

    public function testCraftDesignFieldIsAvailableInTwig(): void
    {
        $variable = $this->app->make(CraftVariable::class)->designField();

        self::assertInstanceOf(DesignFieldVariable::class, $variable);
        self::assertSame(['tone'], array_keys($variable->groups()));
        self::assertSame(['tone' => 'dark'], $variable->value(['tone' => 'dark'], 'blockCta')->toArray());
    }

    public function testTheSchemaCommandPrintsTheOptionModel(): void
    {
        self::assertSame(0, Artisan::call('design-field:schema'));
        self::assertStringContainsString('"tone"', Artisan::output());
    }
}
