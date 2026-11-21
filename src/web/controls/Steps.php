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
 * chosen one marked. The text choice (Block default) is a button before the slider, not a
 * stop: it is no point on the scale. It posts the choice's key, as the buttons do, so the
 * field reads it without the place-on-the-scale mapping core's Range needs.
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

    /**
     * @var array{value:string,label:string}|null
     */
    private ?array $text = null;

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

    /**
     * The text choice (Block default), a button before the slider.
     *
     * @param array{value:string,label:string}|null $text
     * @return static
     *
     * @author WMD
     * @since 2.1.0
     */
    public function text(?array $text): static
    {
        $this->text = $text;

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
        return ['text' => $this->text, 'steps' => $this->steps];
    }

    #[Override]
    public function inputSchema(): array
    {
        return ['type' => 'string', 'enum' => array_column(self::choices($this->text, $this->steps), 'value')];
    }

    #[Override]
    public static function renderHtml(ControlPayload $control, mixed $value, array $attributes, UiHtmlRenderer $renderer): string
    {
        return self::selectHtml(self::choices($control->props['text'] ?? null, $control->props['steps'] ?? []), $value, $attributes);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param array{value:string,label:string}|null $text
     * @param list<array{value:string,label:string}> $steps
     * @return list<array{value:string,label:string}>
     */
    private static function choices(?array $text, array $steps): array
    {
        return $text !== null ? [$text, ...$steps] : $steps;
    }
}
