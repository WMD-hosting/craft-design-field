<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use wmd\designfield\models\Group;

/**
 * What an existing field would become as a Design Field group: option fields keep their
 * options, a Lightswitch becomes an on/off chip, a Color field with a fixed palette becomes
 * swatches, Button Box keeps its buttons, Design Tokens point at their token file.
 *
 * Craft-free: works on a field description (Importer::describe()).
 *
 * @author WMD
 * @since 1.0.0
 */
class FieldShapes
{
    // Const Properties
    // =========================================================================

    /**
     * The Design Tokens plugin's field, by name: the plugin is optional.
     */
    public const DESIGN_TOKENS = 'trendyminds\designtokens\fields\DesignTokensField';

    /**
     * Craft's Dropdown, Radio Buttons, Button Group (and multi-select fields, which are skipped).
     */
    public const OPTIONS = 'craft\fields\BaseOptionsField';

    /**
     * Craft's Lightswitch: an on/off chip.
     */
    public const LIGHTSWITCH = 'craft\fields\Lightswitch';

    /**
     * Craft's Color field: swatches when it has a fixed palette.
     */
    public const COLOR = 'craft\fields\Color';

    /**
     * Button Box's buttons (one choice from a set); its Stars, Width and Colours fields are not.
     */
    public const BUTTON_BOX = 'verbb\buttonbox\fields\Buttons';

    // Public Methods
    // =========================================================================

    /**
     * @param array{class:string,parents:string[],name:string,instructions:string,multi:bool,options:?array<int,array<string,mixed>>,settings:array<string,mixed>} $field
     * @return ?array<string,mixed> A group definition, or null when the field is not one choice out of a fixed set
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function group(array $field): ?array
    {
        $is = static fn(string $class) => $field['class'] === $class || in_array($class, $field['parents'], true);
        $base = array_filter(['label' => $field['name'], 'instructions' => $field['instructions']]);

        if ($is(self::DESIGN_TOKENS)) {
            $file = (string)($field['settings']['config'] ?? '');

            return $file === '' ? null : $base + ['tokens' => basename($file, '.json'), 'auto' => 'Block default'];
        }

        if ($is(self::LIGHTSWITCH)) {
            $s = $field['settings'];

            return $base + [
                'options' => [
                    'off' => ['label' => (string)(($s['offLabel'] ?? '') ?: 'Off')],
                    'on' => ['label' => (string)(($s['onLabel'] ?? '') ?: 'On')],
                ],
                'default' => !empty($s['default']) ? 'on' : 'off',
                'aliases' => ['0' => 'off', '1' => 'on'],
                'input' => Group::INPUT_CHIP,
            ];
        }

        if ($is(self::COLOR)) {
            if (!empty($field['settings']['allowCustomColors'])) {
                return null;
            }
            $options = [];
            foreach ((array)($field['settings']['palette'] ?? []) as $swatch) {
                $hex = strtolower(ltrim((string)($swatch['color'] ?? ''), '#'));
                // `transparent` is a valid palette entry but no swatch colour.
                if (!preg_match('/^[0-9a-f]{3,8}$/', $hex) || isset($options[$hex])) {
                    continue;
                }
                $options[$hex] = ['label' => (string)(($swatch['label'] ?? '') ?: '#' . strtoupper($hex)), 'swatch' => "#$hex"];
            }

            // No default from the palette: a block that had no colour keeps none (Block default).
            return $options === [] ? null : $base + ['options' => $options, 'input' => Group::INPUT_SWATCHES, 'auto' => 'Block default'];
        }

        $buttonBox = $field['class'] === self::BUTTON_BOX;
        if (($is(self::OPTIONS) && !$field['multi']) || $buttonBox) {
            $options = self::_options($buttonBox ? (array)($field['settings']['options'] ?? []) : (array)$field['options']);

            return $options === [] ? null : $base + ['options' => $options, 'auto' => 'Block default'];
        }

        return null;
    }

    /**
     * @param array{class:string,parents:string[]} $field
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function kind(array $field): string
    {
        return match (true) {
            $field['class'] === self::LIGHTSWITCH => 'Lightswitch',
            $field['class'] === self::COLOR => 'Color palette',
            $field['class'] === self::BUTTON_BOX => 'Button Box',
            $field['class'] === self::DESIGN_TOKENS => 'Design Tokens',
            default => preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', substr((string)strrchr('\\' . $field['class'], '\\'), 1)) ?? $field['class'],
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * @param array<int,mixed> $raw
     * @return array<string,array<string,string>>
     */
    private static function _options(array $raw): array
    {
        $options = [];
        foreach ($raw as $option) {
            $value = is_array($option) ? (string)($option['value'] ?? '') : '';
            if (!is_array($option) || isset($option['optgroup']) || $value === '' || $value === Group::AUTO) {
                continue;
            }
            $options[$value] = array_filter(['label' => (string)($option['label'] ?? $value), 'icon' => (string)($option['icon'] ?? '')]);
        }

        return $options;
    }
}
