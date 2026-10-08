<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use InvalidArgumentException;
use wmd\designfield\models\Group;

/**
 * Turns the three settings tables (groups, options, profiles) into the config
 * array `Registry::fromConfig()` reads, the same shape as `config/design-field.php`.
 *
 * Craft-free on purpose, so the conversion is unit tested.
 *
 * Table conventions:
 * - A group row with a Profile is local to that profile (each block's own `variant`).
 * - An option row's Group is `handle` for a shared group, `profile/handle` for a local one.
 * - A profile row's Groups is a comma list; `group=key` overrides that group's default.
 *   Local groups not listed are put first.
 *
 * @author WMD
 * @since 1.0.0
 */
class SettingsRows
{
    // Public Methods
    // =========================================================================

    /**
     * @param array<int|string,array<string,mixed>> $groupRows
     * @param array<int|string,array<string,mixed>> $optionRows
     * @param array<int|string,array<string,mixed>> $profileRows
     * @return array{groups:array<string,array<string,mixed>>,profiles:array<string,array<int|string,mixed>>}
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function toConfig(array $groupRows, array $optionRows, array $profileRows): array
    {
        $options = [];

        foreach ($optionRows as $row) {
            $group = self::_str($row['group'] ?? null);
            $key = self::_str($row['key'] ?? null);

            if ($group === '' || $key === '') {
                continue;
            }

            $option = ['label' => self::_str($row['label'] ?? null) ?: Group::humanize($key)];

            foreach (['icon', 'swatch', 'image', 'description'] as $meta) {
                if (($metaValue = self::_str($row[$meta] ?? null)) !== '') {
                    $option[$meta] = $metaValue;
                }
            }

            if (($value = self::_str($row['value'] ?? null)) !== '') {
                $option['value'] = $value;
            }

            $options[$group][$key] = $option;
        }

        $groups = [];
        $local = [];

        foreach ($groupRows as $row) {
            $handle = self::_str($row['handle'] ?? null);

            if ($handle === '') {
                continue;
            }

            $profile = self::_str($row['profile'] ?? null);
            $config = array_filter([
                'label' => self::_str($row['label'] ?? null),
                'instructions' => self::_str($row['instructions'] ?? null),
                'tokens' => self::_str($row['tokens'] ?? null),
                'default' => self::_str($row['default'] ?? null),
                'auto' => self::_str($row['auto'] ?? null),
                'input' => self::_str($row['input'] ?? null),
            ], static fn(string $value) => $value !== '');

            if (!empty($row['iconsOnly'])) {
                $config['iconsOnly'] = true;
            }

            // "Show for layouts": a comma list of the block's layout keys.
            $variants = array_values(array_filter(array_map('trim', explode(',', self::_str($row['variants'] ?? null)))));
            if ($variants !== []) {
                $config['variants'] = $variants;
            }

            if (!isset($config['tokens'])) {
                $config['options'] = $options[$profile !== '' ? "$profile/$handle" : $handle] ?? [];
            }

            if ($profile !== '') {
                $local[$profile][$handle] = $config;
                continue;
            }

            $groups[$handle] = $config;
        }

        $profiles = [];

        foreach ($profileRows as $row) {
            $name = self::_str($row['profile'] ?? null);

            if ($name === '') {
                continue;
            }

            $profiles[$name] = self::_profile(self::_str($row['groups'] ?? null), $local[$name] ?? []);
            unset($local[$name]);
        }

        // Local groups whose profile has no row of its own still form a profile.
        foreach ($local as $name => $localGroups) {
            $profiles[$name] = self::_profile('', $localGroups);
        }

        return ['groups' => $groups, 'profiles' => $profiles];
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $list e.g. `variant, tone, columns=4`
     * @param array<string,array<string,mixed>> $localGroups
     * @return array<int|string,mixed>
     */
    private static function _profile(string $list, array $localGroups): array
    {
        $profile = [];
        $listed = [];

        foreach (array_filter(array_map('trim', explode(',', $list))) as $entry) {
            [$handle, $default] = array_pad(array_map('trim', explode('=', $entry, 2)), 2, null);
            $listed[] = $handle;

            if (isset($localGroups[$handle])) {
                $profile[$handle] = $default !== null && $default !== ''
                    ? array_merge($localGroups[$handle], ['default' => $default])
                    : $localGroups[$handle];
                continue;
            }

            if ($default !== null && $default !== '') {
                $profile[$handle] = $default;
                continue;
            }

            $profile[] = $handle;
        }

        $unlisted = array_diff_key($localGroups, array_flip($listed));

        return $unlisted + $profile;
    }

    /**
     * Turns the presets table into the `presets` config.
     *
     * A row is one preset: profile, name and its choices as `group=key, group=key`.
     * Rows saved by 1.0 betas held one choice each (`group`, `key`); they still read,
     * and rows with the same profile and name merge.
     *
     * @param array<int|string,array<string,mixed>> $rows
     * @return array<string,array<string,array<string,string>>> Profile => name => group => key
     * @throws InvalidArgumentException on a choice that is not `group=key`
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function presets(array $rows): array
    {
        $presets = [];

        foreach ($rows as $row) {
            $profile = self::_str($row['profile'] ?? null);
            $name = self::_str($row['name'] ?? null);

            if ($profile === '' || $name === '') {
                continue;
            }

            $choices = isset($row['choices'])
                ? self::_choices(self::_str($row['choices']), $name)
                : array_filter([self::_str($row['group'] ?? null) => self::_str($row['key'] ?? null)], static fn($key, $group) => $group !== '' && $key !== '', ARRAY_FILTER_USE_BOTH);

            if ($choices !== []) {
                $presets[$profile][$name] = ($presets[$profile][$name] ?? []) + $choices;
            }
        }

        return $presets;
    }

    /**
     * The presets table rows for a `presets` config: one row per preset.
     *
     * @param array<string,array<string,array<string,string>>> $presets
     * @return array<int,array{profile:string,name:string,choices:string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function presetRows(array $presets): array
    {
        $rows = [];

        foreach ($presets as $profile => $list) {
            foreach ($list as $name => $values) {
                $rows[] = [
                    'profile' => (string)$profile,
                    'name' => (string)$name,
                    'choices' => implode(', ', array_map(static fn($group, $key) => "$group=$key", array_keys($values), $values)),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param string $list `group=key, group=key`
     * @param string $name Preset name, for the error message
     * @return array<string,string>
     * @throws InvalidArgumentException
     */
    private static function _choices(string $list, string $name): array
    {
        $choices = [];

        foreach (preg_split('/\s*,\s*/', $list, -1, PREG_SPLIT_NO_EMPTY) as $pair) {
            if (!preg_match('/^([\w-]+)\s*=\s*([\w.-]+)$/', $pair, $m)) {
                throw new InvalidArgumentException("Preset \"$name\": \"$pair\" is not a group=key choice.");
            }

            $choices[$m[1]] = $m[2];
        }

        return $choices;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function _str(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
