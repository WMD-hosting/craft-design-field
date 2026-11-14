<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web;

use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Enums\FieldWidth;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Group as GroupNode;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Ui;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\Motion;
use wmd\designfield\helpers\Positions;
use wmd\designfield\helpers\Sections;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;
use wmd\designfield\web\controls\Grid;
use wmd\designfield\web\controls\Rendered;
use wmd\designfield\web\controls\Steps;

/**
 * The Design panel as a Craft UI: one field per option, on the controls of
 * Craft's own kit (no control-panel JavaScript of ours).
 *
 * Each option's input style picks the control: buttons, a dropdown, a switch, a slider,
 * a grid of position cells, colour chips, or cards with a picture or a playing motion.
 * What a button shows besides its name (a drawn icon, a chip, an "Aa" sample, a width
 * bar) is label markup from {@see OptionLabels}, laid out by option-looks.css.
 *
 * @author WMD
 * @since 2.0.0
 */
class OptionControls
{
    // Const Properties
    // =========================================================================

    /**
     * Ends the `src` of a layout picture, so the hover preview finds our tiles and no other card.
     */
    public const TILE_MARK = '#df-tile';

    // Public Methods
    // =========================================================================

    /**
     * The panel in a view mode: every option open (panel), behind a line saying what is
     * set (summary), or in a closed section (collapsed). The options are in the form
     * either way, so every choice is posted.
     *
     * @param DesignValue $value
     * @param string $mode One of the Design::VIEW_* constants
     * @param bool $open Start with the options shown (a new element)
     * @param string $layout One of the Design::LAYOUT_* constants
     * @return Ui
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function panel(DesignValue $value, string $mode = Design::VIEW_PANEL, bool $open = false, string $layout = Design::LAYOUT_SECTIONS): Ui
    {
        $nodes = self::nodes($value->groups(), $value->keys(), $layout);

        if ($mode !== Design::VIEW_SUMMARY && $mode !== Design::VIEW_COLLAPSED) {
            return Ui::make($nodes);
        }

        $changes = self::changes($value);

        if ($mode === Design::VIEW_COLLAPSED) {
            $count = match (count($changes)) {
                0 => 'Defaults',
                1 => '1 change',
                default => count($changes) . ' changes',
            };

            return Ui::make([
                GroupNode::make('design-view', $nodes)->label("Options · $count")->collapsible()->expanded($open),
            ]);
        }

        return Ui::make([
            // Labels are plain text: keep Markdown from reading them as markup.
            MarkdownContent::make('design-summary', $changes !== []
                ? (string)preg_replace('/([\\\\`*_\[\]<>#|~])/', '\\\\$1', implode(' · ', $changes))
                : 'All options at their defaults.')->displayInPane(false),
            GroupNode::make('design-view', $nodes)->label('Edit')->collapsible()->expanded($open),
        ]);
    }

    /**
     * What differs from the defaults, one "Option: Choice" per changed option.
     *
     * @param DesignValue $value
     * @return list<string>
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function changes(DesignValue $value): array
    {
        $groups = $value->groups();
        $changes = [];

        foreach ($value->changes() as $handle => $key) {
            $changes[] = $groups[$handle]->label . ': ' . $groups[$handle]->options[$key]['label'];
        }

        return $changes;
    }

    /**
     * The panel for one block: its options under their section headings.
     *
     * @param array<string,Group> $groups The block's options, in panel order
     * @param array<string,string> $keys Option handle => current choice
     * @param bool $headings False: one list without section headings
     * @return Ui
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function ui(array $groups, array $keys, bool $headings = true): Ui
    {
        return Ui::make(self::nodes($groups, $keys, $headings ? Design::LAYOUT_SECTIONS : Design::LAYOUT_INLINE));
    }

    /**
     * The options as nodes: a group per section, or one list (inline). Side by side,
     * a section takes half the row.
     *
     * @param array<string,Group> $groups The block's options, in panel order
     * @param array<string,string> $keys Option handle => current choice
     * @param string $layout One of the Design::LAYOUT_* constants
     * @return list<Node>
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function nodes(array $groups, array $keys, string $layout = Design::LAYOUT_SECTIONS): array
    {
        $nodes = [];

        foreach (Sections::arrange($groups, $layout !== Design::LAYOUT_INLINE) as $index => $section) {
            $fields = array_map(
                static fn(string $handle) => self::field($groups[$handle], $keys[$handle] ?? $groups[$handle]->default, $keys),
                $section['groups'],
            );

            if ($section['label'] === '') {
                array_push($nodes, ...$fields);
                continue;
            }

            // A section whose options all wait for another choice has nothing to show:
            // without this its heading would stand over an empty area.
            $shown = array_filter($fields, static fn(Field $field) => $field->isVisible());

            $nodes[] = GroupNode::make("design-section-$index", $fields)
                ->label($section['label'])
                ->width($layout === Design::LAYOUT_SIDE ? FieldWidth::Half : null)
                ->visible($shown !== []);
        }

        return $nodes;
    }

    /**
     * One option as a field. An option that does not apply to the other
     * choices (`showIf`) is still there, hidden, so its value is kept.
     *
     * @param Group $group
     * @param string $selected The current choice
     * @param array<string,string> $keys Every option's current choice
     * @return Field
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function field(Group $group, string $selected, array $keys): Field
    {
        return Field::make($group->label, self::control($group, $selected))
            ->instructions($group->instructions !== '' ? $group->instructions : null)
            ->visible($group->appliesTo($keys));
    }

    /**
     * The control for one option, named by its handle.
     *
     * @param Group $group
     * @param string $selected The current choice
     * @return Control
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function control(Group $group, string $selected): Control
    {
        if ($group->input === Group::INPUT_TOGGLE && count($group->options) === 2) {
            [$off, $on] = array_map('strval', array_keys($group->options));

            return Lightswitch::make($group->handle)
                ->checkedValue($on)
                ->onLabel($group->options[$on]['label'])
                ->offLabel($group->options[$off]['label'])
                ->reactive()
                ->value($selected === $on);
        }

        // A slider shows every choice's name under its track and posts the chosen key, like
        // the buttons; Block default is a button before it (no point on the scale). A choice
        // the slider leaves out (hidden) keeps buttons while it is chosen.
        $keys = $group->sliderKeys();
        $steps = array_values(array_filter($keys, static fn(string $key) => !self::isTextCell($key)));

        if ($group->input === Group::INPUT_SLIDER && in_array($selected, $keys, true) && count($steps) >= 2) {
            $choice = static fn(string $key) => ['value' => $key, 'label' => (string)$group->options[$key]['label']];

            return Steps::make($group->handle)
                ->text(count($steps) < count($keys) ? $choice(Group::AUTO) : null)
                ->steps(array_map($choice, $steps))
                ->reactive()
                ->value($selected);
        }

        if ($group->input === Group::INPUT_POSITION) {
            return self::gridControl($group, $selected);
        }

        // Rendered cards need the site's stylesheet; without one they stay named radios.
        if ($group->input === Group::INPUT_RENDERED) {
            $stylesheet = trim((string)app(Groups::class)->config()['previewStylesheet']);

            if ($stylesheet !== '') {
                return self::renderedControl($group, $selected, $stylesheet);
            }
        }

        $options = self::options($group, $selected);
        $presentation = self::presentation($group);

        // Buttons that only draw (colour chips, icons without names) never show which one is
        // chosen: as the grid control, the chosen one's name is read out beside them.
        $drawn = $presentation === ChoicePresentation::Buttons ? self::drawnCells($group, $options) : null;

        if ($drawn !== null) {
            return Grid::make($group->handle)
                ->text($drawn['text'])
                ->cells($drawn['cells'])
                ->reactive()
                ->value($selected);
        }

        $control = Choice::make($group->handle)
            ->options($options)
            ->presentation($presentation)
            ->withoutPlaceholder()
            ->reactive()
            ->value($selected);

        return $group->iconsOnly ? $control : $control->iconsOnly(false);
    }

    /**
     * How an option's input style is presented.
     *
     * @param Group $group
     * @return ChoicePresentation
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function presentation(Group $group): ChoicePresentation
    {
        return match ($group->input) {
            Group::INPUT_SELECT, Group::INPUT_CHIP => ChoicePresentation::Select,
            Group::INPUT_TILES, Group::INPUT_RENDERED, Group::INPUT_MOTION => ChoicePresentation::Radios,
            Group::INPUT_SWATCHES, Group::INPUT_POSITION, Group::INPUT_SLIDER => ChoicePresentation::Buttons,
            // A long list of names reads better as a dropdown; "Aa" samples stay buttons (they are
            // narrow, and a dropdown shows only names), as Group picks them.
            default => $group->input === Group::INPUT_BUTTONS
                && count($group->options) > Group::BUTTONS_MAX
                && array_filter($group->options, static fn(array $option) => $option['sample'] !== null) === []
                ? ChoicePresentation::Select
                : ChoicePresentation::Buttons,
        };
    }

    /**
     * The choices of one option, as the kit's Choice control takes them (position options
     * have their own control, see gridControl()).
     * Choices the picker leaves out (`hidden`) are listed only while chosen.
     *
     * @param Group $group
     * @param string $selected The current choice
     * @return list<array<string,mixed>>
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function options(Group $group, string $selected): array
    {
        $options = [];

        foreach (array_keys($group->options) as $key) {
            $key = (string)$key;
            $option = $group->options[$key];

            if ($key !== $selected && in_array($key, $group->hidden, true)) {
                continue;
            }

            $choice = ['label' => $option['label'], 'value' => $key];
            $labelHtml = self::labelHtml($group, $option);

            if ($labelHtml !== null) {
                $choice['labelHtml'] = $labelHtml;
            } elseif ($option['icon'] !== null && !OptionLabels::isSvg($option['icon'])) {
                $choice['icon'] = $option['icon'];
            }

            // A picture whose file is missing would show as a broken image: leave it out.
            if (in_array($group->input, [Group::INPUT_TILES, Group::INPUT_RENDERED], true) && $option['image'] !== null) {
                $picture = app(Groups::class)->picture($option['image']);

                if ($picture !== null) {
                    // The fragment marks our tiles for the larger look on hover (tile-preview.js).
                    $choice['thumbnail'] = ['src' => $picture . self::TILE_MARK];
                }
            }

            if ($group->input === Group::INPUT_MOTION) {
                $choice['thumbnail'] = ['svg' => self::motionStage($option['motion']), 'aspectRatio' => '16 / 9'];
            }

            $options[] = $choice;
        }

        return $options;
    }

    /**
     * A position option as the grid control: the text choice, then the cells in grid order.
     * Choices the picker leaves out (`hidden`) are listed only while chosen; in a nine-point
     * grid or the corners their place stays empty, so the other cells do not move.
     *
     * @param Group $group
     * @param string $selected The current choice
     * @return Grid
     *
     * @author WMD
     * @since 2.1.0
     */
    public static function gridControl(Group $group, string $selected): Grid
    {
        $grid = self::grid($group);
        $keepsPlaces = in_array($grid['set'], [Positions::GRID, Positions::CORNERS], true);
        $text = null;
        $cells = [];

        foreach ($grid['order'] as $key) {
            $key = (string)$key;
            $option = $group->options[$key];
            $left = $key !== $selected && in_array($key, $group->hidden, true);

            if (self::isTextCell($key)) {
                $text = $left ? null : ['value' => $key, 'label' => (string)$option['label']];
                continue;
            }

            if ($left) {
                if ($keepsPlaces) {
                    $cells[] = ['value' => $key, 'label' => (string)$option['label'], 'gap' => true];
                }

                continue;
            }

            $cells[] = [
                'value' => $key,
                'label' => (string)$option['label'],
                // Outside a position set a dot says nothing: a cell without an icon shows its name.
                'html' => OptionLabels::drawn($option['icon'], $grid['places'][$key] ?? null, $grid['set'] !== null),
            ];
        }

        return Grid::make($group->handle)
            ->columns($grid['columns'])
            ->text($text)
            ->cells($cells)
            ->reactive()
            ->value($selected);
    }

