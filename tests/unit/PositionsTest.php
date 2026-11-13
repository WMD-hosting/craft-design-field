<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Positions;

final class PositionsTest extends TestCase
{
    public function testNinePointGridInGridOrder(): void
    {
        $keys = ['center', 'top-left', 'top', 'top-right', 'left', 'right', 'bottom-left', 'bottom', 'bottom-right'];
        $match = Positions::match($keys);

        self::assertNotNull($match);
        self::assertSame(Positions::GRID, $match['set']);
        self::assertSame(3, $match['columns']);
        self::assertSame(
            ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'],
            array_keys($match['cells']),
            'cells come in grid order, whatever order the config lists them in',
        );
        self::assertSame([1, 1], $match['cells']['center']);
        self::assertSame([2, 1], $match['cells']['bottom']);
    }

    public function testKeySpellings(): void
    {
        foreach ([
            ['topLeft', 'topCenter', 'topRight', 'centerLeft', 'centerCenter', 'centerRight', 'bottomLeft', 'bottomCenter', 'bottomRight'],
            ['tl', 'tc', 'tr', 'ml', 'mc', 'mr', 'bl', 'bc', 'br'],
            ['left top', 'center top', 'right top', 'left center', 'center', 'right center', 'left bottom', 'center bottom', 'right bottom'],
            ['top_start', 'top', 'top_end', 'start', 'middle', 'end', 'bottom_start', 'bottom', 'bottom_end'],
        ] as $keys) {
            self::assertSame(Positions::GRID, Positions::match($keys)['set'] ?? null, implode(', ', $keys));
        }
    }

    public function testCornersRowAndColumn(): void
    {
        $corners = Positions::match(['bottom-right', 'top-left', 'top-right', 'bottom-left']);
        self::assertSame(Positions::CORNERS, $corners['set'] ?? null);
        self::assertSame(2, $corners['columns']);
        self::assertSame(['top-left', 'top-right', 'bottom-left', 'bottom-right'], array_keys($corners['cells']));

        $row = Positions::match(['start', 'center', 'end']);
        self::assertSame(Positions::ROW, $row['set'] ?? null);
        self::assertSame(0, $row['columns'], 'a row is one line of cells');
        self::assertSame([1, 0], $row['cells']['start']);

        $column = Positions::match(['bottom', 'top', 'middle']);
        self::assertSame(Positions::COLUMN, $column['set'] ?? null);
        self::assertSame(1, $column['columns']);
        self::assertSame(['top', 'middle', 'bottom'], array_keys($column['cells']));
    }

    public function testIncompleteOrUnknownKeysAreNoSet(): void
    {
        self::assertNull(Positions::match([]));
        self::assertNull(Positions::match(['sm', 'md', 'lg']), 'sizes are not positions');
        self::assertNull(Positions::match(['1', '2', '3']));
        self::assertNull(Positions::match(['top-left', 'top-right', 'center']), 'part of a grid');
        self::assertNull(Positions::match(['left', 'right']), 'a row needs its middle');
        self::assertNull(Positions::match(['left', 'center', 'middle']), 'two keys for one cell');
        self::assertNull(Positions::match(['square', '4-3', '16-9']));
    }
}
