<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\variables;

use ArrayAccess;
use CraftCms\Cms\Element\Concerns\NestedElement;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\HtmlStack;
use wmd\designfield\fields\Design;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;

/**
 * `craft.designField` in Twig, registered as a CraftVariable macro.
 *
 * Same API as the Craft 5 plugin's variable. An element without a Design field
 * is read through its old option fields (the legacy path).
 *
 * @author WMD
 * @since 1.0.0
 */
class DesignFieldVariable
{
    /**
     * The starter stylesheet starterCss() adds to a page.
     */
    public const STARTER_CSS = __DIR__ . '/../web/assets/starter/dist/design-field.css';

    public function __construct(
        private readonly Groups $groups,
    ) {
    }

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
        return $this->groups->getRegistry()->value($keys, $profile);
    }

    /**
     * The design value of any block: an element's option fields, a mock map
     * (plus an optional nested `design` map), or a DesignValue as is.
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

        $registry = $this->groups->getRegistry();

        if ($block instanceof ElementInterface) {
            $fields = $block->getFieldLayout()?->getCustomFields() ?? [];

            foreach ($fields as $field) {
                if ($field instanceof Design) {
                    $value = $block->getFieldValue($field->handle);

                    if ($value instanceof DesignValue) {
                        return $value;
                    }
                }
            }

            $candidates = $this->profilesFor($block);
            if ($profile !== null) {
                $candidates[] = $profile;
            }
            $type = $this->typeHandle($block) ?? (string)$profile;
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
        return $this->groups->getRegistry()->groups;
    }

    /**
     * Adds the starter options' stylesheet (plain-CSS classes and entrances) to the page:
     * `{% do craft.designField.starterCss() %}` in the site layout. It is small (about 2 kB)
     * and goes into the head as a `<style>` tag, once per page however often it is called.
     *
     * @return void
     *
     * @author WMD
     * @since 1.0.0
     */
    public function starterCss(): void
    {
        HtmlStack::css((string)file_get_contents(self::STARTER_CSS), key: 'design-field-starter');
    }

    // Private Methods
    // =========================================================================

    /**
     * Profile candidates: `{ownerField}:{type}` for a nested entry, then the type handle.
     *
     * @param ElementInterface $element
     * @return list<string>
     */
    private function profilesFor(ElementInterface $element): array
    {
        $type = $this->typeHandle($element);

        if ($type === null) {
            return [];
        }

        $candidates = [];

        if (in_array(NestedElement::class, class_uses_recursive($element), true)) {
            $owner = $element->getField();

            if ($owner !== null) {
                $candidates[] = "$owner->handle:$type";
            }
        }

        $candidates[] = $type;

        return $candidates;
    }

    /**
     * The element's type handle (entry type, product type).
     *
     * @param ElementInterface $element
     * @return ?string
     */
    private function typeHandle(ElementInterface $element): ?string
    {
        if (!method_exists($element, 'getType')) {
            return null;
        }

        try {
            return $element->getType()->handle ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
