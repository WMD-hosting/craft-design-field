<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web;

use Craft;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\web\View;
use wmd\designfield\helpers\Motion;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\Sections;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\Plugin;

/**
 * Renders one group of the Design panel in its input style: buttons, dropdown,
 * colour swatches, picture tiles, position grid or slider.
 *
 * Tiles and the position grid are styled radio buttons and swatches are a
 * button group, so they work and stay keyboard accessible without script;
 * only the slider and "show only what applies" need the small script below.
 *
 * @author WMD
 * @since 1.0.0
 */
class GroupInput
{
    // Const Properties
    // =========================================================================

    /**
     * Milliseconds before a tooltip shows (Craft's default is 500).
     */
    public const TOOLTIP_DELAY = 150;

    // Public Methods
    // =========================================================================

    /**
     * @param Group $group
     * @param string $selected Current key
     * @param string $fieldHandle Design field handle, for input names
     * @param bool $disabled
     * @param bool $visible Whether the group applies to the current choices
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function render(Group $group, string $selected, string $fieldHandle, bool $disabled, bool $visible): string
    {
        self::registerAssets();

        $name = "{$fieldHandle}[$group->handle]";
        $group = self::_shown($group, $selected);

        if ($group->input === Group::INPUT_CHIP) {
            return self::_chip($group, $selected, $name, $disabled, $visible);
        }
        $config = [
            'label' => $group->label,
            'id' => Html::id("$fieldHandle-$group->handle"),
            'name' => $name,
            'value' => $selected,
            'disabled' => $disabled,
            'headingSuffix' => $group->instructions !== '' ? Html::tag('span', Html::encode($group->instructions), ['class' => 'info']) : null,
            'fieldAttributes' => array_filter([
                'data' => array_filter([
                    'df-group' => $group->handle,
                    'df-show-if' => $group->showIf !== [] ? Json::encode($group->showIf) : null,
                ]),
                'hidden' => $visible ? null : true,
            ]),
            // Tile looks take the whole row, so their tiles run across it instead of stacking.
            // A long swatch row too, or it wraps in a narrow cell beside a full-row neighbour.
            'fieldClass' => in_array($group->input, [Group::INPUT_TILES, Group::INPUT_MOTION, Group::INPUT_RENDERED], true)
                || ($group->input === Group::INPUT_SWATCHES && count($group->options) > 6) ? 'df-wide' : null,
        ];

        return match ($group->input) {
            Group::INPUT_SELECT => Cp::selectFieldHtml($config + ['options' => self::_plainOptions($group)]),
            Group::INPUT_SWATCHES => Cp::buttonGroupFieldHtml($config + ['options' => self::_swatchOptions($group)]),
            Group::INPUT_TILES => Cp::fieldHtml(self::_tiles($group, $name, $selected, $disabled), $config + ['fieldset' => true]),
            Group::INPUT_MOTION => Cp::fieldHtml(self::_motion($group, $name, $selected, $disabled), $config + ['fieldset' => true]),
            // Drawn with the site's stylesheet; without one, plain buttons.
            Group::INPUT_RENDERED => self::stylesheet() !== ''
                ? Cp::fieldHtml(self::_rendered($group, $name, $selected, $disabled), $config + ['fieldset' => true])
                : Cp::buttonGroupFieldHtml($config + ['options' => self::_buttonOptions($group)]),
            Group::INPUT_POSITION => Cp::fieldHtml(self::_position($group, $name, $selected, $disabled), $config + ['fieldset' => true]),
            Group::INPUT_SLIDER => Cp::fieldHtml(self::_slider($group, $name, $selected, $disabled), $config),
            Group::INPUT_TOGGLE => self::_toggle($group, $selected, $config),
            default => Cp::buttonGroupFieldHtml($config + ['options' => self::_buttonOptions($group)]),
        };
    }

    /**
     * The preset row: one button per preset; a click sets that preset's choices
     * in the panel, which the editor can then change one by one.
     *
     * @param array<int,array{label:string,values:array<string,string>}> $presets From Registry::presetsFor()
     * @param array<string,Group> $groups The block's groups, to describe each preset
     * @param bool $disabled
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function presets(array $presets, array $groups, bool $disabled): string
    {
        if ($presets === []) {
            return '';
        }

        self::registerAssets();
        $buttons = '';

        foreach ($presets as $preset) {
            $summary = [];

            foreach ($preset['values'] as $handle => $key) {
                $summary[] = $groups[$handle]->label . ': ' . $groups[$handle]->options[$key]['label'];
            }

            $buttons .= Html::button(Html::encode($preset['label']) . self::_tooltip(implode(' · ', $summary), 'button'), [
                'type' => 'button',
                'class' => ['btn', 'small', 'df-preset'],
                'data' => ['df-preset' => $preset['values']],
                'disabled' => $disabled,
                'onclick' => 'DesignField.apply(this)',
            ]);
        }

        return Html::tag('div', Html::tag('span', Craft::t('app', 'Presets'), ['class' => 'df-presets-label']) . $buttons, [
            'class' => 'df-presets',
            'role' => 'group',
            'aria-label' => Craft::t('app', 'Presets'),
        ]);
    }

    /**
     * A profile's Design panel with its defaults, for the settings page preview.
     *
     * Every input is tied to a form that does not exist (`form="df-preview"`), so the
     * browser never submits it with the settings: the panel works (layouts show and hide
     * their options, presets apply) but nothing in it is saved.
     *
     * @param Registry $registry
     * @param string $profile
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function preview(Registry $registry, string $profile): string
    {
        $value = $registry->value([], $profile);
        $keys = $value->keys();
        $html = self::presets($registry->presetsFor($profile, $value->groups()), $value->groups(), false);

        $html .= self::fields($value->groups(), $keys, 'dfPreview', false);

        $html = preg_replace('/<(input|select|textarea|button)\b/', '<$1 form="df-preview"', $html);

        return Html::tag('div', $html, ['class' => 'design-field']);
    }

    /**
     * A panel's options, under their section headings when they have any (helpers/Sections).
     * A section is a disclosure, open by default; one whose options the current layout
     * all hides is hidden by the panel script.
     *
     * @param array<string,Group> $groups
     * @param array<string,string> $keys Current key per group
     * @param string $fieldHandle
     * @param bool $disabled
     * @param string $layout Design::LAYOUT_*: 'inline' drops the headings; 'side' sizes each section by its option count
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function fields(array $groups, array $keys, string $fieldHandle, bool $disabled, string $layout = 'sections'): string
    {
        $html = '';

        foreach (Sections::arrange($groups, $layout !== 'inline') as $section) {
            $fields = '';
            $chips = '';
            $chipsAt = null;
            $count = 0;
            foreach ($section['groups'] as $handle) {
                $input = self::render($groups[$handle], $keys[$handle], $fieldHandle, $disabled, $groups[$handle]->appliesTo($keys));
                // A section's chips share one row, where its first chip is.
                if ($groups[$handle]->input === Group::INPUT_CHIP) {
                    $chipsAt ??= strlen($fields);
                    $chips .= $input;
                    continue;
                }
                $fields .= $input;
                $count++;
            }
            if ($chipsAt !== null) {
                $row = Html::tag('div',
                    Html::tag('div', Html::tag('label', Craft::t('design-field', 'Switches')), ['class' => 'heading']) .
                    Html::tag('div', $chips, ['class' => ['input', 'df-chip-row']]),
                    ['class' => ['field', 'df-chips-field'], 'role' => 'group', 'data' => ['df-chips' => true]],
                );
                $fields = substr($fields, 0, $chipsAt) . $row . substr($fields, $chipsAt);
            }

            $html .= $section['label'] === ''
                ? $fields
                : Html::tag('details',
                    Html::tag('summary', Html::encode($section['label']), ['class' => 'df-section-title']) .
                    Html::tag('div', $fields, ['class' => 'df-section-body']),
                    [
                        'class' => 'df-section',
                        'open' => true,
                        'data' => ['df-section' => $section['label']],
                        // Side by side: a section asks for one column per option (its Switches row is one);
                        // the panel script recounts the shown ones.
                        'style' => ['--df-n' => (string)($count + ($chips === '' ? 0 : 1))],
                    ],
                );
        }

        return $html;
    }

    /**
     * Wraps a Design panel in the field's view mode: as is (panel), behind a one-line
     * summary of what is set with Edit (summary), or in a closed section (collapsed). The
     * panel stays in the form either way, so every choice is posted.
     *
     * @param string $mode One of the Design::VIEW_* constants
     * @param DesignValue $value
     * @param string $panel The panel HTML
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function view(string $mode, DesignValue $value, string $panel): string
    {
        if ($mode !== 'summary' && $mode !== 'collapsed') {
            return $panel;
        }

        self::registerAssets();
        $groups = $value->groups();
        $chips = '';

        foreach ($value->changes() as $handle => $key) {
            $chips .= Html::tag('span', Html::encode($groups[$handle]->label . ': ' . $groups[$handle]->options[$key]['label']), ['class' => 'df-summary-chip']);
        }

        // Labels and defaults, so the summary follows the editor's changes before saving.
        $map = [];
        foreach ($groups as $handle => $group) {
            $map[$handle] = [
                'label' => $group->label,
                'default' => $group->default,
                'options' => array_map(static fn(array $option) => $option['label'], $group->options),
            ];
        }

        $count = count($value->changes());
        $countText = $count === 0
            ? Craft::t('design-field', 'Defaults')
            : Craft::t('design-field', '{n, plural, =1{1 change} other{# changes}}', ['n' => $count]);
        $attributes = ['class' => ['df-view', "df-view--$mode"], 'data' => ['df-view' => Json::encode($map)]];

        if ($mode === 'collapsed') {
            return Html::tag('details',
                Html::tag('summary',
                    Html::tag('span', Craft::t('design-field', 'Options'), ['class' => 'df-view-title']) .
                    Html::tag('span', $countText, ['class' => 'light', 'data' => ['df-summary-count' => true]]) .
                    Html::tag('span', $chips, ['class' => 'df-summary-chips', 'data' => ['df-summary' => true]]),
                ) . $panel,
                $attributes,
            );
        }

        return Html::tag('div',
            Html::tag('div',
                Html::tag('span', $chips !== '' ? $chips : Html::tag('span', Craft::t('design-field', 'All options at their defaults.'), ['class' => 'light']), ['class' => 'df-summary-chips', 'data' => ['df-summary' => true]]) .
                Html::button(Craft::t('design-field', 'Edit'), ['type' => 'button', 'class' => ['btn', 'small'], 'data' => ['icon' => 'edit', 'df-view-toggle' => true], 'aria-expanded' => 'false']),
                ['class' => 'df-summary'],
            ) . Html::tag('div', $panel, ['class' => 'df-view-panel', 'hidden' => true]),
            $attributes,
        );
    }

    /**
     * Registers the panel's CSS and script once per request.
     *
     * @return void
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function registerAssets(): void
    {
        $view = Craft::$app->getView();
        $view->registerCss(self::CSS, [], 'design-field');
        $view->registerJs(self::JS, View::POS_END, 'design-field');
    }

    /**
     * Allows colours, gradients and color-mix(), nothing that can break out of a style attribute.
     *
     * @param ?string $value
     * @return ?string
     *
     * @author WMD
     * @since 1.0.0
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
     * @param Group $group
     * @return array<int,array<string,string>>
     */
    private static function _plainOptions(Group $group): array
    {
        $options = [];
        foreach ($group->options as $key => $option) {
            $options[] = ['label' => $option['label'], 'value' => $key];
        }

        return $options;
    }

