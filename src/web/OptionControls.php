<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web;

use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Group as GroupNode;
use CraftCms\Cms\Ui\Ui;
use wmd\designfield\helpers\Sections;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;

/**
 * The Design panel as a Craft UI: one field per option, on the controls of
 * Craft's own kit (no control-panel JavaScript of ours).
 *
 * Each option's input style picks the control: buttons, a dropdown, a switch,
 * radios with a colour or a picture. Styles the kit has no control for yet
 * (slider, position grid) fall back to buttons.
 *
 * @author WMD
 * @since 2.0.0
 */
class OptionControls
{
    // Public Methods
    // =========================================================================

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
        $ui = Ui::make();

        foreach (Sections::arrange($groups, $headings) as $index => $section) {
            $fields = array_map(
                static fn(string $handle) => self::field($groups[$handle], $keys[$handle] ?? $groups[$handle]->default, $keys),
                $section['groups'],
            );

            if ($section['label'] === '') {
                $ui->add(...$fields);
                continue;
            }

            $ui->add(GroupNode::make("design-section-$index", $fields)->label($section['label']));
        }

        return $ui;
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
            Group::INPUT_SELECT, Group::INPUT_CHIP, Group::INPUT_MOTION => ChoicePresentation::Select,
            Group::INPUT_SWATCHES, Group::INPUT_TILES, Group::INPUT_RENDERED => ChoicePresentation::Radios,
            default => count($group->options) > Group::BUTTONS_MAX && $group->input === Group::INPUT_BUTTONS
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

        foreach ($group->options as $key => $option) {
            $key = (string)$key;

            if ($key !== $selected && in_array($key, $group->hidden, true)) {
                continue;
            }

            $choice = ['label' => $option['label'], 'value' => $key];

            if ($option['icon'] !== null) {
                $choice['icon'] = $option['icon'];
            }

            if ($group->input === Group::INPUT_SWATCHES && $option['swatch'] !== null) {
                $choice['color'] = $option['swatch'];
            }

            // A picture whose file is missing would show as a broken image: leave it out.
            if (in_array($group->input, [Group::INPUT_TILES, Group::INPUT_RENDERED], true) && $option['image'] !== null) {
                $picture = app(Groups::class)->picture($option['image']);

                if ($picture !== null) {
                    $choice['thumbnail'] = ['src' => $picture];
                }
            }

            $options[] = $choice;
        }

        return $options;
    }
}
