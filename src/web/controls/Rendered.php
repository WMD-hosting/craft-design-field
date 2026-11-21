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
 * A `rendered` option: each choice a small card drawn with the site's own stylesheet and
 * brand, so a corner radius or a card shadow shows this brand's real value. The card lives
 * in a shadow root that adopts the stylesheet, so the site's reset stays out of the panel.
 * The text choice (Block default) is a card with its name.
 *
 * Drawn by the Vue component `design-field:rendered` (resources/js/RenderedControl.vue);
 * screens that are not drawn with Vue get a plain dropdown of the same choices.
 *
 * @author WMD
 * @since 2.1.0
 */
class Rendered extends Control
{
    use SelectFallback;

    // Properties
    // =========================================================================

    private string $stylesheet = '';

    /**
     * @var array<string,string>
     */
    private array $attributes = [];

    /**
     * @var list<array{value:string,label:string,preview:?string}>
     */
    private array $choices = [];

    // Public Methods
    // =========================================================================

    /**
     * The URL of the site's built stylesheet (`previewStylesheet`).
     *
     * @param string $url
     * @return static
     *
     * @author WMD
     * @since 2.1.0
     */
    public function stylesheet(string $url): static
    {
        $this->stylesheet = $url;

        return $this;
    }

    /**
     * Attributes for the card's root, e.g. the brand (`previewAttributes`).
     *
     * @param array<string,string> $attributes
     * @return static
     *
     * @author WMD
     * @since 2.1.0
     */
    public function attributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * The choices in order; `preview` holds the classes the card is drawn with (null: the
     * card shows the choice's name).
     *
     * @param list<array{value:string,label:string,preview:?string}> $choices
     * @return static
     *
     * @author WMD
     * @since 2.1.0
     */
    public function choices(array $choices): static
    {
        $this->choices = $choices;

        return $this;
    }

    #[Override]
    public function component(): string
    {
        return 'design-field:rendered';
    }

    #[Override]
    public function props(mixed $value = null): array
    {
        return [
            'stylesheet' => $this->stylesheet,
            // Props must be plain arrays (the resolver refuses objects); an empty map arrives
            // as [] and the component's Object.entries() reads both the same.
            'attributes' => $this->attributes,
            'choices' => $this->choices,
        ];
    }

    #[Override]
    public function inputSchema(): array
    {
        return ['type' => 'string', 'enum' => array_column($this->choices, 'value')];
    }

    #[Override]
    public static function renderHtml(ControlPayload $control, mixed $value, array $attributes, UiHtmlRenderer $renderer): string
    {
        return self::selectHtml($control->props['choices'] ?? [], $value, $attributes);
    }
}