    /**
     * Button options; icons-only buttons keep the label as accessible name and tooltip.
     *
     * @param Group $group
     * @return array<int,array<string,mixed>>
     */
    private static function _buttonOptions(Group $group): array
    {
        $options = [];

        foreach ($group->options as $key => $option) {
            // Labels only: plain text buttons (S · M · L), whatever icons or samples the options have.
            if ($group->textOnly) {
                $options[] = ['label' => $option['label'], 'value' => $key];
                continue;
            }

            // A type sample ("Aa" at the option's size) says more than "lg" or "xl".
            $sample = self::safeCss($option['sample']);
            if ($sample !== null) {
                $options[] = [
                    'value' => $key,
                    'labelHtml' => Html::tag('span', 'Aa', ['class' => 'df-sample', 'style' => ['font-size' => $sample]]) . self::_tooltip($option['label'], 'button'),
                    'attributes' => ['aria' => ['label' => $option['label']]],
                ];
                continue;
            }

            if ($group->iconsOnly && $option['icon'] !== null) {
                $options[] = [
                    'value' => $key,
                    'icon' => $option['icon'],
                    // Craft's button template only renders `labelHtml` next to an icon,
                    // so the tooltip element rides in the (otherwise empty) label slot.
                    'labelHtml' => self::_tooltip($option['label'], 'button'),
                    'attributes' => ['aria' => ['label' => $option['label']]],
                ];
                continue;
            }

            $options[] = array_filter([
                'label' => $option['label'],
                'value' => $key,
                'icon' => $option['icon'],
            ], static fn($item) => $item !== null);
        }

        return $options;
    }

