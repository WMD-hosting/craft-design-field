<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use wmd\designfield\models\Group;
use function CraftCms\Cms\t;

/**
 * The panel in the editor's language.
 *
 * What a site's config names (options, choices, section headings, help text, presets) is
 * the site's own text, so it is translated in Craft's `site` category, as Craft translates
 * the names of fields and entry types: a site gives it its language in
 * `translations/<locale>/site.php` (Craft 6: `lang/<locale>/site.php`). The panel's own
 * words ("Edit", "Most used") are the plugin's, in its category `design-field`.
 *
 * Only what the editor sees in the panel is translated: the settings page, the MCP schema
 * and validation keep the config's words, which are what an admin or an agent writes.
 *
 * @author WMD
 * @since 2.0.0
 */
final class Translations
{
    // Const Properties
    // =========================================================================

    /**
     * The plugin's own category: its handle.
     */
    public const CATEGORY = 'design-field';

    // Public Methods
    // =========================================================================

    /**
     * A text of the site's config in the editor's language; an empty one stays empty.
     *
     * @param string $text
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function site(string $text): string
    {
        return $text === '' ? '' : t($text, category: 'site');
    }

    /**
     * One of the panel's own words in the editor's language.
     *
     * @param string $message
     * @param array<string,mixed> $parameters
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function own(string $message, array $parameters = []): string
    {
        return t($message, $parameters, self::CATEGORY);
    }

    /**
     * The options with every text an editor reads in the editor's language.
     *
     * @param array<string,Group> $groups
     * @return array<string,Group>
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function groups(array $groups): array
    {
        return array_map(static fn(Group $group) => $group->translated(self::site(...)), $groups);
    }
}
