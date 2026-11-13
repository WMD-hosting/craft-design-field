<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

/**
 * Position choices recognised by their keys: a nine-point grid, the four corners, a
 * horizontal row (left, center, right) or a vertical column (top, middle, bottom).
 *
 * A position option whose keys name one of these sets needs no `columns` setting: its
 * cells are laid out in that shape, in grid order whatever order the config lists
 * them in, and a row or column draws where each cell sits. Only a complete set counts;
 * any other keys stay a plain row of cells.
 *
 * @author WMD
 * @since 2.0.0
 */
final class Positions
{
    // Const Properties
    // =========================================================================

    /**
     * A nine-point grid, three to a row.
     */
    public const GRID = 'grid';

    /**
     * The four corners, two to a row.
     */
    public const CORNERS = 'corners';

    /**
     * Left, center and right in one row.
     */
    public const ROW = 'row';

    /**
     * Top, middle and bottom in one column.
     */
    public const COLUMN = 'column';

    /**
     * Words for a row (top), a column (left) or the middle of either, as keys use them.
     */
    private const ROWS = ['top' => 0, 't' => 0, 'middle' => 1, 'center' => 1, 'centre' => 1, 'm' => 1, 'c' => 1, 'bottom' => 2, 'b' => 2];

    private const COLUMNS = ['left' => 0, 'start' => 0, 'l' => 0, 'center' => 1, 'centre' => 1, 'middle' => 1, 'c' => 1, 'm' => 1, 'right' => 2, 'end' => 2, 'r' => 2];

    // Public Methods
    // =========================================================================

    /**
     * The set the keys name, with each key's cell; null when they name no complete set.
     *
     * Cells are `[row, column]` in a three by three frame (a corner set uses rows and
     * columns 0 and 2, a row set row 1, a column set column 1).
     *
     * @param list<string> $keys The option keys, without `auto`
     * @return ?array{set:string,columns:int,cells:array<string,array{int,int}>}
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function match(array $keys): ?array
    {
        if ($keys === []) {
            return null;
        }

        $cells = [];

        foreach ($keys as $key) {
            $cell = self::_cell((string)$key);

            if ($cell === null) {
                return null;
            }

            $cells[(string)$key] = $cell;
        }

        $set = self::_set($cells);

        if ($set === null) {
            return null;
        }

        $cells = self::_place($cells, $set);
        $taken = array_map(static fn(array $cell) => "$cell[0]:$cell[1]", $cells);

        // Two keys for one cell (`center` and `middle`): not a position set.
        if (count(array_unique($taken)) !== count($taken)) {
            return null;
        }

        uasort($cells, static fn(array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return [
            'set' => $set,
            'columns' => match ($set) {
                self::GRID => 3,
                self::CORNERS => 2,
                self::COLUMN => 1,
                default => 0,
            },
            'cells' => $cells,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads one key as a cell: `top-left`, `topLeft`, `top_left`, `left top`, `tl`,
     * or one word (`top`, `left`, `center`), whose missing half is null.
     *
     * @param string $key
     * @return ?array{?int,?int}
     */
    private static function _cell(string $key): ?array
    {
        $words = preg_split('/[\s_\-]+|(?<=[a-z])(?=[A-Z])/', trim($key)) ?: [];
        $words = array_values(array_filter(array_map('strtolower', $words), static fn(string $word) => $word !== ''));

        // Two-letter shorthand: tl, tc, tr, ml, mc, mr, bl, bc, br.
        if (count($words) === 1 && strlen($words[0]) === 2 && isset(self::ROWS[$words[0][0]], self::COLUMNS[$words[0][1]])) {
            return [self::ROWS[$words[0][0]], self::COLUMNS[$words[0][1]]];
        }

        // Single letters only count as half of a shorthand pair.
        $words = array_filter($words, static fn(string $word) => strlen($word) > 1);

        if (count($words) === 1) {
            $word = reset($words);

            return match (true) {
                in_array($word, ['center', 'centre', 'middle'], true) => [null, null],
                isset(self::ROWS[$word]) => [self::ROWS[$word], null],
                isset(self::COLUMNS[$word]) => [null, self::COLUMNS[$word]],
                default => null,
            };
        }

        if (count($words) !== 2) {
            return null;
        }

        [$first, $second] = array_values($words);

        // "center center" or "middle center": the middle cell.
        if (in_array($first, ['center', 'centre', 'middle'], true) && in_array($second, ['center', 'centre', 'middle'], true)) {
            return [1, 1];
        }

        // Row word first (top-left), or column word first (left-top).
        if (isset(self::ROWS[$first]) && isset(self::COLUMNS[$second]) && !isset(self::COLUMNS[$first])) {
            return [self::ROWS[$first], self::COLUMNS[$second]];
        }

        if (isset(self::COLUMNS[$first]) && isset(self::ROWS[$second]) && !isset(self::ROWS[$first])) {
            return [self::ROWS[$second], self::COLUMNS[$first]];
        }

        // "center left" / "middle right": the word that is only a column decides.
        if (in_array($first, ['center', 'centre', 'middle'], true) && isset(self::COLUMNS[$second])) {
            return [1, self::COLUMNS[$second]];
        }

        if (in_array($first, ['center', 'centre', 'middle'], true) && isset(self::ROWS[$second])) {
            return [self::ROWS[$second], 1];
        }

        return null;
    }

    /**
     * Which complete set the cells make.
     *
     * @param array<string,array{?int,?int}> $cells
     * @return ?string
     */
    private static function _set(array $cells): ?string
    {
        $count = count($cells);
        $full = array_filter($cells, static fn(array $cell) => $cell[0] !== null && $cell[1] !== null);
        $corners = array_filter($full, static fn(array $cell) => $cell[0] !== 1 && $cell[1] !== 1);

        // Any two-word cell makes it a grid: the one-word keys are its edges and center.
        if ($full !== []) {
            if ($count === 4 && count($corners) === 4) {
                return self::CORNERS;
            }

            return $count === 9 ? self::GRID : null;
        }

        $rows = array_filter($cells, static fn(array $cell) => $cell[0] !== null);
        $columns = array_filter($cells, static fn(array $cell) => $cell[1] !== null);

        // One-word keys only: three across (left, center, right) or three down.
        if ($count === 3 && count($columns) === 2 && $rows === []) {
            return self::ROW;
        }

        if ($count === 3 && count($rows) === 2 && $columns === []) {
            return self::COLUMN;
        }

        // top, left, center, right, bottom and the corners spelled as single words do
        // not occur: nine one-word keys cannot be told apart.
        return null;
    }

    /**
     * Fills in the half a one-word key leaves open, for the set it belongs to.
     *
     * @param array<string,array{?int,?int}> $cells
     * @param string $set
     * @return array<string,array{int,int}>
     */
    private static function _place(array $cells, string $set): array
    {
        return array_map(static fn(array $cell) => match ($set) {
            self::ROW => [1, $cell[1] ?? 1],
            self::COLUMN => [$cell[0] ?? 1, 1],
            default => [$cell[0] ?? 1, $cell[1] ?? 1],
        }, $cells);
    }
}
