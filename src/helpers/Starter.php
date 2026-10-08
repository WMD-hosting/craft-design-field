<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use InvalidArgumentException;

/**
 * A first set of options for a site that has none: Section tone, Section spacing, Container,
 * Alignment and Entrance on every block, as rows for the settings tables, with the classes of
 * the CSS framework the site uses. Every option starts at "Block default", so adding the field
 * changes no block until an editor picks something. Entrances (and everything in plain CSS)
 * come from the plugin's stylesheet, `design-field.css`.
 *
 * Craft-free.
 *
 * @author WMD
 * @since 1.0.0
 */
class Starter
{
    // Const Properties
    // =========================================================================

    /**
     * Framework handle => name.
     */
    public const FRAMEWORKS = [
        'tailwind' => 'Tailwind CSS',
        'bootstrap' => 'Bootstrap 5',
        'plain' => 'Plain CSS',
    ];

    /**
     * Option => key => [label, Tailwind, Bootstrap, plain CSS].
     */
    private const CLASSES = [
        'tone' => [
            'none' => ['None', 'bg-transparent', 'bg-transparent', 'df-tone-none'],
            'light' => ['Light', 'bg-gray-50 text-gray-900', 'bg-light text-dark', 'df-tone-light'],
            'dark' => ['Dark', 'bg-gray-900 text-white', 'bg-dark text-white', 'df-tone-dark'],
            'brand' => ['Brand', 'bg-indigo-600 text-white', 'bg-primary text-white', 'df-tone-brand'],
        ],
        'spacing' => [
            'none' => ['None', 'py-0', 'py-0', 'df-space-none'],
            'small' => ['Small', 'py-8', 'py-3', 'df-space-small'],
            'medium' => ['Medium', 'py-16', 'py-5', 'df-space-medium'],
            'large' => ['Large', 'py-24', 'py-5 my-5', 'df-space-large'],
        ],
        'container' => [
            'boxed' => ['Boxed', 'mx-auto max-w-7xl px-4', 'container', 'df-container-boxed'],
            'wide' => ['Wide', 'w-full px-4', 'container-fluid', 'df-container-wide'],
        ],
        'alignment' => [
            'start' => ['Start', 'text-left', 'text-start', 'df-align-start'],
            'center' => ['Center', 'text-center', 'text-center', 'df-align-center'],
            'end' => ['End', 'text-right', 'text-end', 'df-align-end'],
        ],
        // The same classes everywhere: design-field.css plays them.
        'motion' => [
            'none' => ['None', '', '', ''],
            'fade' => ['Fade in', 'df-enter-fade', 'df-enter-fade', 'df-enter-fade'],
            'fade-up' => ['Fade up', 'df-enter-fade-up', 'df-enter-fade-up', 'df-enter-fade-up'],
            'zoom' => ['Zoom in', 'df-enter-zoom', 'df-enter-zoom', 'df-enter-zoom'],
        ],
    ];

    /**
     * Option => [label, instructions, look].
     */
    private const GROUPS = [
        'tone' => ['Section tone', 'Background and text colour of the block.', 'swatches'],
        'spacing' => ['Section spacing', 'Space above and below the block.', 'slider'],
        'container' => ['Container', 'Boxed keeps the content to the page width; Wide runs it edge to edge.', 'buttons'],
        'alignment' => ['Alignment', 'Horizontal alignment of the text.', 'position'],
        'motion' => ['Entrance', 'How the block appears. Visitors who turn off animations see it without motion.', 'motion'],
    ];

    /**
     * Swatch per tone, for the editor only.
     */
    private const SWATCHES = ['none' => 'transparent', 'light' => '#f3f4f6', 'dark' => '#111827', 'brand' => '#4f46e5'];

    /**
     * Icon per alignment, for the position look.
     */
    private const ICONS = ['start' => 'align-left', 'center' => 'align-center', 'end' => 'align-right'];

    // Public Methods
    // =========================================================================

    /**
     * @param string $framework One of FRAMEWORKS' keys
     * @return array{groupRows:list<array<string,string>>,optionRows:list<array<string,string>>,profileRows:list<array<string,string>>}
     * @throws InvalidArgumentException for an unknown framework
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function rows(string $framework): array
    {
        $column = array_search($framework, array_keys(self::FRAMEWORKS), true);

        if ($column === false) {
            throw new InvalidArgumentException("Unknown framework \"$framework\".");
        }

        $groupRows = [];
        $optionRows = [];

        foreach (self::GROUPS as $handle => [$label, $instructions, $look]) {
            $groupRows[] = ['handle' => $handle, 'label' => $label, 'instructions' => $instructions, 'auto' => 'Block default', 'default' => 'auto', 'input' => $look];

            foreach (self::CLASSES[$handle] as $key => $definition) {
                $optionRows[] = array_filter([
                    'group' => $handle,
                    'key' => $key,
                    'label' => $definition[0],
                    'value' => $definition[1 + $column],
                    'swatch' => $handle === 'tone' ? self::SWATCHES[$key] : '',
                    'icon' => $handle === 'alignment' ? self::ICONS[$key] : '',
                ], static fn(string $value) => $value !== '') + ['value' => ''];
            }
        }

        return [
            'groupRows' => $groupRows,
            'optionRows' => $optionRows,
            'profileRows' => [['profile' => '*', 'groups' => implode(', ', array_keys(self::GROUPS))]],
        ];
    }
}
