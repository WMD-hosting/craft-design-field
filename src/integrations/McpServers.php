<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\integrations;

use craft\helpers\Json;
use wmd\designfield\fields\Design;
use wmd\designfield\models\DesignValue;
use yii\base\Event;

/**
 * Hands MCP servers the Design value as data: `{"tone": "dark", "spacing": "loose", …}`.
 *
 * Without it, MCP – All in one (`rewi.sh/craft-mcp`) prints the value through
 * `__toString`, so an agent reading an entry sees the block's Tailwind classes and
 * cannot tell which option is picked, let alone change it. Both MCP plugins are
 * optional: nothing is registered when the plugin is not installed.
 *
 * The MCP plugin by stimmt (`stimmt/craft-mcp`) needs nothing here: it reads values
 * through `Design::serializeValue()`, which already returns the keys.
 *
 * @author WMD
 * @since 1.0.5
 */
class McpServers
{
    // Const Properties
    // =========================================================================

    /**
     * MCP – All in one's plugin class, by name: the plugin is optional.
     */
    public const REWISH_MCP = 'rewish\craft\mcp\Mcp';

    /**
     * Its `Mcp::EVENT_REGISTER_FIELD_TRANSLATORS`.
     */
    public const REWISH_REGISTER_FIELD_TRANSLATORS = 'registerFieldTranslators';

    // Public Methods
    // =========================================================================

    /**
     * Registers the Design field translator with MCP – All in one, when it is installed.
     *
     * @param string $mcpClass The MCP plugin's class (tests pass a stand-in)
     *
     * @author WMD
     * @since 1.0.5
     */
    public static function register(string $mcpClass = self::REWISH_MCP): void
    {
        if (!class_exists($mcpClass)) {
            return;
        }

        Event::on($mcpClass, self::REWISH_REGISTER_FIELD_TRANSLATORS, static function(Event $event) {
            // FieldTranslatorsEvent::$translators: field class => callable(field, value, direction)
            if (property_exists($event, 'translators') && is_array($event->translators)) {
                $event->translators[Design::class] = [self::class, 'translate'];
            }
        });
    }

    /**
     * Translates a Design value for an MCP server, both ways.
     *
     * Reads return the stored keys: what `Design::serializeValue()` saves, so a value
     * read, edited and written back keeps options the current layout hides. Writes
     * accept the same object (or its JSON) and hand it to the field to normalize;
     * unknown keys then fail the entry's validation instead of being dropped.
     *
     * @param object $field The field (unused: one translator serves every Design field)
     * @param mixed $value A DesignValue on read; whatever the agent sent on write
     * @param string $direction `read` or `write`
     * @return mixed Group handle => option key, or the value unchanged when it is not a Design value
     *
     * @author WMD
     * @since 1.0.5
     */
    public static function translate(object $field, mixed $value, string $direction = 'read'): mixed
    {
        if ($value instanceof DesignValue) {
            return $value->toArray();
        }

        if (is_string($value)) {
            $decoded = Json::decodeIfJson($value);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ($value === null || $value === '') {
            return [];
        }

        return $value;
    }
}
