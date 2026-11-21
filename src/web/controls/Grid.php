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
 * A position option as a grid of cells: a nine-point grid, the corners, a row or column
 * of marks, or a line of icons. The text choice (Block default) sits before or above the
 * cells, the chosen cell's name is read out beside them, and the arrow keys move through
 * rows and columns. It posts the choice's key, like the buttons.
 *
 * Drawn by the Vue component `design-field:grid` (resources/js/GridControl.vue);
 * screens that are not drawn with Vue get a plain dropdown of the same choices.
 *
 * @author WMD
 * @since 2.1.0
 */
class Grid extends Control
{
    use SelectFallback;

    // Properties
    // =========================================================================

    private int $columns = 0;

    /**
     * @var array{value:string,label:string}|null
     */
    private ?array $text = null;

    /**
     * @var list<array{value:string,label:string,html?:?string,gap?:bool}>
     */
    private array $cells = [];

    // Public Methods
    // =========================================================================

    /**
     * Cells per row; 0 puts them all in one row.
     *
     * @param int $columns
     * @return static
     *
     * @author WMD
     * @since 2.1.0
     */
    public function columns(int $columns): static
    {
        $this->columns = max(0, $columns);

        return $this;
    }

    /**
     * The text choice (Block default, Auto), shown as a button with its name.
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

    /**
     * The cells in grid order. `html` is what the cell draws (sanitised server-side;
     * null shows the name), `gap` keeps the place of a choice the block leaves out.
     *
     * @param list<array{value:string,label:string,html?:?string,gap?:bool}> $cells
     * @return static
     *
     * @author WMD
     * @since 2.1.0
     */
    public function cells(array $cells): static
    {
        $this->cells = $cells;

        return $this;
    }

    #[Override]
    public function component(): string
    {
        return 'design-field:grid';
    }

    #[Override]
    public function props(mixed $value = null): array
    {
        return ['columns' => $this->columns, 'text' => $this->text, 'cells' => $this->cells];
    }

    #[Override]
    public function inputSchema(): array
    {
        return ['type' => 'string', 'enum' => array_column(self::choices($this->text, $this->cells), 'value')];
    }

    #[Override]
    public static function renderHtml(ControlPayload $control, mixed $value, array $attributes, UiHtmlRenderer $renderer): string
    {
        return self::selectHtml(self::choices($control->props['text'] ?? null, $control->props['cells'] ?? []), $value, $attributes);
    }

    // Private Methods
    // =========================================================================

    /**
     * The choices that can be picked: the text choice, then every cell that is no gap.
     *
     * @param array{value:string,label:string}|null $text
     * @param list<array{value:string,label:string,gap?:bool}> $cells
     * @return list<array{value:string,label:string}>
     */
    private static function choices(?array $text, array $cells): array
    {
        $choices = $text !== null ? [['value' => $text['value'], 'label' => $text['label']]] : [];

        foreach ($cells as $cell) {
            if (empty($cell['gap'])) {
                $choices[] = ['value' => $cell['value'], 'label' => $cell['label']];
            }
        }

        return $choices;
    }
}
