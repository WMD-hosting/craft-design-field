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
use CraftCms\Cms\Ui\Controls\Range;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Enums\FieldWidth;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Group as GroupNode;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Ui;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\Motion;
use wmd\designfield\helpers\Positions;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\Sections;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;

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

        // A slider posts the choice's place on the scale, under its own key (Registry::SLIDER_KEY)
        // so it is never read as a key itself. A choice the slider leaves out keeps buttons.
        $steps = $group->sliderKeys();
        $step = array_search($selected, $steps, true);

        if ($group->input === Group::INPUT_SLIDER && $step !== false && count($steps) >= 2) {
            return Range::make([Registry::SLIDER_KEY, $group->handle])
                ->min(0)
                ->max(count($steps) - 1)
                ->step(1)
                ->suffix($group->options[$selected]['label'])
                ->reactive()
                ->value($step);
        }

        $control = Choice::make($group->handle)
            ->options(self::options($group, $selected))
            ->presentation(self::presentation($group))
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
     * The choices of one option, as the kit's Choice control takes them.
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
        $grid = $group->input === Group::INPUT_POSITION ? self::grid($group) : null;

        foreach ($grid['order'] ?? array_keys($group->options) as $key) {
            $key = (string)$key;
            $option = $group->options[$key];

            if ($key !== $selected && in_array($key, $group->hidden, true)) {
                // In a grid or the corners, a cell's place is its position: keep the hidden
                // one's place empty so the cells after it do not move.
                if (in_array($grid['set'] ?? null, [Positions::GRID, Positions::CORNERS], true)) {
                    $options[] = ['label' => $option['label'], 'value' => $key, 'disabled' => true, 'labelHtml' => OptionLabels::gap($grid['columns'])];
                }

                continue;
            }

            $choice = ['label' => $option['label'], 'value' => $key];
            $labelHtml = $grid !== null
                ? self::cellHtml($group, $key, $grid)
                : self::labelHtml($group, $option);

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
     * The label of a position cell.
     *
     * @param Group $group
     * @param string $key
     * @param array{columns:int,order:list<string>,set:?string,places:array<string,string>} $grid
     * @return string
     */
    private static function cellHtml(Group $group, string $key, array $grid): string
    {
        $option = $group->options[$key];

        if (self::isTextCell($key)) {
            return OptionLabels::textCell($option['label'], $grid['columns']);
        }

        // Outside a position set a dot says nothing: a cell without an icon shows its name.
        return OptionLabels::cell($option['label'], $grid['columns'], $option['icon'], $grid['places'][$key] ?? null, $grid['set'] !== null);
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