    /**
     * Colour chips; options without a swatch (like `auto`) show their label.
     *
     * @param Group $group
     * @return array<int,array<string,mixed>>
     */
    private static function _swatchOptions(Group $group): array
    {
        $options = [];

        foreach ($group->options as $key => $option) {
            $swatch = self::safeCss($option['swatch']);

            if ($swatch === null) {
                $options[] = ['label' => $option['label'], 'value' => $key];
                continue;
            }

            $chip = Html::tag('span', '', [
                'class' => ['df-swatch', in_array($swatch, ['transparent', 'none'], true) ? 'df-swatch--empty' : null],
                'style' => ['background' => $swatch],
            ]);

            $options[] = [
                'value' => $key,
                'labelHtml' => $chip . self::_tooltip($option['label'], 'button'),
                'attributes' => ['aria' => ['label' => $option['label']]],
            ];
        }

        return $options;
    }

    /**
     * Picture cards as radio buttons.
     *
     * @param Group $group
     * @param string $name
     * @param string $selected
     * @param bool $disabled
     * @return string
     */
    private static function _tiles(Group $group, string $name, string $selected, bool $disabled): string
    {
        $html = '';

        foreach ($group->options as $key => $option) {
            $picture = $option['image'] !== null
                ? Html::tag('img', '', ['src' => $option['image'], 'alt' => '', 'loading' => 'lazy'])
                : Html::tag('span', Html::encode(mb_substr($option['label'], 0, 1)), ['class' => 'df-tile-blank']);

            $html .= Html::tag('label',
                Html::radio($name, $key === $selected, ['value' => $key, 'disabled' => $disabled]) .
                Html::tag('span', $picture, ['class' => 'df-tile-img']) .
                Html::tag('span', Html::encode($option['label']), ['class' => 'df-tile-label']),
                ['class' => 'df-tile'],
            );
        }

        return Html::tag('div', $html, ['class' => 'df-tiles']);
    }

    /**
     * Motion tiles as radio buttons: a small stage that plays the choice's motion on hover,
     * focus and when picked; a choice without one (None, Block default) stays still.
     *
     * @param Group $group
     * @param string $name
     * @param string $selected
     * @param bool $disabled
     * @return string
     */
    private static function _motion(Group $group, string $name, string $selected, bool $disabled): string
    {
        $html = '';

        foreach ($group->options as $key => $option) {
            $motion = $option['motion'];
            $card = Html::tag('span', Html::tag('i', '') . Html::tag('i', ''), ['class' => 'df-m-b']);

            $html .= Html::tag('label',
                Html::radio($name, $key === $selected, ['value' => $key, 'disabled' => $disabled]) .
                Html::tag('span', Html::tag('span', '', ['class' => 'df-m-a']) . $card, ['class' => 'df-motion-stage', 'aria-hidden' => 'true']) .
                Html::tag('span', Html::encode($option['label']), ['class' => 'df-motion-label']),
                [
                    'class' => 'df-motion',
                    'data' => [
                        'df-motion' => $motion ?? '',
                        'df-kind' => $motion !== null ? Motion::kind($motion) : null,
                    ],
                ],
            );
        }

        return Html::tag('div', $html, ['class' => 'df-motions']);
    }

    /**
     * The group as its picker shows it: without the choices hidden for this block (Tidy up),
     * except the one the block has. A toggle or chip keeps both choices, a position grid
     * every cell (one gone would move the others).
     *
     * @param Group $group
     * @param string $selected
     * @return Group
     */
    private static function _shown(Group $group, string $selected): Group
    {
        $drop = array_diff($group->hidden, [$selected]);

        if ($drop === [] || in_array($group->input, [Group::INPUT_TOGGLE, Group::INPUT_CHIP, Group::INPUT_POSITION], true)) {
            return $group;
        }

        return new Group(
            handle: $group->handle,
            label: $group->label,
            options: array_diff_key($group->options, array_flip($drop)),
            default: $group->default,
            aliases: $group->aliases,
            input: $group->input,
            instructions: $group->instructions,
            iconsOnly: $group->iconsOnly,
            showIf: $group->showIf,
            columns: $group->columns,
            textOnly: $group->textOnly,
            section: $group->section,
        );
    }

    /**
     * The site's stylesheet for Rendered tiles (`previewStylesheet`), '' when none is set.
     *
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function stylesheet(): string
    {
        $url = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->previewStylesheet));

        return $url === '' ? '' : (string)Craft::getAlias($url);
    }

    /**
     * Rendered tiles as radio buttons: each holds a small card drawn by the panel script in a
     * shadow root with the site's stylesheet and the choice's classes; `auto` is a plain tile.
     *
     * @param Group $group
     * @param string $name
     * @param string $selected
     * @param bool $disabled
     * @return string
     */
    private static function _rendered(Group $group, string $name, string $selected, bool $disabled): string
    {
        $html = '';

        foreach ($group->options as $key => $option) {
            $card = $option['preview'] !== null
                ? Html::tag('span', '', ['class' => 'df-r-host', 'data' => ['df-preview' => $option['preview']]])
                : Html::tag('span', Html::encode($option['label']), ['class' => 'df-r-host df-r-auto']);

            $html .= Html::tag('label',
                Html::radio($name, $key === $selected, ['value' => $key, 'disabled' => $disabled]) .
                $card .
                Html::tag('span', Html::encode($option['label']), ['class' => 'df-r-label']),
                ['class' => 'df-rtile'],
            );
        }

        return Html::tag('div', $html, [
            'class' => 'df-rendered',
            'data' => [
                'df-css' => self::stylesheet(),
                'df-attrs' => Json::encode((object)Plugin::getInstance()->getSettings()->previewAttributes),
            ],
        ]);
    }

