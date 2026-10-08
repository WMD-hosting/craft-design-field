<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\EntryFieldsValue;
use wmd\designfield\entryfields\Row;

final class EntryFieldsValueTest extends TestCase
{
    public function testRowDefaultsAndWhitelists(): void
    {
        $row = Row::fromArray(['field' => 'trajanje', 'slot' => 'below-title', 'tag' => 'script', 'style' => '', 'format' => '']);

        self::assertNotNull($row);
        self::assertSame('p', $row->tag, 'script is not whitelisted, falls back to p');
        self::assertSame('h1', Row::fromArray(['field' => 't', 'slot' => 's', 'tag' => 'h1'])->tag, 'the ordered variant renders the title as h1');
        self::assertSame('default', $row->style);
        self::assertSame('auto', $row->format);
        self::assertSame('', $row->label);
        self::assertFalse($row->divider);
    }

    public function testRowWithoutFieldIsDropped(): void
    {
        self::assertNull(Row::fromArray(['field' => '  ', 'slot' => 'below-title']));
        self::assertNull(Row::fromArray(['slot' => 'below-title']));
    }

    public function testRowWithoutSlotIsDropped(): void
    {
        self::assertNull(Row::fromArray(['field' => 'trajanje']));
    }

    public function testFromMixedAcceptsJsonArrayNullAndGarbage(): void
    {
        $json = '{"preset":"authorLine","rows":[{"field":"autor","slot":"below-title"}]}';

        self::assertSame('authorLine', EntryFieldsValue::fromMixed($json)->preset);
        self::assertCount(1, EntryFieldsValue::fromMixed($json)->rows);
        self::assertTrue(EntryFieldsValue::fromMixed(null)->isEmpty());
        self::assertTrue(EntryFieldsValue::fromMixed('')->isEmpty());
        self::assertTrue(EntryFieldsValue::fromMixed('not json')->isEmpty());
        self::assertTrue(EntryFieldsValue::fromMixed(42)->isEmpty());
        self::assertTrue(EntryFieldsValue::fromMixed(['rows' => 'nope'])->isEmpty());
    }

    public function testCpTableRowsArePlainListWithoutPresetKey(): void
    {
        // The CP editable table posts rows keyed by row id, plus a sibling `preset`.
        $value = EntryFieldsValue::fromMixed([
            'preset' => '',
            'rows' => ['row1' => ['field' => 'a', 'slot' => 's'], 'row2' => ['field' => '', 'slot' => 's']],
        ]);

        self::assertNull($value->preset, 'empty preset normalizes to null');
        self::assertCount(1, $value->rows);
        self::assertSame(['preset' => null, 'rows' => [$value->rows[0]->toArray()]], $value->toArray());
    }

    public function testRowsForMergesPresetFirstAndFiltersBySlot(): void
    {
        $value = EntryFieldsValue::fromMixed([
            'preset' => 'authorLine',
            'rows' => [['field' => 'trajanje', 'slot' => 'below-title'], ['field' => 'cijena', 'slot' => 'below-body']],
        ]);
        $presets = ['authorLine' => [['field' => 'autor', 'slot' => 'below-title', 'style' => 'byline']]];

        $rows = $value->rowsFor('below-title', $presets);

        self::assertSame(['autor', 'trajanje'], array_map(static fn(Row $r) => $r->field, $rows));
        self::assertSame('byline', $rows[0]->style);
    }

    public function testUnknownPresetIsIgnored(): void
    {
        $value = EntryFieldsValue::fromMixed(['preset' => 'gone', 'rows' => [['field' => 'a', 'slot' => 's']]]);

        self::assertCount(1, $value->rowsFor('s', []));
    }

    public function testUnknownOrTamperedFormatFallsBackToAuto(): void
    {
        self::assertSame('auto', Row::fromArray(['field' => 't', 'slot' => 's', 'format' => '../../_layout/_preview'])->format);
        self::assertSame('auto', Row::fromArray(['field' => 't', 'slot' => 's', 'format' => 'sparkline'])->format);
        self::assertSame('badge', Row::fromArray(['field' => 't', 'slot' => 's', 'format' => 'badge'])->format);
    }

    public function testElementRowNeedsNoField(): void
    {
        $row = Row::fromArray(['field' => '', 'slot' => 's', 'format' => 'element', 'element' => '42']);

        self::assertNotNull($row);
        self::assertSame(42, $row->element);
        self::assertSame('', $row->field);
        self::assertSame(42, $row->toArray()['element']);
        self::assertNull(Row::fromArray(['field' => '', 'slot' => 's', 'format' => 'element']), 'element format without an element is dropped');
        self::assertNull(Row::fromArray(['field' => '', 'slot' => 's', 'format' => 'text', 'element' => 42]), 'only element rows may omit the field');
        self::assertNull(Row::fromArray(['field' => 'a', 'slot' => 's', 'element' => 'x'])->element);
    }

    public function testColumnIsWhitelisted(): void
    {
        self::assertSame('gallery', Row::fromArray(['field' => 'a', 'slot' => 's', 'column' => 'gallery'])->column);
        self::assertNull(Row::fromArray(['field' => 'a', 'slot' => 's', 'column' => 'sidebar'])->column);
        self::assertSame('below', Row::fromArray(['field' => 'a', 'slot' => 's', 'column' => 'below'])->column);
        self::assertSame('details', Row::fromArray(['field' => 'a', 'slot' => 's', 'column' => 'details'])->toArray()['column']);
    }
}
