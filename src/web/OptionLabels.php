<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web;

use CraftCms\Cms\Support\Html;

/**
 * What a choice shows inside its button: a drawn icon, a colour chip, an "Aa" sample,
 * a width bar or a position cell.
 *
 * Craft's choice control takes a choice's label as HTML (`labelHtml`), so these are
 * plain markup styled by option-looks.css: no control-panel JavaScript. The choice's
 * name always stays in the label, visible or for screen readers only, and as the
 * tooltip when it is not shown.
 *
 * @author WMD
 * @since 2.0.0
 */
final class OptionLabels
{
    // Public Methods
    // =========================================================================

    /**
     * Whether an icon setting is inline SVG markup rather than the name of a Craft icon.
     *
     * @param ?string $icon
     * @return bool
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function isSvg(?string $icon): bool
    {
        return $icon !== null && str_starts_with(ltrim($icon), '<svg');
    }

    /**
     * An icon in a label: inline SVG (sanitised) or a Craft icon by name.
     *
     * @param string $icon
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function glyph(string $icon): string
    {
        $content = self::isSvg($icon)
            ? Html::sanitizeSvg($icon)
            : Html::tag('craft-icon', '', ['name' => $icon]);

        return Html::tag('span', $content, ['class' => 'df-glyph', 'aria-hidden' => 'true']);
    }

    /**
     * "Aa" at the choice's font size, so sizes compare at a glance.
     *
     * @param string $size A CSS font size
     * @return ?string Null when the size is no plain CSS value
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function sample(string $size): ?string
    {
        $size = self::safeCss($size);

        if ($size === null) {
            return null;
        }

        return Html::tag('span', 'Aa', ['class' => 'df-sample', 'style' => "font-size: $size", 'aria-hidden' => 'true']);
    }

    /**
     * A colour chip: one colour or gradient, two colours split on the diagonal
     * (`#4f46e5|#0ea5e9`), and with a text colour "Aa" in that colour on it.
     *
     * @param string $swatch
     * @param ?string $text The text colour shown on the chip
     * @return ?string Null when the colour is no plain CSS value
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function swatch(string $swatch, ?string $text = null): ?string
    {
        $colours = array_map(trim(...), explode('|', $swatch, 2));
        $colours = array_map(self::safeCss(...), $colours);

        if (in_array(null, $colours, true)) {
            return null;
        }

        $background = count($colours) === 2
            ? "linear-gradient(135deg, $colours[0] 50%, $colours[1] 50%)"
            : $colours[0];
        $text = $text !== null ? self::safeCss($text) : null;
        $empty = count($colours) === 1 && in_array(strtolower($colours[0]), ['transparent', 'none'], true);

        return Html::tag('span', $text !== null ? 'Aa' : '', [
            'class' => array_filter(['df-swatch', $text !== null ? 'df-swatch--text' : null, $empty ? 'df-swatch--empty' : null]),
            'style' => "background: $background" . ($text !== null ? "; color: $text" : ''),
            'aria-hidden' => 'true',
        ]);
    }

    /**
     * A bar filled to a width, for width choices (75 fills three quarters).
     *
     * @param float $percent 0 to 100
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function bar(float $percent): string
    {
        $percent = max(0.0, min(100.0, $percent));

        return Html::tag('span',
            Html::tag('span', '', ['class' => 'df-bar-fill', 'style' => 'width: ' . round($percent, 2) . '%']),
            ['class' => 'df-bar', 'aria-hidden' => 'true'],
        );
    }

    /**
     * Reads a width setting: `75`, `75%` or `0.75`.
     *
     * @param ?string $value
     * @return ?float 0 to 100, or null when it is no number
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function percent(?string $value): ?float
    {
        if ($value === null || !preg_match('/^\s*(\d+(?:\.\d+)?)\s*(%?)\s*$/', $value, $match)) {
            return null;
        }

        $number = (float)$match[1];

        // A fraction without a percent sign: 0.75 is three quarters.
        if ($match[2] === '' && $number > 0 && $number <= 1 && str_contains($match[1], '.')) {
            $number *= 100;
        }

        return min(100.0, $number);
    }

    /**
     * A cell of a position grid: the choice's icon, or a mark where the cell sits in its
     * row or column, or a dot; the name stays the tooltip and the accessible name.
     *
     * @param string $label
     * @param int $columns Cells per row (0 = one row)
     * @param ?string $icon The choice's icon (inline SVG or a Craft icon name)
     * @param ?string $place Where a one-line set's cell sits: `row-0` ... `column-2`
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function cell(string $label, int $columns, ?string $icon = null, ?string $place = null): string
    {
        $mark = match (true) {
            $icon !== null => self::glyph($icon),
            $place !== null => Html::tag('span', Html::tag('i', ''), ['class' => ['df-mark', "df-mark--$place"], 'aria-hidden' => 'true']),
            default => Html::tag('span', '', ['class' => 'df-dot', 'aria-hidden' => 'true']),
        };

        return Html::tag('span', $mark . self::hidden($label), [
            'class' => 'df-cell',
            'title' => $label,
            'data' => ['df-columns' => (string)$columns],
        ]);
    }

    /**
     * The text choice of a position grid (Auto, Block default), on its own row above the cells.
     *
     * @param string $label
     * @param int $columns Cells per row of the grid it belongs to
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function textCell(string $label, int $columns): string
    {
        return Html::tag('span', self::text($label), [
            'class' => ['df-cell', 'df-cell--text'],
            'data' => ['df-columns' => (string)$columns],
        ]);
    }

    /**
     * Something drawn, then the choice's name: shown, or for screen readers only with the
     * name as the tooltip.
     *
     * @param string $drawn Markup from glyph(), swatch(), sample() or bar()
     * @param string $label
     * @param bool $showLabel
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function label(string $drawn, string $label, bool $showLabel): string
    {
        return Html::tag('span', $drawn . ($showLabel ? self::text($label) : self::hidden($label)), [
            'class' => ['df-label', $showLabel ? null : 'df-label--drawn'],
            'title' => $showLabel ? null : $label,
        ]);
    }

    /**
     * Allows colours, gradients, color-mix() and lengths, nothing that can break out of a
     * style attribute.
     *
     * @param ?string $value
     * @return ?string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function safeCss(?string $value): ?string
    {
        if ($value === null || !preg_match('/^[a-zA-Z0-9#%(),.\s\-\/]+$/', $value)) {
            return null;
        }

        return trim($value);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $label
     * @return string
     */
    private static function text(string $label): string
    {
        return Html::tag('span', htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ['class' => 'df-text']);
    }

    /**
     * @param string $label
     * @return string
     */
    private static function hidden(string $label): string
    {
        return Html::tag('span', htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ['class' => 'df-sr']);
    }
}