    /**
     * A grid of cells as radio buttons; `auto` sits before the grid as text.
     *
     * @param Group $group
     * @param string $name
     * @param string $selected
     * @param bool $disabled
     * @return string
     */
    private static function _position(Group $group, string $name, string $selected, bool $disabled): string
    {
        $cells = '';
        $auto = '';
        $count = 0;

        foreach ($group->options as $key => $option) {
            $radio = Html::radio($name, $key === $selected, ['value' => $key, 'disabled' => $disabled, 'aria-label' => $option['label']]);

            if ($key === Group::AUTO) {
                $auto = Html::tag('label', $radio . Html::tag('span', Html::encode($option['label'])), ['class' => 'df-cell df-cell--text']);
                continue;
            }

            $mark = $option['icon'] !== null ? Html::tag('span', Cp::iconSvg($option['icon']) ?? '', ['class' => 'cp-icon']) : Html::tag('span', '', ['class' => 'df-dot']);
            $cells .= Html::tag('label', $radio . $mark . self::_tooltip($option['label'], 'label'), ['class' => 'df-cell']);
            $count++;
        }

        $columns = $group->columns > 0 ? $group->columns : $count;

        return Html::tag('div',
            $auto . Html::tag('div', $cells, ['class' => 'df-grid', 'style' => ['grid-template-columns' => "repeat($columns, 2.25rem)"]]),
            ['class' => 'df-position'],
        );
    }

    /**
     * A range input over the options in order, writing the key to a hidden input.
     *
     * @param Group $group
     * @param string $name
     * @param string $selected
     * @param bool $disabled
     * @return string
     */
    private static function _slider(Group $group, string $name, string $selected, bool $disabled): string
    {
        $keys = array_map('strval', array_keys($group->options));
        $labels = array_values(array_map(static fn($option) => $option['label'], $group->options));
        $index = max(0, (int)array_search($selected, $keys, true));

        return Html::tag('div',
            Html::input('range', null, (string)$index, [
                'min' => 0,
                'max' => count($keys) - 1,
                'step' => 1,
                'disabled' => $disabled,
                'aria-valuetext' => $labels[$index] ?? '',
                'oninput' => 'DesignField.slide(this)',
                'data' => ['keys' => $keys, 'labels' => $labels],
            ]) .
            Html::hiddenInput($name, $keys[$index] ?? '') .
            Html::tag('span', Html::encode($labels[$index] ?? ''), ['class' => 'df-slider-value']),
            ['class' => 'df-slider'],
        );
    }

    /**
     * An on/off pill: pressed stores the second option, not pressed the first. Its own
     * hidden input carries the key, so it reads and saves like every other look.
     *
     * @param Group $group
     * @param string $selected
     * @param string $name
     * @param bool $disabled
     * @param bool $visible
     * @return string
     */
    private static function _chip(Group $group, string $selected, string $name, bool $disabled, bool $visible): string
    {
        $keys = array_map('strval', array_keys($group->options));
        $on = $selected === $keys[1];

        return Html::tag('span',
            Html::hiddenInput($name, $on ? $keys[1] : $keys[0]) .
            Html::button(Html::encode($group->label), [
                'type' => 'button',
                'class' => 'df-chip-toggle',
                'aria-pressed' => $on ? 'true' : 'false',
                'disabled' => $disabled,
                'title' => $group->instructions !== '' ? $group->instructions : null,
                'data' => ['on' => $keys[1], 'off' => $keys[0]],
            ]),
            [
                'class' => 'df-chip-item',
                'data' => array_filter([
                    'df-group' => $group->handle,
                    'df-show-if' => $group->showIf !== [] ? Json::encode($group->showIf) : null,
                ]),
                'hidden' => $visible ? null : true,
            ],
        );
    }

    /**
     * A lightswitch: on stores the second option, off the first. Craft posts an
     * empty value when off, which the field turns back into the first option.
     *
     * @param Group $group
     * @param string $selected
     * @param array<string,mixed> $config
     * @return string
     */
    private static function _toggle(Group $group, string $selected, array $config): string
    {
        [$off, $on] = array_map('strval', array_keys($group->options));

        return Cp::lightswitchFieldHtml([
            'on' => $selected === $on,
            'value' => $on,
            'onLabel' => $group->options[$on]['label'],
            'offLabel' => $group->options[$off]['label'],
        ] + $config);
    }

    /**
     * @param string $text
     * @param string $trigger Selector of the element the tooltip belongs to
     * @return string
     */
    private static function _tooltip(string $text, string $trigger): string
    {
        return Html::tag('craft-tooltip', '', [
            'text' => $text,
            'placement' => 'top',
            'delay' => (string)self::TOOLTIP_DELAY,
            'trigger' => $trigger,
            'aria-hidden' => 'true',
        ]);
    }

    // Assets
    // =========================================================================

