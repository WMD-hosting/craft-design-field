<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\FormatPicker;
use wmd\designfield\entryfields\Row;

final class CustomFormatsTest extends TestCase
{
    protected function tearDown(): void
    {
        FormatPicker::useCustom([]);
    }

    public function testCustomFormatsJoinTheList(): void
    {
        FormatPicker::useCustom(['docTable' => ['label' => 'Documents: table', 'fits' => ['Assets']]]);

        self::assertContains('docTable', FormatPicker::all());
        self::assertSame(FormatPicker::FORMATS, array_slice(FormatPicker::all(), 0, count(FormatPicker::FORMATS)), 'built-ins first, in order');
        self::assertTrue(FormatPicker::isCustom('docTable'));
        self::assertFalse(FormatPicker::isCustom('files'));
        self::assertSame('Documents: table', FormatPicker::label('docTable'));
        self::assertSame('files', FormatPicker::label('files'));
    }

    public function testCustomFormatFitsOnlyItsFieldTypes(): void
    {
        FormatPicker::useCustom([
            'docTable' => ['label' => 'Documents: table', 'fits' => ['Assets']],
            'tourPrice' => ['fits' => ['Number', 'craft\fields\PlainText']],
        ]);

        self::assertTrue(FormatPicker::fits('docTable', 'craft\fields\Assets'));
        self::assertFalse(FormatPicker::fits('docTable', 'craft\fields\PlainText'));
        self::assertTrue(FormatPicker::fits('tourPrice', 'craft\fields\Number'), 'short names mean craft\fields\…');
        self::assertTrue(FormatPicker::fits('tourPrice', 'craft\fields\PlainText'), 'full class names work too');
        self::assertTrue(FormatPicker::fits('docTable', 'vendor\MyAssets', ['craft\fields\Assets']), 'subclasses fit');
        self::assertSame('tourPrice', FormatPicker::label('tourPrice'), 'label defaults to the name');
    }

    public function testCustomFormatWithoutFitsFitsEverySupportedField(): void
    {
        FormatPicker::useCustom(['anything' => ['label' => 'Anything']]);

        self::assertTrue(FormatPicker::fits('anything', 'craft\fields\Assets'));
        self::assertTrue(FormatPicker::fits('anything', 'craft\fields\PlainText'));
        self::assertFalse(FormatPicker::fits('anything', 'nystudio107\seomatic\fields\SeoSettings'), 'unsupported field types still skip');
    }

    public function testUnsafeOrCollidingNamesAreDropped(): void
    {
        // Names become template paths: no slashes, dots or leading capitals; built-ins can't be replaced.
        FormatPicker::useCustom([
            '../secret' => ['fits' => ['Assets']],
            'doc/table' => ['fits' => ['Assets']],
            'DocTable' => ['fits' => ['Assets']],
            'files' => ['fits' => ['Assets']],
            'ok2' => ['fits' => ['Assets']],
            'notAnArray' => 'Assets',
        ]);

        self::assertSame(['ok2'], array_values(array_diff(FormatPicker::all(), FormatPicker::FORMATS)));
        self::assertFalse(FormatPicker::isCustom('files'));
    }

    public function testRowKeepsARegisteredCustomFormat(): void
    {
        FormatPicker::useCustom(['docTable' => ['fits' => ['Assets']]]);
        self::assertSame('docTable', Row::fromArray(['field' => 'docs', 'slot' => 's', 'format' => 'docTable'])?->format);

        // Removed from config later: the saved row falls back to auto instead of breaking.
        FormatPicker::useCustom([]);
        self::assertSame('auto', Row::fromArray(['field' => 'docs', 'slot' => 's', 'format' => 'docTable'])?->format);
    }
}
