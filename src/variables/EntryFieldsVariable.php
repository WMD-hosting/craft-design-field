<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\variables;

use Twig\Markup;
use wmd\designfield\Plugin;

/**
 * `craft.entryFields`: render the Entry Fields rows of one slot.
 *
 * @author WMD
 * @since 1.1.0
 */
class EntryFieldsVariable
{
    /**
     * @param mixed $block
     * @param mixed $entry
     * @param string $slot
     * @return Markup
     *
     * @author WMD
     * @since 1.1.0
     */
    public function render(mixed $block, mixed $entry, string $slot): Markup
    {
        return Plugin::getInstance()->getEntryFieldsRenderer()->render($block, $entry, $slot);
    }

    /**
     * The ordered Content variant: rows in stored order, or the entry type layout.
     *
     * @param mixed $block
     * @param mixed $entry
     * @return Markup
     *
     * @author WMD
     * @since 1.1.0
     */
    public function ordered(mixed $block, mixed $entry): Markup
    {
        return Plugin::getInstance()->getEntryFieldsRenderer()->renderOrdered($block, $entry);
    }

    /**
     * The product block's two columns: `gallery` and `details`.
     *
     * @param mixed $block
     * @param mixed $entry
     * @param string|null $form The block's buy form id
     * @return array{gallery: Markup, details: Markup, below: Markup}
     *
     * @author WMD
     * @since 1.1.0
     */
    public function columns(mixed $block, mixed $entry, ?string $form = null): array
    {
        return Plugin::getInstance()->getEntryFieldsRenderer()->renderColumns($block, $entry, $form);
    }
}