    private const CSS = <<<'CSS'
.design-field{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--m) var(--l);align-items:start}
.design-field>.field,.df-section-body>.field{margin:0!important}
.design-field .field[hidden],.df-section[hidden]{display:none!important}
.design-field>.df-wide,.design-field>.df-presets,.design-field>.df-section,.df-section-body>.df-wide{grid-column:1/-1}
.df-section{border-top:1px solid var(--hairline-color,#e5e7eb);padding-top:var(--s,8px)}
.df-section>summary{display:flex;align-items:center;gap:6px;cursor:pointer;list-style:none;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--medium-text-color,#606d7b)}
.df-section>summary::-webkit-details-marker{display:none}
.df-section>summary::before{content:'';width:6px;height:6px;border:solid currentColor;border-width:0 1.5px 1.5px 0;transform:rotate(-45deg);transition:transform .15s}
.df-section[open]>summary::before{transform:rotate(45deg)}
.design-field.df-side{display:flex;flex-wrap:wrap}
.df-side>.df-presets,.df-side>.df-wide{flex:1 1 100%}
.df-side>.field{flex:1 1 200px;min-width:0}
.df-side>.df-section{flex:1 1 calc(var(--df-n,1) * 200px + (var(--df-n,1) - 1) * var(--l,24px));min-width:0}
.df-side>.df-section:has(.df-wide){flex-basis:100%}
.df-section-body{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--m) var(--l);align-items:start;margin-top:var(--s,8px)}
.df-view--summary .df-summary{display:flex;flex-wrap:wrap;align-items:center;gap:6px var(--s,8px)}
.df-view--summary .df-view-panel{margin-top:var(--m,16px)}
.df-summary-chips{display:inline-flex;flex-wrap:wrap;gap:4px}
.df-summary-chip{display:inline-block;padding:2px 8px;border-radius:999px;background:var(--gray-100,#e5e7eb);font-size:12px}
.df-view--collapsed>summary{display:flex;flex-wrap:wrap;align-items:center;gap:6px var(--s,8px);cursor:pointer;padding:6px 0;list-style:none}
.df-view--collapsed>summary::-webkit-details-marker{display:none}
.df-view--collapsed>summary::before{content:'';width:7px;height:7px;margin:0 4px 0 2px;border:solid currentColor;border-width:0 2px 2px 0;transform:rotate(-45deg);transition:transform .15s}
.df-view--collapsed[open]>summary::before{transform:rotate(45deg)}
.df-view--collapsed>summary .df-view-title{font-weight:600}
.df-view--collapsed[open]>summary .df-summary-chips{display:none}
.df-view--collapsed[open]>.design-field{margin-top:var(--s,8px)}
.df-chip-row{display:flex;flex-wrap:wrap;gap:6px}
.df-chip-item[hidden]{display:none!important}
.df-chip-toggle{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;border:1px solid var(--hairline-color,#e5e7eb);background:var(--white,#fff);font-size:13px;line-height:1.4;cursor:pointer;color:inherit}
.df-chip-toggle::before{content:'';width:8px;height:8px;border-radius:999px;box-shadow:inset 0 0 0 1.5px currentColor;opacity:.5}
.df-chip-toggle[aria-pressed=true]{background:var(--gray-600,#4b5563);border-color:transparent;color:#fff}
.df-chip-toggle[aria-pressed=true]::before{background:currentColor;box-shadow:none;opacity:1}
.df-chip-toggle:disabled{cursor:default;opacity:.6}
.df-presets{display:flex;flex-wrap:wrap;align-items:center;gap:var(--xs,4px) var(--s,8px);padding-bottom:var(--s,8px);border-bottom:1px solid var(--hairline-color,#e5e7eb)}
.df-presets:has(~.df-section){padding-bottom:0;border-bottom:0}
.df-presets-label{font-weight:600;margin-right:var(--xs,4px)}
.df-preset.df-applied{box-shadow:0 0 0 2px var(--link-color,#2563eb)}
.design-field .btn .label:has(>craft-tooltip:only-child){display:none}
.design-field .btn .label:has(>.df-swatch){display:inline-flex;align-items:center}
.design-field .btngroup:has(.df-swatch),.design-field .btngroup:has(.df-sample){flex-wrap:wrap;row-gap:2px}
.design-field .btngroup .btn:has(.df-swatch){padding-inline:6px}
.df-sample{display:inline-block;line-height:1;font-weight:600}
.df-swatch{display:inline-block;width:1.25rem;height:1.25rem;border-radius:999px;box-shadow:inset 0 0 0 1px rgb(0 0 0 / .15)}
.df-swatch--empty{background:repeating-conic-gradient(#e5e7eb 0 25%,#fff 0 50%) 50%/8px 8px!important}
.df-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:var(--s)}
.df-tile{position:relative;display:flex;flex-direction:column;gap:4px;padding:6px;border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px);cursor:pointer;background:var(--white,#fff)}
.df-tile:hover{border-color:var(--medium-hairline-color,#cbd5e1)}
.df-tile:has(input:checked){border-color:var(--link-color,#2563eb);box-shadow:0 0 0 2px var(--link-color,#2563eb)}
.df-tile:has(input:focus-visible){outline:2px solid var(--focus-ring-color,#2563eb);outline-offset:2px}
.df-tile input,.df-cell input{position:absolute;opacity:0;pointer-events:none}
.df-tile-img{display:block;aspect-ratio:16/10;overflow:hidden;border-radius:4px;background:var(--gray-050,#f3f4f6)}
.df-tile-img img{width:100%;height:100%;object-fit:contain;object-position:top center;background:#fff}
.df-tile-blank{display:grid;place-items:center;height:100%;font-size:1.5rem;color:var(--light-text-color,#9ca3af)}
.df-tile-label{font-size:12px;line-height:1.3}
.df-rendered{display:flex;flex-wrap:wrap;gap:6px}
.df-rtile{position:relative;display:flex;flex-direction:column;gap:3px;width:92px;padding:4px;border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px);cursor:pointer;background:var(--white,#fff)}
.df-rtile:hover{border-color:var(--medium-hairline-color,#cbd5e1)}
.df-rtile:has(input:checked){border-color:var(--link-color,#2563eb);box-shadow:0 0 0 2px var(--link-color,#2563eb)}
.df-rtile:has(input:focus-visible){outline:2px solid var(--focus-ring-color,#2563eb);outline-offset:2px}
.df-rtile input{position:absolute;opacity:0;pointer-events:none}
.df-r-host{display:block;align-self:stretch;width:100%;height:52px;border-radius:4px;background:var(--gray-050,#f3f4f6)}
.df-r-auto{display:grid;place-items:center;font-size:11px;color:var(--light-text-color,#6b7280);text-align:center}
.df-r-label{font-size:12px;line-height:1.25}
.df-motions{display:flex;flex-wrap:wrap;gap:6px}
.df-motion{position:relative;display:flex;flex-direction:column;gap:3px;width:86px;padding:4px;border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px);cursor:pointer;background:var(--white,#fff)}
.df-motion:hover{border-color:var(--medium-hairline-color,#cbd5e1)}
.df-motion:has(input:checked){border-color:var(--link-color,#2563eb);box-shadow:0 0 0 2px var(--link-color,#2563eb)}
.df-motion:has(input:focus-visible){outline:2px solid var(--focus-ring-color,#2563eb);outline-offset:2px}
.df-motion input{position:absolute;opacity:0;pointer-events:none}
.df-motion-stage{position:relative;display:block;height:44px;overflow:hidden;border-radius:4px;background:var(--gray-050,#f3f4f6);perspective:160px}
.df-m-a,.df-m-b{position:absolute;inset:7px 16px;border-radius:3px;backface-visibility:hidden}
.df-m-a{background:var(--gray-300,#cbd5e1);opacity:0}
.df-m-b{display:flex;flex-direction:column;justify-content:flex-end;gap:3px;padding:5px;background:var(--blue-300,#93c5fd)}
.df-m-b i{display:block;height:3px;width:80%;border-radius:2px;background:var(--white,#fff)}
.df-m-b i+i{width:55%}
.df-motion[data-df-kind=entrance] .df-m-a{display:none}
.df-motion-label{font-size:12px;line-height:1.25}
.df-motion:hover .df-m-a,.df-motion:hover .df-m-b,.df-motion:has(input:focus-visible) .df-m-a,.df-motion:has(input:focus-visible) .df-m-b,.df-motion.df-play .df-m-a,.df-motion.df-play .df-m-b{animation-duration:1.6s;animation-timing-function:cubic-bezier(.3,.7,.4,1);animation-fill-mode:both;animation-iteration-count:infinite}
.df-motion:hover .df-m-a,.df-motion:has(input:focus-visible) .df-m-a,.df-motion.df-play .df-m-a{animation-name:var(--df-a,none)}
.df-motion:hover .df-m-b,.df-motion:has(input:focus-visible) .df-m-b,.df-motion.df-play .df-m-b{animation-name:var(--df-b,none)}
.df-motion.df-play .df-m-a,.df-motion.df-play .df-m-b{animation-iteration-count:1}
.df-motion[data-df-motion=fade]{--df-b:df-in-fade}
.df-motion[data-df-motion=fade-up]{--df-b:df-in-up}
.df-motion[data-df-motion=fade-down]{--df-b:df-in-down}
.df-motion[data-df-motion=from-left]{--df-b:df-in-left}
.df-motion[data-df-motion=from-right]{--df-b:df-in-right}
.df-motion[data-df-motion=zoom-in]{--df-b:df-in-zoom}
.df-motion[data-df-motion=zoom-out]{--df-b:df-in-zoom-out}
.df-motion[data-df-motion=flip-in]{--df-b:df-in-flip}
.df-motion[data-df-motion=blur-in]{--df-b:df-in-blur}
.df-motion[data-df-motion=slide]{--df-a:df-out-slide;--df-b:df-in-slide}
.df-motion[data-df-motion=crossfade]{--df-a:df-out-fade;--df-b:df-in-crossfade}
.df-motion[data-df-motion=fade-through]{--df-a:df-out-through;--df-b:df-in-through}
.df-motion[data-df-motion=cube]{--df-a:df-out-cube;--df-b:df-in-cube}
.df-motion[data-df-motion=coverflow]{--df-a:df-out-coverflow;--df-b:df-in-coverflow}
.df-motion[data-df-motion=flip]{--df-a:df-out-flip;--df-b:df-in-flip-card}
.df-motion[data-df-motion=cards]{--df-a:df-out-cards;--df-b:df-in-cards}
.df-motion[data-df-motion=creative]{--df-a:df-out-creative;--df-b:df-in-creative}
@keyframes df-in-fade{0%{opacity:0}45%,100%{opacity:1}}
@keyframes df-in-up{0%{opacity:0;transform:translateY(14px)}45%,100%{opacity:1;transform:none}}
@keyframes df-in-down{0%{opacity:0;transform:translateY(-14px)}45%,100%{opacity:1;transform:none}}
@keyframes df-in-left{0%{opacity:0;transform:translateX(-36px)}45%,100%{opacity:1;transform:none}}
@keyframes df-in-right{0%{opacity:0;transform:translateX(36px)}45%,100%{opacity:1;transform:none}}
@keyframes df-in-zoom{0%{opacity:0;transform:scale(.4)}45%,100%{opacity:1;transform:none}}
@keyframes df-in-zoom-out{0%{opacity:0;transform:scale(1.6)}45%,100%{opacity:1;transform:none}}
@keyframes df-in-flip{0%{opacity:0;transform:rotateX(85deg)}45%,100%{opacity:1;transform:none}}
@keyframes df-in-blur{0%{opacity:0;filter:blur(6px)}45%,100%{opacity:1;filter:none}}
@keyframes df-out-slide{0%{opacity:1;transform:none}45%,100%{opacity:1;transform:translateX(-140%)}}
@keyframes df-in-slide{0%{transform:translateX(140%)}45%,100%{transform:none}}
@keyframes df-out-fade{0%{opacity:1}45%,100%{opacity:0}}
@keyframes df-in-crossfade{0%{opacity:0}45%,100%{opacity:1}}
@keyframes df-out-through{0%{opacity:1}22%,100%{opacity:0}}
@keyframes df-in-through{0%,22%{opacity:0}50%,100%{opacity:1}}
@keyframes df-out-cube{0%{opacity:1;transform:none}45%,100%{opacity:1;transform:translateX(-50%) rotateY(-90deg) translateX(-50%)}}
@keyframes df-in-cube{0%{transform:translateX(50%) rotateY(90deg) translateX(50%)}45%,100%{transform:none}}
@keyframes df-out-coverflow{0%{opacity:1;transform:none}45%,100%{opacity:.7;transform:translateX(-85%) rotateY(50deg) scale(.75)}}
@keyframes df-in-coverflow{0%{transform:translateX(85%) rotateY(-50deg) scale(.75)}45%,100%{transform:none}}
@keyframes df-out-flip{0%{opacity:1;transform:none}45%,100%{opacity:1;transform:rotateY(180deg)}}
@keyframes df-in-flip-card{0%{transform:rotateY(-180deg)}45%,100%{transform:none}}
@keyframes df-out-cards{0%{opacity:1;transform:none}45%,100%{opacity:1;transform:translateX(-150%) rotate(-18deg)}}
@keyframes df-in-cards{0%{transform:translate(5px,5px) scale(.9)}45%,100%{transform:none}}
@keyframes df-out-creative{0%{opacity:1;transform:none}45%,100%{opacity:0;transform:translateX(-30%) scale(.6)}}
@keyframes df-in-creative{0%{transform:translateX(120%) rotate(12deg)}45%,100%{transform:none}}
@media (prefers-reduced-motion:reduce){.df-motion-stage *{animation:none!important}}
.df-position{display:flex;flex-wrap:wrap;align-items:center;gap:var(--s)}
.df-grid{display:grid;gap:2px;padding:2px;border-radius:var(--medium-border-radius,6px);background:var(--gray-100,#e5e7eb)}
.df-cell{position:relative;display:grid;place-items:center;height:2.25rem;border-radius:4px;background:var(--white,#fff);cursor:pointer}
.df-cell--text{padding:0 .75rem;height:2.25rem;white-space:nowrap;background:var(--gray-100,#e5e7eb);font-size:13px}
.df-cell:has(input:checked){background:var(--gray-600,#4b5563);color:#fff}
.df-cell>craft-tooltip{position:absolute}
.df-cell:has(input:checked) .cp-icon{color:#fff}
.df-cell:has(input:checked) .cp-icon svg,.df-cell:has(input:checked) .cp-icon svg *{fill:currentColor}
.df-cell:has(input:focus-visible){outline:2px solid var(--focus-ring-color,#2563eb)}
.df-dot{width:8px;height:8px;border-radius:999px;background:currentColor;opacity:.6}
.design-field .input:has(>.df-slider){width:100%}
.df-slider{display:flex;align-items:center;gap:var(--s);width:100%;max-width:28rem}
.df-slider input[type=range]{flex:1;min-width:0;width:100%;accent-color:var(--gray-600,#4b5563)}
.df-slider-value{min-width:4.5rem;font-size:13px;white-space:nowrap}
CSS;

    private const JS = <<<'JS'
window.DesignField = window.DesignField || (function() {
    // Current key of a group inside one Design panel, whatever its input style.
    function value(root, group) {
        var field = root.querySelector('[data-df-group="' + group + '"]');
        if (!field) { return ''; }
        var radio = field.querySelector('input[type=radio]:checked');
        if (radio) { return radio.value; }
        var input = field.querySelector('input[type=hidden][name$="[' + group + ']"], select[name$="[' + group + ']"]');
        return input ? input.value : '';
    }
    // Every group's current key in one panel, e.g. for a live preview.
    function keys(root) {
        var out = {};
        root.querySelectorAll('[data-df-group]').forEach(function(field) {
            var group = field.getAttribute('data-df-group');
            out[group] = value(root, group);
        });
        return out;
    }
    // Show a group only when the groups it depends on have one of its keys.
    function refresh(root) {
        root.querySelectorAll('[data-df-show-if]').forEach(function(field) {
            var rules = JSON.parse(field.getAttribute('data-df-show-if'));
            field.hidden = !Object.keys(rules).every(function(group) {
                return rules[group].indexOf(value(root, group)) !== -1;
            });
        });
        root.querySelectorAll('[data-df-chips]').forEach(function(row) {
            row.hidden = !row.querySelector('.df-chip-item:not([hidden])');
        });
        root.querySelectorAll('[data-df-section]').forEach(function(section) {
            section.hidden = !section.querySelector('[data-df-group]:not([hidden])');
            // Side by side: room for the options this layout shows, not every option.
            section.style.setProperty('--df-n', String(section.querySelectorAll('.df-section-body > .field:not([hidden])').length || 1));
        });
        var view = root.closest && root.closest('[data-df-view]');
        if (view) { summarize(view, root); }
    }
    // Summary / collapsed view: list what differs from the defaults, as the editor changes it.
    function summarize(view, root) {
        var map = JSON.parse(view.getAttribute('data-df-view'));
        var list = view.querySelector('[data-df-summary]');
        var count = view.querySelector('[data-df-summary-count]');
        var chips = [];
        root.querySelectorAll('[data-df-group]').forEach(function(field) {
            var handle = field.getAttribute('data-df-group'), info = map[handle];
            if (!info || field.hidden) { return; }
            var key = value(root, handle);
            // A switched-off toggle posts nothing: that is its first option.
            if (key === '') { key = Object.keys(info.options)[0]; }
            if (key !== info.default) { chips.push(info.label + ': ' + (info.options[key] || key)); }
        });
        if (list) {
            list.innerHTML = '';
            chips.forEach(function(text) {
                var chip = document.createElement('span');
                chip.className = 'df-summary-chip';
                chip.textContent = text;
                list.append(chip);
            });
            if (!chips.length && view.classList.contains('df-view--summary')) {
                var none = document.createElement('span');
                none.className = 'light';
                none.textContent = Craft.t('design-field', 'All options at their defaults.');
                list.append(none);
            }
        }
        if (count) { count.textContent = chips.length ? Craft.t('design-field', '{n, plural, =1{1 change} other{# changes}}', {n: chips.length}) : Craft.t('design-field', 'Defaults'); }
    }
    // Motion tiles play their motion once when picked.
    document.addEventListener('change', function(event) {
        var tile = event.target.closest && event.target.closest('.df-motion');
        if (!tile) { return; }
        tile.classList.remove('df-play');
        void tile.offsetWidth;
        tile.classList.add('df-play');
        setTimeout(function() { tile.classList.remove('df-play'); }, 1700);
    });
    // Chips: a click flips the pill and its hidden input (and tells the form it changed).
    document.addEventListener('click', function(event) {
        var chip = event.target.closest && event.target.closest('.df-chip-toggle');
        if (!chip || chip.disabled) { return; }
        var on = chip.getAttribute('aria-pressed') !== 'true';
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        var input = chip.parentNode.querySelector('input[type=hidden]');
        input.value = on ? chip.getAttribute('data-on') : chip.getAttribute('data-off');
        input.dispatchEvent(new Event('change', {bubbles: true}));
        var root = chip.closest('.design-field');
        if (root) { refresh(root); }
    });
    document.addEventListener('click', function(event) {
        var toggle = event.target.closest && event.target.closest('[data-df-view-toggle]');
        if (!toggle) { return; }
        var panel = toggle.closest('[data-df-view]').querySelector('.df-view-panel');
        panel.hidden = !panel.hidden;
        toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        (toggle.querySelector('.label') || toggle).textContent = panel.hidden ? Craft.t('design-field', 'Edit') : Craft.t('design-field', 'Done');
    });
    function refreshAll() {
        document.querySelectorAll('.design-field').forEach(refresh);
    }
    // Button groups set their hidden input without a change event, so watch clicks and keys too.
    ['click', 'change', 'keyup', 'input'].forEach(function(type) {
        document.addEventListener(type, function(event) {
            var root = event.target.closest && event.target.closest('.design-field');
            if (root) { setTimeout(function() { refresh(root); }, 0); }
        }, true);
    });
    function slide(input) {
        var keys = JSON.parse(input.getAttribute('data-keys'));
        var labels = JSON.parse(input.getAttribute('data-labels'));
        var wrap = input.closest('.df-slider');
        wrap.querySelector('input[type=hidden]').value = keys[input.value];
        wrap.querySelector('.df-slider-value').textContent = labels[input.value];
        input.setAttribute('aria-valuetext', labels[input.value]);
        var root = input.closest('.design-field');
        if (root) { refresh(root); }
    }
    // Set one group to a key, through the input itself so Craft sees the change.
    function set(root, group, key) {
        var field = root.querySelector('[data-df-group="' + group + '"]');
        if (!field) { return; }
        var radio = field.querySelector('input[type=radio][value="' + CSS.escape(key) + '"]');
        if (radio) { if (!radio.checked) { radio.click(); } return; }
        var button = field.querySelector('.btngroup [data-value="' + CSS.escape(key) + '"]');
        if (button) { if (button.getAttribute('aria-pressed') !== 'true') { button.click(); } return; }
        var range = field.querySelector('input[type=range]');
        if (range) {
            var index = JSON.parse(range.getAttribute('data-keys')).indexOf(key);
            if (index !== -1) { range.value = index; slide(range); range.dispatchEvent(new Event('change', {bubbles: true})); }
            return;
        }
        var chip = field.querySelector('.df-chip-toggle');
        if (chip) {
            if ((chip.getAttribute('aria-pressed') === 'true') !== (key === chip.getAttribute('data-on'))) { chip.click(); }
            return;
        }
        var lightswitch = field.querySelector('.lightswitch');
        if (lightswitch) {
            var on = key === lightswitch.getAttribute('data-value');
            var instance = window.jQuery && window.jQuery(lightswitch).data('lightswitch');
            if (instance) { on ? instance.turnOn() : instance.turnOff(); }
            return;
        }
        var select = field.querySelector('select');
        if (select) { select.value = key; select.dispatchEvent(new Event('change', {bubbles: true})); }
    }
    // A preset button: set each of its choices, then show what now applies.
    function apply(button) {
        var root = button.closest('.design-field');
        var values = JSON.parse(button.getAttribute('data-df-preset'));
        Object.keys(values).forEach(function(group) { set(root, group, values[group]); });
        refresh(root);
        root.querySelectorAll('.df-preset').forEach(function(other) { other.classList.toggle('df-applied', other === button); });
    }
    return {refresh: refresh, refreshAll: refreshAll, slide: slide, apply: apply, keys: keys};
})();
// Rendered tiles: each card is drawn in a shadow root that adopts the site's stylesheet (fetched
// once per page), inside a root with the brand attributes, so the brand's radius and shadow
// apply while the site's reset stays out of the control panel.
(function() {
    var sheets = {};
    var frame = new CSSStyleSheet();
    // Drawn at twice the size and scaled to half: a radius reads against a card's proportions.
    frame.replaceSync(':host{display:block;width:100%;overflow:hidden}.df-r-root{display:flex;align-items:center;justify-content:center;width:100%;height:52px;background:transparent}.df-r-card{flex:none;display:flex;flex-direction:column;justify-content:flex-end;gap:6px;width:136px;height:80px;padding:12px;box-sizing:border-box;transform:scale(.5)}.df-r-card i{display:block;height:6px;width:70%;border-radius:3px;background:currentColor;opacity:.4}.df-r-card i+i{width:45%}');
    function sheet(url) {
        sheets[url] = sheets[url] || fetch(url, {credentials: 'same-origin'}).then(function(r) { return r.text(); }).then(function(css) {
            // Tailwind's typed defaults (@property --tw-shadow…) only register at document level.
            var props = css.match(/@property\s+--[\w-]+\s*\{[^}]*\}/g);
            if (props) {
                var registered = new CSSStyleSheet();
                registered.replaceSync(props.join(''));
                document.adoptedStyleSheets = document.adoptedStyleSheets.concat(registered);
            }
            // The theme (--radius-xl: var(--brand-radius-xl)…) is declared on :root,:host; declare it
            // on the tile's root too, which carries the brand attributes, so it resolves there.
            var s = new CSSStyleSheet();
            s.replaceSync(css.replace(/@import[^;]+;/g, '').split(':root,:host{').join(':root,:host,.df-r-root{'));
            return s;
        });
        return sheets[url];
    }
    function draw(scope) {
        (scope || document).querySelectorAll('.df-rendered').forEach(function(list) {
            var hosts = list.querySelectorAll('.df-r-host[data-df-preview]:not([data-df-drawn])');
            if (!hosts.length) { return; }
            var attrs = JSON.parse(list.getAttribute('data-df-attrs') || '{}');
            sheet(list.getAttribute('data-df-css')).then(function(site) {
                hosts.forEach(function(host) {
                    if (host.hasAttribute('data-df-drawn')) { return; }
                    host.setAttribute('data-df-drawn', '');
                    var shadow = host.attachShadow({mode: 'open'});
                    shadow.adoptedStyleSheets = [site, frame];
                    var root = document.createElement('div');
                    Object.keys(attrs).forEach(function(name) { root.setAttribute(name, attrs[name]); });
                    root.classList.add('df-r-root');
                    var card = document.createElement('div');
                    card.className = 'df-r-card ' + host.getAttribute('data-df-preview');
                    card.append(document.createElement('i'), document.createElement('i'));
                    root.append(card);
                    shadow.append(root);
                });
            }).catch(function() { /* no stylesheet: the tiles keep their labels */ });
        });
    }
    draw();
    // Panels added later (a new Matrix block, the settings page's block switch).
    new MutationObserver(function(records) {
        if (records.some(function(r) { return r.addedNodes.length; })) { draw(); }
    }).observe(document.body, {childList: true, subtree: true});
    window.DesignField.drawTiles = draw;
})();
window.DesignField.refreshAll();
JS;
}
