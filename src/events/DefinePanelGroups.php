<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\events;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use wmd\designfield\models\Group;

/**
 * Dispatched before a Design panel is drawn, so a site can trim it for the element being
 * edited: hide choices that make no sense for this entry
 * (`$event->groups['x'] = $event->groups['x']->withHidden([...])`), relabel them, or leave a
 * whole option out (`$event->hiddenGroups[] = 'handle'`).
 *
 * Only the panel changes: stored values, validation and templates see every option, a
 * hidden option keeps its stored value, and a block that already has a hidden choice still
 * shows it. Listen with `Event::listen(DefinePanelGroups::class, …)`.
 *
 * @author WMD
 * @since 2.0.0
 */
class DefinePanelGroups
{
    /**
     * @var string[] Options to leave out of this panel; each keeps its stored value
     */
    public array $hiddenGroups = [];

    /**
     * @param ?ElementInterface $element The element whose Design field is drawn (a block, an entry)
     * @param array<string,Group> $groups The panel's options by handle; replace a Group to change it.
     *                                    Adding or removing handles has no effect.
     * @param array<string,string> $keys The element's current choices by handle (read only)
     */
    public function __construct(
        public readonly ?ElementInterface $element,
        public array $groups,
        public readonly array $keys,
    ) {
    }
}
