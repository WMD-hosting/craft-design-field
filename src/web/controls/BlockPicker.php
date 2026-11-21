<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web\controls;

use CraftCms\Cms\Ui\ControlPayload;
use CraftCms\Cms\Ui\Controls\Control;
use CraftCms\Cms\Ui\UiHtmlRenderer;
use Override;

/**
 * The settings page's block dropdown: picking a block opens its URL, so the page,
 * its address and the panel's refreshes all name the same block.
 *
 * @author WMD
 * @since 2.0.0
 */
class BlockPicker extends Control
{
    use SelectFallback;

    // Properties
    // =========================================================================

    /**
     * @var list<array{value:string,label:string,url:string}>
     */
    private array $options = [];

    // Public Methods
    // =========================================================================

    /**
     * @param list<array{value:string,label:string,url:string}> $options Each block with the URL that shows it
     * @return static
     *
     * @author WMD
     * @since 2.0.0
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    #[Override]
    public function component(): string
    {
        return 'design-field:block-picker';
    }

    #[Override]
    public function props(mixed $value = null): array
    {
        return ['options' => $this->options];
    }

    #[Override]
    public static function renderHtml(ControlPayload $control, mixed $value, array $attributes, UiHtmlRenderer $renderer): string
    {
        return self::selectHtml($control->props['options'] ?? [], $value, $attributes);
    }
}
