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
 * A slider over an option's named choices: every stop's name under the track, the
 * chosen one read out next to the label. It posts the choice's key, as the buttons do,
 * so the field reads it without the place-on-the-scale mapping core's Range needs.
 *
 * Drawn by the Vue component `design-field:steps` (resources/js/StepsControl.vue);
 * screens that are not drawn with Vue get a plain dropdown of the same choices.
 *
 * @author WMD
 * @since 2.1.0
 */
class Steps extends Control
{
    use SelectFallback;

    // Properties
    // =========================================================================

    /**
     * @var list<array{value:string,label:string}>
     */
    private array $steps = [];

    // Public Methods
    // =========================================================================

    /**
     * The choices in slider order.
     *
     * @param list<array{value:string,label:string}> $steps
     * @return static
     *
     * @author WMD
     * @since 2.1.0
     */
    public function steps(array $steps): static
    {
        $this->steps = $steps;

        return $this;
    }

    #[Override]
    public function component(): string
    {
        return 'design-field:steps';
    }

    #[Override]
    public function props(mixed $value = null): array
    {
        return ['steps' => $this->steps];
    }

    #[Override]
    public function inputSchema(): array
    {
        return ['type' => 'string', 'enum' => array_column($this->steps, 'value')];
    }

    #[Override]
    public static function renderHtml(ControlPayload $control, mixed $value, array $attributes, UiHtmlRenderer $renderer): string
    {
        return self::selectHtml($control->props['steps'] ?? [], $value, $attributes);
    }
}
