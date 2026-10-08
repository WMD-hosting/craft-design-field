<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\FormatPicker;

final class FormatPickerTest extends TestCase
{
    public function testAutoByFieldClass(): void
    {
        self::assertSame('text', FormatPicker::auto('craft\fields\PlainText'));
        self::assertSame('rich', FormatPicker::auto('craft\ckeditor\Field'));
        self::assertSame('badge', FormatPicker::auto('craft\fields\Lightswitch'));
        self::assertSame('files', FormatPicker::auto('craft\fields\Assets'));
        self::assertSame('items', FormatPicker::auto('craft\fields\Matrix'), 'renderer refines Matrix with forMatrix()');
        self::assertSame('text', FormatPicker::auto('craft\fields\Entries'), 'relations join titles until `chips` ships');
    }

    public function testAutoFollowsParentClass(): void
    {
        self::assertSame('items', FormatPicker::auto('verbb\hyper\SomeMatrixChild', ['craft\fields\Matrix', 'craft\base\Field']));
    }

    public function testUnsupportedFieldTypesHaveNoFormat(): void
    {
        // No format can print these (plugin objects, forms, code): skip, don't guess.
        self::assertNull(FormatPicker::auto('vendor\Unknown'));
        self::assertNull(FormatPicker::auto('nystudio107\seomatic\fields\SeoSettings'));
        self::assertNull(FormatPicker::auto('wmd\designfield\fields\Design'));
    }

    public function testScalarAndRelationFieldsUseText(): void
    {
        foreach (['craft\fields\Number', 'craft\fields\Dropdown', 'craft\fields\Categories', 'craft\fields\Tags', 'craft\fields\Users'] as $class) {
            self::assertSame('text', FormatPicker::auto($class), $class);
        }
    }

    public function testExplicitFormatOnUnsupportedFieldDoesNotFit(): void
    {
        self::assertFalse(FormatPicker::fits('text', 'nystudio107\seomatic\fields\SeoSettings'));
    }

    public function testNativeAttributes(): void
    {
        self::assertSame('text', FormatPicker::forNative('title'));
        self::assertSame('date', FormatPicker::forNative('postDate'));
        self::assertSame('price', FormatPicker::forNative('price'));
        self::assertSame('links', FormatPicker::forNative('url'));
        self::assertTrue(FormatPicker::nativeFits('text', 'postDate'));
        self::assertTrue(FormatPicker::nativeFits('date', 'postDate'));
        self::assertFalse(FormatPicker::nativeFits('table', 'title'));
    }

    public function testFits(): void
    {
        self::assertTrue(FormatPicker::fits('auto', 'craft\fields\Assets'));
        self::assertTrue(FormatPicker::fits('text', 'craft\fields\Assets'), 'text fits every supported type');
        self::assertTrue(FormatPicker::fits('badge', 'craft\fields\Lightswitch'));
        self::assertFalse(FormatPicker::fits('badge', 'craft\fields\PlainText'));
        self::assertFalse(FormatPicker::fits('pageBuilder', 'craft\fields\Assets'));
        self::assertFalse(FormatPicker::fits('nope', 'craft\fields\PlainText'));
    }

    public function testRoundTwoAutoFormats(): void
    {
        $expect = [
            'craft\fields\Date' => 'date',
            'craft\fields\Time' => 'time',
            'craft\fields\Money' => 'price',
            'craft\fields\Table' => 'table',
            'justinholtweb\legs\fields\TableField' => 'table',
            'verbb\hyper\fields\HyperField' => 'links',
            'craft\fields\Link' => 'links',
            'craft\fields\Url' => 'links',
            'craft\fields\Email' => 'links',
            'justinholtweb\awesemo\fields\IconField' => 'icon',
            'craft\fields\Color' => 'color',
            'craft\fields\Addresses' => 'map',
            'craft\fields\Entries' => 'text',
        ];
        foreach ($expect as $class => $format) {
            self::assertSame($format, FormatPicker::auto($class), $class);
        }
        self::assertNull(FormatPicker::auto('nystudio107\codefield\fields\Code'));
        self::assertNull(FormatPicker::auto('verbb\formie\fields\Forms'));
    }

