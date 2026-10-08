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
 * options without a section first, then each section in the order its first option
 * comes. Without any section, or inline, the panel stays one plain list.
 *
 * @author WMD
 * @since 1.0.0
 */
class Sections
{
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

        foreach ($groups as $handle => $group) {
            $sections[$group->section][] = (string)$handle;
        }

        $arranged = [];
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
