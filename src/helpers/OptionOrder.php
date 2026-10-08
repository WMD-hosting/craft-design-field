<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use wmd\designfield\models\Group;

/**
 * Long dropdowns show the choices editors pick most at the top ("Most used"), the others
 * after them in their usual order. Counts come from the usage report (cached).
 *
 * Craft-free.
 *
 * @author WMD
 * @since 1.0.0
 */
class OptionOrder
{
    // Const Properties
    // =========================================================================

    /**
     * Lists at least this long get a "Most used" group.
     */
    public const LONG = 10;

    /**
     * How many choices the "Most used" group holds.
     */
    public const TOP = 3;

    // Public Methods
    // =========================================================================

    /**
     * @param list<string> $keys The option keys in their usual order
     * @param array<string,int> $counts Key => how many blocks picked it
     * @return array{top:list<string>,rest:list<string>} `top` empty: show the list as it is
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function split(array $keys, array $counts): array
    {
        if (count($keys) < self::LONG) {
            return ['top' => [], 'rest' => $keys];
        }

        // By use, ties in list order; "Block default" is not a choice anyone looks for.
        $used = array_values(array_filter($keys, static fn(string $key) => $key !== Group::AUTO && ($counts[$key] ?? 0) > 0));
        $position = array_flip($keys);
        usort($used, static fn(string $a, string $b) => [$counts[$b], $position[$a]] <=> [$counts[$a], $position[$b]]);
        $top = array_slice($used, 0, self::TOP);

        return ['top' => $top, 'rest' => array_values(array_diff($keys, $top))];
    }

    /**
     * Counts for one block type's options: the type's own picks, and for an option nobody
     * picked on this type yet, the picks across the whole site (few blocks of a type say little).
     *
     * @param array<string,array<string,array<string,int>>> $all Entry type => option => key => count
     * @param ?string $type Entry type handle; null for the whole site
     * @return array<string,array<string,int>> Option => key => count
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function countsFor(array $all, ?string $type): array
    {
        $site = [];
        foreach ($all as $groups) {
            foreach ($groups as $handle => $counts) {
                foreach ($counts as $key => $n) {
                    $site[$handle][$key] = ($site[$handle][$key] ?? 0) + $n;
                }
            }
        }

        $own = $type !== null ? ($all[$type] ?? []) : [];
        foreach ($site as $handle => $counts) {
            if (array_sum($own[$handle] ?? []) === 0) {
                $own[$handle] = $counts;
            }
        }

        return $own;
    }
}
