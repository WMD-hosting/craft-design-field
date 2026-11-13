<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use wmd\designfield\models\Group;

/**
 * Arranges a panel's options under their section headings (Layout, Colour, Spacing…):
 * the layout's section first with the layout at its top, then options without a section,
 * then each section in the order its first option comes. Without any section, or inline,
 * the panel stays one plain list.
 *
 * @author WMD
 * @since 1.0.0
 */
class Sections
{
    // Const Properties
    // =========================================================================

    /**
     * The layout option: picked first, since most other options show only for some layouts.
     */
    private const LAYOUT = 'variant';

    // Public Methods
    // =========================================================================

    /**
     * @param array<string,Group> $groups In panel order
     * @param bool $headings False: one list without headings, still in section order
     * @return list<array{label:string,groups:list<string>}> '' label = no heading
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function arrange(array $groups, bool $headings = true): array
    {
        $sections = [];
        $layout = isset($groups[self::LAYOUT]) ? $groups[self::LAYOUT]->section : null;

        if ($layout !== null) {
            $sections[$layout] = [self::LAYOUT];
        }
        foreach ($groups as $handle => $group) {
            if ((string)$handle !== self::LAYOUT) {
                $sections[$group->section][] = (string)$handle;
            }
        }

        $arranged = [];
        if ($layout !== null) {
            $arranged[] = ['label' => $layout, 'groups' => $sections[$layout]];
            unset($sections[$layout]);
        }
        if (isset($sections[''])) {
            $arranged[] = ['label' => '', 'groups' => $sections['']];
            unset($sections['']);
        }
        foreach ($sections as $label => $handles) {
            $arranged[] = ['label' => (string)$label, 'groups' => $handles];
        }

        if (!$headings && $arranged !== []) {
            return [['label' => '', 'groups' => array_merge(...array_column($arranged, 'groups'))]];
        }

        return $arranged;
    }
}
