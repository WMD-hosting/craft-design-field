<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\events;

use craft\base\ElementInterface;
use wmd\designfield\models\Group;
use yii\base\Event;

/**
 * Lets a site trim a Design panel for the element being edited, before it is drawn:
 * hide choices that make no sense for this entry (`$group->withHidden([...$group->hidden, ...])`),
 * relabel them, or leave a whole option out (`$event->hiddenGroups[] = 'handle'`). Only the
 * panel changes: stored values, validation and templates see every option, a hidden option
 * keeps its stored value, and a block that already has a hidden choice still shows it.
 *
 * @author WMD
 * @since 1.0.0
 */
class DefinePanelGroupsEvent extends Event
{
    /**
     * @var ?ElementInterface The element whose Design field is drawn (a block, an entry, a product)
     */
    public ?ElementInterface $element = null;

    /**
     * @var array<string,Group> The panel's options by handle; replace a Group to change it.
     * Adding or removing handles has no effect.
     */
    public array $groups = [];

    /**
     * @var array<string,string> The element's current choices by handle (read only)
     */
    public array $keys = [];

    /**
     * @var string[] Options to leave out of this panel; each keeps its stored value
     */
    public array $hiddenGroups = [];
}
