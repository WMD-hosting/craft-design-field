<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web\controls;

use CraftCms\Cms\Ui\Controls\ContentBlock;
use Override;

/**
 * The Design panel with a row of presets above its options: a click on a preset sets
 * each of its options at once, and the editor can then change them one by one.
 *
 * Core draws the options (its Content Block); the Vue component
 * `design-field:presets` (resources/js/PresetsControl.vue) only adds the row. It can
 * set several options because this control holds the whole option map: one value at
 * the field's path, which every option's own control reads from. A control next to
 * the options could not: the panel's values can be read from outside an option
 * (`UiValues`), not written.
 *
 * Without presets it is core's Content Block as it was. Screens that are not drawn
 * with Vue get core's markup, without the row.
 *
 * @author WMD
 * @since 2.1.0
 */
class PresetBlock extends ContentBlock
{
    // Properties
    // =========================================================================

    /**
     * @var list<array{label:string,summary:string,values:array<string,string|bool>}>
     */
    private array $presets = [];

    // Public Methods
    // =========================================================================

    /**
     * The presets in the order they show, each with its option values as the
     * options' own controls hold them (a switch holds true/false, not its key).
     *
     * @param list<array{label:string,summary:string,values:array<string,string|bool>}> $presets
     * @return static
     *
     * @author WMD
     * @since 2.1.0
     */
    public function presets(array $presets): static
    {
        $this->presets = $presets;

        return $this;
    }

    #[Override]
    public function component(): string
    {
        return $this->presets === [] ? parent::component() : 'design-field:presets';
    }

    #[Override]
    public function props(mixed $value = null): array
    {
        $props = parent::props($value);

        if ($this->presets !== []) {
            $props['presets'] = $this->presets;
        }

        return $props;
    }
}
