<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web\controls;

use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\ContentBlock;
use CraftCms\Cms\Ui\Ui;
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

    /**
     * @var ?array{url:string,toggles:array<string,array{0:string,1:string}>} The block's own page for the settings page's preview
     */
    private ?array $frame = null;

    /**
     * @var ?array{saveUrl:string,block:string,skip:string,layouts:list<array{key:string,label:string,picture:?string}>}
     */
    private ?array $tiles = null;

    /**
     * @var ?Ui The options, kept to add to: core's Content Block does not hand its Ui back
     */
    private ?Ui $options = null;

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

    /**
     * Shows the block itself next to the options (the settings page's Preview tab):
     * a frame that loads `$url` again after each change, with `{design}` replaced by
     * the panel's choices as JSON, option handle => key, the form a Design field stores.
     *
     * @param ?string $url Null for the panel alone
     * @param array<string,array{0:string,1:string}> $toggles The off and on key of each switch: the panel holds a switch as true/false
     * @return static
     *
     * @author WMD
     * @since 2.0.0
     */
    public function frame(?string $url, array $toggles = []): static
    {
        $this->frame = $url === null ? null : ['url' => $url, 'toggles' => $toggles];

        return $this;
    }

    /**
     * Adds "Make tile pictures" to the frame (the settings page's Preview tab): each
     * layout is drawn from the block's own page and posted to `saveUrl`.
     *
     * @param ?array{saveUrl:string,block:string,skip:string,layouts:list<array{key:string,label:string,picture:?string}>} $tiles Null for no button
     * @return static
     *
     * @author WMD
     * @since 2.0.0
     */
    public function tiles(?array $tiles): static
    {
        $this->tiles = $tiles;

        return $this;
    }

    #[Override]
    public function ui(Ui $ui): static
    {
        $this->options = $ui;

        return parent::ui($ui);
    }

    /**
     * Adds nodes under the options, inside the panel: their controls are part of the
     * panel's own values, so each refresh of the panel carries them (the settings page's
     * "Customize this block").
     *
     * @param Node ...$nodes
     * @return static
     *
     * @author WMD
     * @since 2.0.0
     */
    public function append(Node ...$nodes): static
    {
        return parent::ui(Ui::make([...($this->options?->nodes() ?? []), ...$nodes]));
    }

    #[Override]
    public function component(): string
    {
        return match (true) {
            $this->frame !== null => 'design-field:preview',
            $this->presets !== [] => 'design-field:presets',
            default => parent::component(),
        };
    }

    #[Override]
    public function props(mixed $value = null): array
    {
        $props = parent::props($value);

        if ($this->presets !== []) {
            $props['presets'] = $this->presets;
        }

        if ($this->frame !== null) {
            $props['presets'] ??= [];
            $props['frame'] = $this->frame;

            if ($this->tiles !== null) {
                $props['tiles'] = $this->tiles;
            }
        }

        return $props;
    }
}
