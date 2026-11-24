<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use wmd\designfield\models\Group;

/**
 * Deals a panel's sections into two columns of about the same height (the field's
 * "Sections side by side" layout). The split keeps the order: the left column is the
 * first sections, the right one the rest, so reading down the left column and then the
 * right one is the order of the panel, and of the single column a narrow panel shows.
 *
 * Heights are estimates in half rows of a plain control; the browser is never asked.
 *
 * @author WMD
 * @since 2.0.0
 */
class SideColumns
{
    // Const Properties
    // =========================================================================

    /**
     * A section's heading.
     */
    private const HEADING = 1;

    /**
     * A row of buttons, a dropdown, a switch, swatches.
     */
    private const PLAIN = 2;

    /**
     * Taller controls: a slider with its stops, drawn cards, layout pictures.
     */
    private const TALL = [
        Group::INPUT_SLIDER => 3,
        Group::INPUT_RENDERED => 3,
        Group::INPUT_MOTION => 4,
        Group::INPUT_TILES => 8,
    ];

    // Public Methods
    // =========================================================================

    /**
     * How tall a section is, about: 0 when it has nothing to show.
     *
     * @param list<string> $inputs The input of each option the section shows (Group::INPUT_*)
     * @param bool $heading Whether the section has a heading
     * @return int
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function weight(array $inputs, bool $heading = true): int
    {
        if ($inputs === []) {
            return 0;
        }

        return array_sum(array_map(static fn(string $input) => self::TALL[$input] ?? self::PLAIN, $inputs)) + ($heading ? self::HEADING : 0);
    }

    /**
     * How many of the sections go in the left column: the split that leaves the taller
     * column as short as it can be, the left one taking the extra where two splits tie.
     * Null when fewer than two sections have something to show: nothing to stand side
     * by side, so they keep the whole row.
     *
     * @param list<int> $weights Each section's weight, in panel order (0 = nothing to show)
     * @return int|null
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function split(array $weights): ?int
    {
        $shown = array_keys(array_filter($weights));
        if (count($shown) < 2) {
            return null;
        }

        $total = array_sum($weights);
        $best = null;
        $tallest = PHP_INT_MAX;
        $left = 0;

        // A split point lies between two sections that show something, so neither column is empty.
        foreach (array_slice($shown, 1) as $at) {
            $left = array_sum(array_slice($weights, 0, $at));
            $taller = max($left, $total - $left);

            if ($taller < $tallest || ($taller === $tallest && $left >= $total - $left)) {
                $tallest = $taller;
                $best = $at;
            }
        }

        return $best;
    }
}
