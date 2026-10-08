<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\variables;

use ArrayAccess;
use Craft;
use craft\base\ElementInterface;
use wmd\designfield\fields\Design;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\Plugin;
use wmd\designfield\web\assets\starter\StarterAsset;

/**
 * `craft.designField` in Twig.
 *
 * @author WMD
 * @since 1.0.0
 */
class DesignFieldVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Builds a value from a plain map, for showcases and mock data:
     * `craft.designField.value({ tone: 'surface' }, 'blockCtaDesign')`.
     *
     * @param array<string,mixed> $keys
     * @param ?string $profile
     * @return DesignValue
     *
     * @author WMD
     * @since 1.0.0
     */
    public function value(array $keys = [], ?string $profile = null): DesignValue
    {
        return Plugin::getInstance()->getGroups()->getRegistry()->value($keys, $profile);
    }

    /**
     * The design value of any block: one way to read options while blocks move
     * onto the Design field.
     *
     * - A block with a Design field returns that field's value.
     * - A block with the old option fields returns them as a Design value
     *   (same mapping as the migrate command), so templates can switch first
     *   and the data can follow later.
     * - A mock map (showcases) reads its keys the same way, plus an optional
     *   nested `design` map.
     *
     * `{% set design = craft.designField.of(block, 'blockTeam') %}`, where the
     * profile name is the fallback for mock maps.
     *
     * @param mixed $block Element, mock map, or DesignValue
     * @param ?string $profile Profile for mock maps; tried after an element's own candidates
     * @return DesignValue
     *
     * @author WMD
     * @since 1.0.0
     */
    public function of(mixed $block, ?string $profile = null): DesignValue
    {
        if ($block instanceof DesignValue) {
            return $block;
        }

        $registry = Plugin::getInstance()->getGroups()->getRegistry();

        if ($block instanceof ElementInterface) {
            $layout = $block->getFieldLayout();
            $fields = $layout?->getCustomFields() ?? [];

            foreach ($fields as $field) {
                if ($field instanceof Design) {
                    $value = $block->getFieldValue($field->handle);

                    if ($value instanceof DesignValue) {
                        return $value;
                    }
                }
            }

            $candidates = Design::profilesFor($block);
            if ($profile !== null) {
                $candidates[] = $profile;
            }
            $type = Design::typeHandle($block) ?? (string)$profile;
            $handles = array_map(static fn($field) => $field->handle, $fields);

            $values = [];
            foreach ($registry->legacyHandles($handles, $candidates, $type) as $handle) {
                $values[$handle] = $block->getFieldValue($handle);
            }

            return $registry->legacyValue($values, $candidates, $type);
        }

        if (is_array($block) || $block instanceof ArrayAccess) {
            $map = is_array($block) ? $block : iterator_to_array(is_iterable($block) ? $block : []);
            $value = $registry->legacyValue($map, $profile, (string)$profile);
            $nested = $map['design'] ?? null;

            // An explicit `design` map in mock data wins over top-level keys.
            return is_array($nested)
                ? $registry->value(array_merge($value->toArray(), $nested), $profile)
                : $value;
        }

        return $registry->value([], $profile);
    }

    /**
     * Every configured group.
     *
     * @return array<string,Group>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function groups(): array
    {
        return Plugin::getInstance()->getGroups()->getRegistry()->groups;
    }

    /**
     * Adds the starter options' stylesheet (plain-CSS classes and entrances) to the page:
     * `{% do craft.designField.starterCss() %}` in the site layout.
     *
     * @return void
     *
     * @author WMD
     * @since 1.0.0
     */
    public function starterCss(): void
    {
        Craft::$app->getView()->registerAssetBundle(StarterAsset::class);
    }
}
