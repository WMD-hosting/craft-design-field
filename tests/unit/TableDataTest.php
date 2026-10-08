<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\TableData;

final class TableDataTest extends TestCase
{
    public function testCraftTableUsesColumnHeadingsAndHandles(): void
    {
        $columns = ['col1' => ['handle' => 'c1', 'heading' => 'Spec'], 'col2' => ['handle' => 'c2', 'heading' => 'Value']];
        $rows = [['c1' => 'Weight', 'c2' => '12 kg'], ['col1' => 'Width', 'col2' => '40 cm'], ['c1' => '', 'c2' => ''], 'junk'];

        self::assertSame(
            ['head' => ['Spec', 'Value'], 'rows' => [['Weight', '12 kg'], ['Width', '40 cm']], 'html' => false],
            TableData::fromColumns($columns, $rows),
        );
    }

    public function testUntitledColumnsGiveNoHeader(): void
    {
        self::assertSame([], TableData::fromColumns(['col1' => ['handle' => 'c1', 'heading' => '']], [['c1' => 'x']])['head']);
    }

    public function testCellsFirstRowIsHeader(): void
    {
        $t = TableData::fromCells([['A', 'B'], ['1', '<a href="/x">2</a>'], ['', '']], true);

        self::assertSame(['A', 'B'], $t['head']);
        self::assertSame([['1', '<a href="/x">2</a>']], $t['rows']);
        self::assertTrue($t['html']);
        self::assertSame([], TableData::fromCells([['', ''], ['a', 'b']])['head']);
        self::assertSame(['head' => [], 'rows' => [], 'html' => false], TableData::fromCells([]));
    }

    public function testPairs(): void
    {
        self::assertSame(['head' => [], 'rows' => [['Weight', '12 kg']], 'html' => false], TableData::fromPairs([['Weight', '12 kg'], ['', '']]));
    }
}
