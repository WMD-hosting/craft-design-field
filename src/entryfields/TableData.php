<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * One table shape for the `table` format, from a Craft Table, a grid of cells
 * (Legs) or label/value pairs (Matrix items). Empty rows are dropped.
 *
 * @author WMD
 * @since 1.1.0
 */
final class TableData
{
    /**
     * @param array<string,mixed> $columns Craft Table `columns` setting: key => ['handle' => …, 'heading' => …]
     * @param array<int|string,mixed> $rows
     * @return array{head:list<string>, rows:list<list<string>>, html:bool}
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function fromColumns(array $columns, array $rows): array
    {
        $head = [];
        $keys = [];
        foreach ($columns as $key => $column) {
            $head[] = is_array($column) ? self::_cell($column['heading'] ?? '') : '';
            $keys[] = [(string)$key, is_array($column) ? self::_cell($column['handle'] ?? '') : ''];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = array_map(static fn(array $k) => self::_cell($row[$k[1]] ?? $row[$k[0]] ?? ''), $keys);
        }

        return self::_shape($head, $out, false);
    }

    /**
     * @param array<int,mixed> $cells Rows of cells; the first row is the header
     * @param bool $html Whether cells hold (already purified) HTML
     * @return array{head:list<string>, rows:list<list<string>>, html:bool}
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function fromCells(array $cells, bool $html = false): array
    {
        $rows = [];
        foreach ($cells as $row) {
            if (is_array($row)) {
                $rows[] = array_map(self::_cell(...), array_values($row));
            }
        }
        $head = array_shift($rows) ?? [];

        return self::_shape($head, $rows, $html);
    }

    /**
     * @param list<array{0:mixed,1:mixed}> $pairs
     * @return array{head:list<string>, rows:list<list<string>>, html:bool}
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function fromPairs(array $pairs): array
    {
        return self::_shape([], array_map(static fn(array $p) => [self::_cell($p[0] ?? ''), self::_cell($p[1] ?? '')], $pairs), false);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param list<string> $head
     * @param list<list<string>> $rows
     * @return array{head:list<string>, rows:list<list<string>>, html:bool}
     */
    private static function _shape(array $head, array $rows, bool $html): array
    {
        return [
            'head' => implode('', $head) === '' ? [] : $head,
            'rows' => array_values(array_filter($rows, static fn(array $r) => implode('', $r) !== '')),
            'html' => $html,
        ];
    }

    private static function _cell(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