    /**
     * Button choices that all draw something instead of showing their name (colour chips,
     * icons-only buttons), as grid cells: the text choice (Block default with no drawing)
     * apart, the rest drawn. Null when any choice shows its name, or fewer than two draw.
     *
     * @param Group $group
     * @param list<array<string,mixed>> $options From options()
     * @return array{text:?array{value:string,label:string},cells:list<array{value:string,label:string,html:string}>}|null
     *
     * @author WMD
     * @since 2.1.0
     */
    public static function drawnCells(Group $group, array $options): ?array
    {
        $text = null;
        $cells = [];

        foreach ($options as $option) {
            $key = (string)$option['value'];
            $html = match (true) {
                isset($option['labelHtml']) && str_contains($option['labelHtml'], 'df-label--drawn') => $option['labelHtml'],
                isset($option['icon']) && $group->iconsOnly => OptionLabels::glyph($option['icon']),
                default => null,
            };

            if ($html !== null) {
                $cells[] = ['value' => $key, 'label' => (string)$option['label'], 'html' => $html];
            } elseif (self::isTextCell($key) && $text === null) {
                $text = ['value' => $key, 'label' => (string)$option['label']];
            } else {
                return null;
            }
        }

        return count($cells) >= 2 ? ['text' => $text, 'cells' => $cells] : null;
    }

