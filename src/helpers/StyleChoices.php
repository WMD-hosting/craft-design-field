<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use wmd\designfield\models\Group;

/**
 * The look choices on the settings page. Buttons come in three looks: icons (`iconsOnly`),
 * buttons as configured (icon and label, or an "Aa" sample), and labels only (`textOnly`:
 * S · M · L instead of samples).
 *
 * @author WMD
 * @since 1.0.0
 */
class StyleChoices
{
    // Const Properties
    // =========================================================================

    /**
     * Choice value => label.
     */
    public const LABELS = [
        'icons' => 'Icons',
        Group::INPUT_BUTTONS => 'Buttons',
        'labels' => 'Labels only',
        Group::INPUT_SELECT => 'Dropdown',
        Group::INPUT_SLIDER => 'Slider',
        Group::INPUT_TOGGLE => 'Toggle',
        Group::INPUT_CHIP => 'Chip',
        Group::INPUT_SWATCHES => 'Swatches',
        Group::INPUT_TILES => 'Picture tiles',
        Group::INPUT_MOTION => 'Motion tiles',
        Group::INPUT_RENDERED => 'Rendered tiles',
        Group::INPUT_POSITION => 'Position grid',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Choice values a group can take, in menu order.
     *
     * @param Group $group
     * @return list<string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function values(Group $group): array
    {
        $values = [];

        foreach ($group->supportedInputs() as $input) {
            if ($input === Group::INPUT_BUTTONS && self::_everyOptionHasIcon($group)) {
                $values[] = 'icons';
            }
            $values[] = $input;
            if ($input === Group::INPUT_BUTTONS && self::_showsMoreThanText($group)) {
                $values[] = 'labels';
            }
        }

        return $values;
    }

    /**
     * The choice value of a stored style.
     *
     * @param array{input:string,iconsOnly:bool,textOnly?:bool} $style
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function value(array $style): string
    {
        if ($style['input'] !== Group::INPUT_BUTTONS) {
            return $style['input'];
        }

        return $style['iconsOnly'] ? 'icons' : (!empty($style['textOnly']) ? 'labels' : Group::INPUT_BUTTONS);
    }

    /**
     * The stored style of a choice value.
     *
     * @param string $value
     * @return array{input:string,iconsOnly:bool,textOnly:bool}
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function style(string $value): array
    {
        return match ($value) {
            'icons' => ['input' => Group::INPUT_BUTTONS, 'iconsOnly' => true, 'textOnly' => false],
            'labels' => ['input' => Group::INPUT_BUTTONS, 'iconsOnly' => false, 'textOnly' => true],
            default => ['input' => $value, 'iconsOnly' => false, 'textOnly' => false],
        };
    }

    /**
     * One row per option on the site, for the "How options look" table: how many blocks
     * show it, its style before the per-option picks ('' when blocks differ), and the
     * styles it can take on at least one block. Most used first.
     *
     * @param Registry $registry
     * @return list<array{handle:string,label:string,blocks:int,base:string,values:list<string>}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function rows(Registry $registry): array
    {
        $rows = [];

        foreach ($registry->profiles as $profile => $groups) {
            foreach ($groups as $handle => $group) {
                $base = self::value($registry->configInputs[$profile][$handle] ?? ['input' => $group->input, 'iconsOnly' => $group->iconsOnly]);
                $rows[$handle]['label'] ??= $group->label;
                $rows[$handle]['blocks'] = ($rows[$handle]['blocks'] ?? 0) + 1;
                $rows[$handle]['bases'][$base] = true;
                $rows[$handle]['values'] = ($rows[$handle]['values'] ?? []) + array_fill_keys(self::values($group), true);
            }
        }

        $out = [];

        foreach ($rows as $handle => $row) {
            $out[] = [
                'handle' => (string)$handle,
                'label' => $row['label'],
                'blocks' => $row['blocks'],
                'base' => count($row['bases']) === 1 ? (string)array_key_first($row['bases']) : '',
                'values' => array_values(array_filter(array_keys(self::LABELS), static fn(string $value) => isset($row['values'][$value]))),
            ];
        }

        usort($out, static fn(array $a, array $b) => [$b['blocks'], $a['label']] <=> [$a['blocks'], $b['label']]);

        return $out;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param Group $group
     * @return bool
     */
    private static function _showsMoreThanText(Group $group): bool
    {
        foreach ($group->options as $key => $option) {
            if ($key !== Group::AUTO && ($option['icon'] !== null || $option['sample'] !== null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Group $group
     * @return bool
     */
    private static function _everyOptionHasIcon(Group $group): bool
    {
        $real = array_diff_key($group->options, [Group::AUTO => true]);

        return $real !== [] && array_filter($real, static fn(array $option) => $option['icon'] === null) === [];
    }
}
