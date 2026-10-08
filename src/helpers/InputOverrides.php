<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use wmd\designfield\models\Group;

/**
 * Input styles picked on the settings page: `styles` (one style swapped for another on
 * every option that uses it, as choice values like `icons` => `select`), `groups` (one
 * option on every block) and `profiles` (one option on one block), each group handle =>
 * `input`, `iconsOnly` and `textOnly`; `meta`, other labels and icons for options
 * (group handle => option key => `label` and/or `icon`); and `sections`, the heading an
 * option is listed under in the panel (group handle => heading).
 *
 * Craft-free, so the settings model and the registry share one reading of the array.
 *
 * @author WMD
 * @since 1.0.0
 */
class InputOverrides
{
    // Public Methods
    // =========================================================================

    /**
     * Keeps known input styles and the two keys a style has; drops everything else.
     *
     * @param mixed $raw Decoded settings value or posted JSON
     * @return array{styles:array<string,string>,groups:array<string,array{input:string,iconsOnly:bool,textOnly?:bool}>,profiles:array<string,array<string,array{input:string,iconsOnly:bool,textOnly?:bool}>>,meta:array<string,array<string,array{label?:string,icon?:string}>>,sections:array<string,string>,defaults:array<string,array<string,string>>,hidden:array<string,array<string,string[]>>}
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function clean(mixed $raw): array
    {
        $clean = ['styles' => [], 'groups' => [], 'profiles' => [], 'meta' => [], 'sections' => [], 'defaults' => [], 'hidden' => []];

        if (!is_array($raw)) {
            return $clean;
        }

        foreach ((array)($raw['styles'] ?? []) as $from => $to) {
            if (isset(StyleChoices::LABELS[$from], StyleChoices::LABELS[$to]) && $from !== $to) {
                $clean['styles'][(string)$from] = (string)$to;
            }
        }

        foreach ((array)($raw['groups'] ?? []) as $handle => $choice) {
            if (($style = self::_style($choice)) !== null) {
                $clean['groups'][(string)$handle] = $style;
            }
        }

        foreach ((array)($raw['profiles'] ?? []) as $profile => $choices) {
            foreach ((array)$choices as $handle => $choice) {
                if (($style = self::_style($choice)) !== null) {
                    $clean['profiles'][(string)$profile][(string)$handle] = $style;
                }
            }
        }

        foreach ((array)($raw['sections'] ?? []) as $handle => $section) {
            $section = is_string($section) ? trim($section) : '';
            if ($section !== '') {
                $clean['sections'][(string)$handle] = mb_substr($section, 0, 40);
            }
        }

        foreach ((array)($raw['meta'] ?? []) as $handle => $keys) {
            foreach (is_array($keys) ? $keys : [] as $key => $changes) {
                $meta = self::_meta($changes);
                if ($meta !== []) {
                    $clean['meta'][(string)$handle][(string)$key] = $meta;
                }
            }
        }

        // Tidy up: a block's default changed, and choices hidden on every block.
        foreach ((array)($raw['defaults'] ?? []) as $profile => $choices) {
            foreach (is_array($choices) ? $choices : [] as $handle => $key) {
                if (is_scalar($key) && (string)$key !== '') {
                    $clean['defaults'][(string)$profile][(string)$handle] = (string)$key;
                }
            }
        }
        // Per block: profile => option => keys its picker leaves out.
        foreach ((array)($raw['hidden'] ?? []) as $profile => $groups) {
            foreach (is_array($groups) ? $groups : [] as $handle => $keys) {
                $keys = is_array($keys) ? array_values(array_filter(array_map(static fn(mixed $k) => is_scalar($k) ? (string)$k : '', $keys), 'strlen')) : [];
                if ($keys !== []) {
                    $clean['hidden'][(string)$profile][(string)$handle] = $keys;
                }
            }
        }

        return $clean;
    }

    /**
     * Drops entries that point at nothing (a removed block or group) and per-block
     * styles the group cannot take. An everywhere style that some groups cannot take
     * stays: it is skipped for those groups only.
     *
     * Style swaps stay too: they are skipped per group in the same way.
     *
     * @param array{styles:array<string,string>,groups:array<string,mixed>,profiles:array<string,array<string,mixed>>,meta:array<string,array<string,mixed>>,sections:array<string,string>,defaults:array<string,array<string,string>>,hidden:array<string,array<string,string[]>>} $inputs Cleaned
     * @param Registry $registry Built with these inputs
     * @return array{styles:array<string,string>,groups:array<string,array{input:string,iconsOnly:bool,textOnly?:bool}>,profiles:array<string,array<string,array{input:string,iconsOnly:bool,textOnly?:bool}>>,meta:array<string,array<string,array{label?:string,icon?:string}>>,sections:array<string,string>,defaults:array<string,array<string,string>>,hidden:array<string,array<string,string[]>>}
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function prune(array $inputs, Registry $registry): array
    {
        foreach ($registry->inputNotes as $note) {
            if ($note['scope'] === 'styles') {
                continue;
            }

            if ($note['scope'] === 'groups') {
                if ($note['kind'] === 'missing') {
                    unset($inputs['groups'][$note['group']]);
                }
                continue;
            }

            if ($note['kind'] === 'missing' && $note['group'] === '') {
                unset($inputs['profiles'][$note['profile']]);
            } elseif ($note['kind'] === 'missing' || $note['kind'] === 'unsupported') {
                unset($inputs['profiles'][$note['profile']][$note['group']]);
            }
        }

        $inputs['profiles'] = array_filter($inputs['profiles']);

        // Labels and icons for options no block has any more.
        foreach ($inputs['meta'] as $handle => $keys) {
            $inputs['meta'][$handle] = array_intersect_key($keys, $registry->baseOptions[$handle] ?? []);
        }
        $inputs['meta'] = array_filter($inputs['meta']);

        // Defaults for blocks or options that are gone, or keys they no longer have.
        foreach ($inputs['defaults'] as $profile => $choices) {
            $groups = $registry->profiles[$profile] ?? [];
            $inputs['defaults'][$profile] = array_filter($choices, static fn(string $key, string $handle) => isset($groups[$handle]->options[$key]), ARRAY_FILTER_USE_BOTH);
        }
        $inputs['defaults'] = array_filter($inputs['defaults']);
        foreach ($inputs['hidden'] as $profile => $choices) {
            $groups = $registry->profiles[$profile] ?? [];
            foreach ($choices as $handle => $keys) {
                $choices[$handle] = isset($groups[$handle]) ? array_values(array_intersect($keys, array_map('strval', array_keys($groups[$handle]->options)))) : [];
            }
            $inputs['hidden'][$profile] = array_filter($choices);
        }
        $inputs['hidden'] = array_filter($inputs['hidden']);

        return $inputs;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param mixed $choice
     * @return ?array{input:string,iconsOnly:bool,textOnly?:bool}
     */
    private static function _style(mixed $choice): ?array
    {
        if (!is_array($choice) || !in_array($choice['input'] ?? null, Group::INPUTS, true)) {
            return null;
        }

        $buttons = $choice['input'] === Group::INPUT_BUTTONS;
        $style = ['input' => $choice['input'], 'iconsOnly' => $buttons && !empty($choice['iconsOnly'])];

        // Stored only when on, so older saved styles read the same.
        if ($buttons && !$style['iconsOnly'] && !empty($choice['textOnly'])) {
            $style['textOnly'] = true;
        }

        return $style;
    }

    /**
     * A label (trimmed, up to 60 characters) and a Craft icon name, each kept only when set.
     *
     * @param mixed $changes
     * @return array{label?:string,icon?:string}
     */
    private static function _meta(mixed $changes): array
    {
        if (!is_array($changes)) {
            return [];
        }

        $meta = [];
        $label = trim((string)($changes['label'] ?? ''));
        $icon = trim((string)($changes['icon'] ?? ''));

        if ($label !== '') {
            $meta['label'] = mb_substr($label, 0, 60);
        }

        if ($icon !== '' && preg_match('/^[a-z0-9-]+$/', $icon)) {
            $meta['icon'] = $icon;
        }

        return $meta;
    }
}