    /**
     * A rendered option as cards drawn with the site's stylesheet and brand attributes
     * (`previewAttributes`). Choices the picker leaves out (`hidden`) are listed only while chosen.
     *
     * @param Group $group
     * @param string $selected The current choice
     * @param string $stylesheet The site's built stylesheet URL
     * @return Rendered
     *
     * @author WMD
     * @since 2.1.0
     */
    public static function renderedControl(Group $group, string $selected, string $stylesheet): Rendered
    {
        $choices = [];

        foreach ($group->options as $key => $option) {
            $key = (string)$key;

            if ($key !== $selected && in_array($key, $group->hidden, true)) {
                continue;
            }

            $choices[] = ['value' => $key, 'label' => (string)$option['label'], 'preview' => $option['preview']];
        }

        $attributes = array_map('strval', (array)app(Groups::class)->config()['previewAttributes']);

        return Rendered::make($group->handle)
            ->stylesheet($stylesheet)
            ->attributes($attributes)
            ->choices($choices)
            ->reactive()
            ->value($selected);
    }

    /**
     * How a position option lays out its cells: cells per row, the order (the text
     * choice first, then the cells in grid order), the position set its keys name (null
     * for none) and where a one-line set's cells sit.
     *
     * @param Group $group
     * @return array{columns:int,order:list<string>,set:?string,places:array<string,string>}
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function grid(Group $group): array
    {
        $keys = array_map('strval', array_keys($group->options));
        $text = array_values(array_filter($keys, static fn(string $key) => self::isTextCell($key)));
        $cells = array_values(array_diff($keys, $text));
        $match = Positions::match($cells);

        if ($match === null) {
            return ['columns' => max(0, $group->columns), 'order' => [...$text, ...$cells], 'set' => null, 'places' => []];
        }

        $places = [];

        foreach ($match['cells'] as $key => [$row, $column]) {
            $place = match ($match['set']) {
                Positions::ROW => "row-$column",
                Positions::COLUMN => "column-$row",
                default => null,
            };

            if ($place !== null) {
                $places[$key] = $place;
            }
        }

        return [
            'columns' => $group->columns > 0 ? $group->columns : $match['columns'],
            'order' => [...$text, ...array_map('strval', array_keys($match['cells']))],
            'set' => $match['set'],
            'places' => $places,
        ];
    }

    /**
     * The picture of a motion choice: a small stage that plays the motion on hover and
     * keyboard focus (motion-tiles.css). A choice without a motion stays still.
     *
     * @param string|null $motion The choice's preset, from Motion::resolve()
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function motionStage(?string $motion): string
    {
        return Html::tag('span',
            Html::tag('span', '', ['class' => 'df-m-a']) .
            Html::tag('span', '<i></i><i></i>', ['class' => 'df-m-b']),
            [
                'class' => 'df-motion-stage',
                'data' => [
                    'df-motion' => $motion ?? '',
                    'df-kind' => $motion !== null ? Motion::kind($motion) : null,
                ],
            ],
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether a position choice is the text cell above the grid (`auto`: Block default).
     *
     * @param string $key
     * @return bool
     */
    private static function isTextCell(string $key): bool
    {
        return $key === Group::AUTO;
    }