    public function testRoundTwoFits(): void
    {
        self::assertTrue(FormatPicker::fits('chips', 'craft\fields\Entries'));
        self::assertTrue(FormatPicker::fits('links', 'craft\fields\Entries'));
        self::assertTrue(FormatPicker::fits('price', 'craft\fields\Number'));
        self::assertTrue(FormatPicker::fits('table', 'craft\fields\Matrix'));
        self::assertTrue(FormatPicker::fits('map', 'craft\fields\PlainText'));
        self::assertTrue(FormatPicker::fits('image', 'craft\fields\Assets'));
        self::assertFalse(FormatPicker::fits('chips', 'craft\fields\PlainText'));
        self::assertFalse(FormatPicker::fits('date', 'craft\fields\PlainText'));
        self::assertFalse(FormatPicker::fits('element', 'craft\fields\PlainText'), 'element rows have no field');
        self::assertTrue(FormatPicker::fits('cover', 'craft\fields\Assets'));
        self::assertFalse(FormatPicker::fits('cover', 'craft\fields\PlainText'));
    }

    public function testCarouselFitsAssetsOnly(): void
    {
        self::assertContains('carousel', FormatPicker::all());
        self::assertTrue(FormatPicker::fits('carousel', 'craft\fields\Assets'));
        self::assertTrue(FormatPicker::fits('carousel', 'vendor\fields\MyAssets', ['craft\fields\Assets']), 'subclasses of Assets fit too');
        self::assertFalse(FormatPicker::fits('carousel', 'craft\fields\Entries'));
        self::assertFalse(FormatPicker::fits('carousel', 'craft\fields\Matrix'));
        self::assertFalse(FormatPicker::nativeFits('carousel', 'title'), 'no native attribute holds images');
    }

    public function testCarouselIsNeverAuto(): void
    {
        self::assertSame('files', FormatPicker::auto('craft\fields\Assets'));
        self::assertNotSame('carousel', FormatPicker::refineAssets(['image', 'image', 'image']), 'many images still default to the grid');
    }

    public function testMatrixShape(): void
    {
        $blocks = [['handle' => 'blockText', 'fields' => ['body'], 'block' => true], ['handle' => 'blockCta', 'fields' => [], 'block' => true]];
        $places = [['handle' => 'blockRichItem', 'fields' => ['heading', 'coords', 'body'], 'block' => false]];
        $events = [['handle' => 'blockEventItem', 'fields' => ['date', 'time', 'location', 'heading'], 'block' => false]];
        $specs = [['handle' => 'blockFeatureItem', 'fields' => ['heading', 'body'], 'block' => false]];
        $mixed = [['handle' => 'a', 'fields' => ['heading'], 'block' => false], ['handle' => 'b', 'fields' => ['coords'], 'block' => false]];

        self::assertSame('pageBuilder', FormatPicker::forMatrix($blocks));
        self::assertSame('map', FormatPicker::forMatrix($places));
        self::assertSame('dates', FormatPicker::forMatrix($events));
        self::assertSame('items', FormatPicker::forMatrix($specs));
        self::assertSame('map', FormatPicker::forMatrix($mixed));
        self::assertSame('items', FormatPicker::forMatrix([]));
        self::assertSame('items', FormatPicker::forMatrix([...$blocks, ...$specs]), 'one non-block type means not a page builder');
    }

    public function testRefinements(): void
    {
        self::assertSame('image', FormatPicker::refineAssets(['image', 'image']));
        self::assertSame('files', FormatPicker::refineAssets(['image', 'pdf']));
        self::assertSame('files', FormatPicker::refineAssets([]));
        self::assertSame('date', FormatPicker::refineDate(true, false));
        self::assertSame('dateTime', FormatPicker::refineDate(true, true));
        self::assertSame('time', FormatPicker::refineDate(false, true));
    }

    public function testProductParts(): void
    {
        self::assertSame('part', FormatPicker::forNative('variants'));
        self::assertSame('part', FormatPicker::forNative('gallery'));
        self::assertSame('part', FormatPicker::forNative('specs'));
        self::assertSame('part', FormatPicker::forNative('similar'));
        self::assertTrue(FormatPicker::nativeFits('part', 'price'));
        self::assertTrue(FormatPicker::nativeFits('price', 'price'));
        self::assertFalse(FormatPicker::nativeFits('part', 'url'));
    }
}
