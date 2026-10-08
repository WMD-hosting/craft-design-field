<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\FieldShapes;
use wmd\designfield\models\Group;

final class FieldShapesTest extends TestCase
{
    private function field(string $class, array $extra = []): array
    {
        return $extra + ['class' => $class, 'parents' => [], 'name' => 'Show date', 'instructions' => '', 'multi' => false, 'options' => null, 'settings' => []];
    }

    public function testLightswitchBecomesAnOnOffChipWithItsLabelsAndDefault(): void
    {
        $group = FieldShapes::group($this->field('craft\fields\Lightswitch', ['settings' => ['default' => true, 'onLabel' => 'Shown', 'offLabel' => 'Hidden']]));

        self::assertSame(['off' => ['label' => 'Hidden'], 'on' => ['label' => 'Shown']], $group['options']);
        self::assertSame('on', $group['default']);
        self::assertSame(['0' => 'off', '1' => 'on'], $group['aliases'], 'stored 1/0 resolve on migrate');
        self::assertSame('chip', $group['input']);
        self::assertArrayNotHasKey('auto', $group, 'a chip needs exactly two options');

        $built = Group::fromConfig('showDate', $group, $group['options']);
        self::assertSame('on', $built->resolveKey('1'));
    }

    public function testColorPaletteBecomesSwatchesUnlessCustomColoursAreAllowed(): void
    {
        $palette = ['palette' => [['color' => '#1E40AF', 'label' => 'Navy', 'default' => true], ['color' => '#fbbf24', 'label' => null, 'default' => false], ['color' => '#1e40af', 'label' => 'Dup', 'default' => false], ['color' => 'transparent', 'label' => null, 'default' => false]], 'allowCustomColors' => false];
        $group = FieldShapes::group($this->field('craft\fields\Color', ['settings' => $palette]));

        self::assertSame(['1e40af' => ['label' => 'Navy', 'swatch' => '#1e40af'], 'fbbf24' => ['label' => '#FBBF24', 'swatch' => '#fbbf24']], $group['options']);
        self::assertArrayNotHasKey('default', $group, 'Block default stays the default: a block without a colour must not get one');
        self::assertSame('swatches', $group['input']);
        self::assertNull(FieldShapes::group($this->field('craft\fields\Color', ['settings' => ['allowCustomColors' => true] + $palette])));
    }

    public function testOptionFieldsAndButtonBoxKeepTheirOptions(): void
    {
        $dropdown = FieldShapes::group($this->field('craft\fields\Dropdown', ['parents' => ['craft\fields\BaseOptionsField'], 'options' => [['label' => 'Left', 'value' => 'left'], ['label' => 'Right', 'value' => 'right', 'default' => true], ['optgroup' => 'x'], ['label' => 'Auto', 'value' => 'auto']]]));
        self::assertSame(['left' => ['label' => 'Left'], 'right' => ['label' => 'Right']], $dropdown['options']);
        self::assertSame('Block default', $dropdown['auto']);

        $box = FieldShapes::group($this->field('verbb\buttonbox\fields\Buttons', ['settings' => ['options' => [['label' => 'Small', 'value' => 'sm'], ['label' => 'Large', 'value' => 'lg']]]]));
        self::assertSame(['sm' => ['label' => 'Small'], 'lg' => ['label' => 'Large']], $box['options']);

        self::assertNull(FieldShapes::group($this->field('craft\fields\Checkboxes', ['parents' => ['craft\fields\BaseOptionsField'], 'multi' => true, 'options' => [['label' => 'A', 'value' => 'a']]])), 'multi-select is not one choice');
        self::assertNull(FieldShapes::group($this->field('craft\fields\PlainText')));
    }

    public function testKindNamesTheFieldType(): void
    {
        self::assertSame('Lightswitch', FieldShapes::kind($this->field('craft\fields\Lightswitch')));
        self::assertSame('Color palette', FieldShapes::kind($this->field('craft\fields\Color')));
        self::assertSame('Button Box', FieldShapes::kind($this->field('verbb\buttonbox\fields\Buttons')));
        self::assertNull(FieldShapes::group($this->field('verbb\buttonbox\fields\Stars', ['settings' => ['options' => [['label' => 'One', 'value' => '1']]]])), 'only Button Box Buttons are one choice from a set');
        self::assertSame('Dropdown', FieldShapes::kind($this->field('craft\fields\Dropdown')));
    }
}