    /**
     * What a button shows besides its name, or null for the kit's own label (and icon).
     * Plain labels (`textOnly`) and the cards (tiles, motion, rendered) keep theirs.
     *
     * @param Group $group
     * @param array{label:string,icon:?string,swatch:?string,sample:?string,percent:?string,swatchText:?string} $option
     * @return ?string
     */
    private static function labelHtml(Group $group, array $option): ?string
    {
        if ($group->textOnly || in_array($group->input, [Group::INPUT_TILES, Group::INPUT_RENDERED, Group::INPUT_MOTION, Group::INPUT_SELECT, Group::INPUT_CHIP], true)) {
            return null;
        }

        if ($group->input === Group::INPUT_SWATCHES) {
            $chip = $option['swatch'] !== null ? OptionLabels::swatch($option['swatch'], $option['swatchText']) : null;

            return $chip !== null ? OptionLabels::label($chip, $option['label'], false) : null;
        }

        $sample = $option['sample'] !== null ? OptionLabels::sample($option['sample']) : null;

        if ($sample !== null) {
            return OptionLabels::label($sample, $option['label'], false);
        }

        $percent = OptionLabels::percent($option['percent']);

        if ($percent !== null) {
            return OptionLabels::label(OptionLabels::bar($percent), $option['label'], !$group->iconsOnly);
        }

        if (OptionLabels::isSvg($option['icon'])) {
            return OptionLabels::label(OptionLabels::glyph((string)$option['icon']), $option['label'], !$group->iconsOnly);
        }

        return null;
    }
}
