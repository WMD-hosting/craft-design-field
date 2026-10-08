<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use InvalidArgumentException;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;

/**
 * All configured groups and profiles, built from the `config/design-field.php` array.
 *
 * Craft-free: token files are read through the `$loadTokens` callable so
 * tests can feed plain arrays.
 *
 * @author WMD
 * @since 1.0.0
 */
class Registry
{
    // Const Properties
    // =========================================================================

    /**
     * Profile used when no profile matches the element.
     */
    public const FALLBACK_PROFILE = '*';

    /**
     * Group handles that would shadow DesignValue methods in Twig.
     */
    public const RESERVED_HANDLES = ['classes', 'keys', 'groups', 'invalid', 'toArray', 'has', 'get'];

    // Public Methods
    // =========================================================================

    /**
     * @param array<string,Group> $groups Shared groups
     * @param array<string,array<string,Group>> $profiles Profile name => resolved groups
     * @param array<string,string> $fieldMap Old option field handle => group handle
     * @param array<string,array<string,array<string,string>>> $presets Profile => preset label => group handle => option key
     * @param array<string,array<string,array{input:string,iconsOnly:bool}>> $configInputs Profile => group => input style before the control-panel choices
     * @param list<array<string,string>> $inputNotes Control-panel styles that were skipped, hide a config style, or point at nothing
     * @param array<string,list<string>> $styleUse Choice value (`icons`, `select`…) => handles of the options shown that way, before any swap
     * @param array<string,array<string,array{label:string,icon:?string}>> $baseOptions Group handle => option key => label and icon before the settings page changed them
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $profiles,
        public readonly array $fieldMap = [],
        public readonly array $presets = [],
        public readonly array $configInputs = [],
        public readonly array $inputNotes = [],
        public readonly array $styleUse = [],
        public readonly array $baseOptions = [],
    ) {
    }

    /**
     * Builds the registry from the plugin config array.
     *
     * A profile lists shared group handles; `'group' => 'key'` overrides a
     * shared group's default, and `'group' => [...]` defines a group local to
     * the profile (e.g. each block's own `variant` list).
     *
     * @param array<string,mixed> $config
     * @param callable(string):array<string,mixed> $loadTokens Reads a token file by name
     * @return self
     * @throws InvalidArgumentException on an invalid group or profile
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function fromConfig(array $config, callable $loadTokens): self
    {
        $groups = [];

        // groupOverrides change a group everywhere, on top of hand-written or
        // generated definitions (so they survive regenerating the config).
        foreach ($config['groups'] ?? [] as $handle => $groupConfig) {
            $groupConfig = array_replace_recursive($groupConfig, $config['groupOverrides'][$handle] ?? []);
            $groups[(string)$handle] = self::_group((string)$handle, $groupConfig, $loadTokens);
        }

        $profiles = [];

        foreach ($config['profiles'] ?? [] as $name => $definition) {
            $profile = [];

            foreach ($definition as $key => $value) {
                if (is_int($key)) {
                    $profile[$value] = $groups[$value] ?? throw new InvalidArgumentException("Design profile \"$name\" uses unknown group \"$value\".");
                    continue;
                }

                // An array with options or a token file defines a group local to the
                // profile; any other array overrides a shared group for this profile.
                if (is_array($value) && (isset($value['options']) || isset($value['tokens']))) {
                    $profile[$key] = self::_group($key, $value, $loadTokens);
                    continue;
                }

                if (is_array($value)) {
                    $group = $groups[$key] ?? throw new InvalidArgumentException("Design profile \"$name\" overrides unknown group \"$key\".");
                    $profile[$key] = $group->withOverrides($value);
                    continue;
                }

                $group = $groups[$key] ?? throw new InvalidArgumentException("Design profile \"$name\" uses unknown group \"$key\".");
                $profile[$key] = $group->withDefault((string)$value);
            }

            // profileOverrides change a group for this profile only.
            foreach ($config['profileOverrides'][$name] ?? [] as $handle => $overrides) {
                $profile[$handle] = isset($profile[$handle])
                    ? $profile[$handle]->withOverrides((array)$overrides)
                    : throw new InvalidArgumentException("profileOverrides for \"$name\" name group \"$handle\", which that profile does not show.");
            }

            $profiles[(string)$name] = $profile;
        }

        // Styles picked on the settings page come last, so the control panel wins over
        // every config layer; they change only how a choice looks (input, iconsOnly).
        // First a style swapped site-wide, then one option everywhere, then one block.
        $inputs = InputOverrides::clean($config['inputs'] ?? []);
        // Section headings: the config file's map (every option with that handle), then the settings page.
        $inputs['sections'] = array_replace(InputOverrides::clean(['sections' => $config['sections'] ?? []])['sections'], $inputs['sections']);
        $configInputs = [];
        $notes = [];
        $styleUse = [];
        $baseOptions = [];

        foreach ($profiles as $name => $profile) {
            foreach ($profile as $handle => $group) {
                // Layout pictures found on disk (`tileImages`): a picture per layout, and Picture
                // tiles when every layout has one and the config names no look for it.
                if ($handle === 'variant' && is_callable($config['tileImage'] ?? null)) {
                    $type = str_contains((string)$name, ':') ? substr((string)$name, strrpos((string)$name, ':') + 1) : (string)$name;
                    $images = [];
                    foreach (array_keys($group->options) as $key) {
                        if ($key !== Group::AUTO && $group->options[$key]['image'] === null) {
                            $images[(string)$key] = (string)($config['tileImage']($type, (string)$key) ?? '');
                        }
                    }
                    $group = $group->withImages(array_filter($images));
                    $all = array_filter($group->options, static fn(array $option, $key) => $key !== Group::AUTO && $option['image'] === null, ARRAY_FILTER_USE_BOTH) === [];
                    if ($all && $group->input !== Group::INPUT_TILES && !self::_configSetsInput($config, (string)$name, $handle)) {
                        $group = $group->withOverrides(['input' => Group::INPUT_TILES]);
                    }
                }
                $group = self::_optionMeta($group, $inputs['meta'], $baseOptions, $inputs['sections']);
                // Tidy up, this block only: its default, then the choices its picker leaves out.
                $default = $inputs['defaults'][$name][$handle] ?? null;
                if ($default !== null && isset($group->options[$default])) {
                    $group = $group->withOverrides(['default' => $default]);
                }
                if (isset($inputs['hidden'][$name][$handle])) {
                    $group = $group->withHidden($inputs['hidden'][$name][$handle]);
                }
                $styleUse[StyleChoices::value(['input' => $group->input, 'iconsOnly' => $group->iconsOnly])][$handle] = true;
                $group = self::_swapStyle($group, $inputs['styles'], (string)$name, $notes);
                $profiles[$name][$handle] = $group;
                // What "Default" means for the option: everything before the per-option picks.
                $configInputs[$name][$handle] = ['input' => $group->input, 'iconsOnly' => $group->iconsOnly];
                $choices = array_filter([
                    'profile' => $inputs['profiles'][$name][$handle] ?? null,
                    'groups' => $inputs['groups'][$handle] ?? null,
                ]);

                foreach ($choices as $scope => $style) {
                    $note = ['scope' => $scope, 'profile' => (string)$name, 'group' => (string)$handle, 'input' => $style['input']];

                    if (!in_array($style['input'], $group->supportedInputs(), true)) {
                        $notes[] = ['kind' => 'unsupported'] + $note;
                        continue;
                    }

                    if ($style['input'] !== $group->input && self::_configSetsInput($config, (string)$name, (string)$handle)) {
                        $notes[] = ['kind' => 'hidden'] + $note + ['config' => $group->input];
                    }

                    $profiles[$name][$handle] = $group->withOverrides(StyleChoices::style(StyleChoices::value($style)));
                    break;
                }
            }

            foreach (array_diff_key($inputs['profiles'][$name] ?? [], $profile) as $handle => $style) {
                $notes[] = ['kind' => 'missing', 'scope' => 'profile', 'profile' => (string)$name, 'group' => (string)$handle, 'input' => $style['input']];
            }
        }

        foreach (array_keys(array_diff_key($inputs['profiles'], $profiles)) as $name) {
            $notes[] = ['kind' => 'missing', 'scope' => 'profile', 'profile' => (string)$name, 'group' => '', 'input' => ''];
        }

        foreach ($groups as $handle => $group) {
            $groups[$handle] = self::_swapStyle(self::_optionMeta($group, $inputs['meta'], $baseOptions, $inputs['sections']), $inputs['styles'], '', $notes);
        }

        foreach ($inputs['groups'] as $handle => $style) {
            $shown = isset($groups[$handle]) || array_filter($profiles, static fn(array $profile) => isset($profile[$handle])) !== [];

            if (!$shown) {
                $notes[] = ['kind' => 'missing', 'scope' => 'groups', 'profile' => '', 'group' => (string)$handle, 'input' => $style['input']];
            } elseif (isset($groups[$handle]) && in_array($style['input'], $groups[$handle]->supportedInputs(), true)) {
                // Shared groups are what a block without any profile shows.
                $groups[$handle] = $groups[$handle]->withOverrides(StyleChoices::style(StyleChoices::value($style)));
            }
        }

        return new self(
            $groups,
            $profiles,
            array_map('strval', $config['fieldMap'] ?? []),
            self::_presets($config['presets'] ?? []),
            $configInputs,
            $notes,
            array_map(static fn(array $handles) => array_map('strval', array_keys($handles)), $styleUse),
            $baseOptions,
        );
    }

    /**
     * Returns the groups of the first matching profile.
     *
     * Candidates are tried most specific first (e.g. `pageBuilder:blockTeam`,
     * then `blockTeam`), then the `*` profile, then every shared group.
     *
     * @param string|string[]|null $profile One profile name or a list of candidates
     * @return array<string,Group>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function groupsFor(string|array|null $profile): array
    {
        foreach ((array)$profile as $candidate) {
            if (isset($this->profiles[$candidate])) {
                return $this->profiles[$candidate];
            }
        }

        return $this->profiles[self::FALLBACK_PROFILE] ?? $this->groups;
    }

    /**
     * Presets a block offers: those of its first matching profile, then the `*` ones.
     *
     * A preset keeps only the choices the block has (a group it shows and a key
     * that group knows, aliases followed); a preset left with none is dropped,
     * so one `*` preset can serve blocks with different groups.
     *
     * @param string|string[]|null $profile One profile name or a list of candidates
     * @param array<string,Group> $groups The block's groups
     * @return array<int,array{label:string,values:array<string,string>}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function presetsFor(string|array|null $profile, array $groups): array
    {
        $sets = [];

        foreach ((array)$profile as $candidate) {
            if (isset($this->presets[$candidate])) {
                $sets[] = $this->presets[$candidate];
                break;
            }
        }

        $sets[] = $this->presets[self::FALLBACK_PROFILE] ?? [];
        $merged = [];

        // The block's own preset wins over a `*` preset with the same label.
        foreach ($sets as $set) {
            $merged += $set;
        }

        $result = [];

        foreach ($merged as $label => $values) {
            $applicable = [];

            foreach ($values as $handle => $key) {
                $resolved = isset($groups[$handle]) ? $groups[$handle]->resolveKey($key) : null;

                if ($resolved !== null) {
                    $applicable[$handle] = $resolved;
                }
            }

            if ($applicable !== []) {
                $result[] = ['label' => (string)$label, 'values' => $applicable];
            }
        }

        return $result;
    }

    /**
     * Preset choices no block can use: a group no profile shows, or a key none
     * of that group's definitions knows. Used to reject typos when saving.
     *
     * @return string[] One message per bad choice
     *
     * @author WMD
     * @since 1.0.0
     */
    public function invalidPresetChoices(): array
    {
        $definitions = [];

        foreach ([$this->groups, ...array_values($this->profiles)] as $groups) {
            foreach ($groups as $handle => $group) {
                $definitions[$handle][] = $group;
            }
        }

        $messages = [];

        foreach ($this->presets as $profile => $presets) {
            foreach ($presets as $label => $values) {
                foreach ($values as $handle => $key) {
                    if (!isset($definitions[$handle])) {
                        $messages[] = "Preset \"$label\" ($profile) sets \"$handle\", which is not a design group.";
                        continue;
                    }

                    $known = array_filter($definitions[$handle], static fn(Group $group) => $group->resolveKey($key) !== null);

                    if ($known === []) {
                        $messages[] = "Preset \"$label\" ($profile) sets \"$handle\" to \"$key\", which is not one of its options.";
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Builds a value for a profile from stored or submitted keys.
     *
     * @param array<string,mixed> $keys Group handle => option key
     * @param string|string[]|null $profile One profile name or a list of candidates
     * @return DesignValue
     *
     * @author WMD
     * @since 1.0.0
     */
    public function value(array $keys, string|array|null $profile): DesignValue
    {
        return new DesignValue($keys, $this->groupsFor($profile), $this->groups);
    }

    /**
     * Builds a value from old option fields or a mock map, for blocks that
     * have no Design field (yet).
     *
     * Old fields map to groups the same way the migrate command maps them:
     * `fieldMap`, then the same handle, then `{type}Variant` to `variant`.
     * Empty values stay unset, so the template's own fallback applies, and
     * values outside a group's options pass through unchanged (lenient), so
     * a template sees exactly what it saw before.
     *
     * @param array<string,mixed> $values Old field handle (or map key) => value
     * @param string|string[]|null $profile One profile name or a list of candidates
     * @param string $typeHandle Handle the `{type}Variant` field is named after
     * @return DesignValue
     *
     * @author WMD
     * @since 1.0.0
     */
    public function legacyValue(array $values, string|array|null $profile, string $typeHandle): DesignValue
    {
        $groups = $this->groupsFor($profile);

        // Map against every known group, not only the profile's: a template may
        // read a group its block's profile does not list (mock data, partials).
        $plan = MigrationPlan::build(array_keys($values), $groups + $this->groups, $typeHandle, $this->fieldMap, false);
        $result = $plan->apply($values, []);

        return new DesignValue($result['unknown'] + $result['keys'], $groups, $this->groups, true);
    }

    /**
     * Old field handles a legacy read needs for a profile, so callers can
     * load only those fields instead of every field of the element.
     *
     * @param string[] $available Field handles the element has
     * @param string|string[]|null $profile
     * @param string $typeHandle
     * @return string[]
     *
     * @author WMD
     * @since 1.0.0
     */
    public function legacyHandles(array $available, string|array|null $profile, string $typeHandle): array
    {
        return array_keys(MigrationPlan::build($available, $this->groupsFor($profile), $typeHandle, $this->fieldMap, false)->fields);
    }

    /**
     * Profile names, for the field settings dropdown.
     *
     * @return string[]
     *
     * @author WMD
     * @since 1.0.0
     */
    public function profileNames(): array
    {
        return array_map('strval', array_keys($this->profiles));
    }

    // Private Methods
    // =========================================================================

    /**
     * Checks the shape of the `presets` config: profile => label => group => key.
     *
     * Keys are checked per block when the panel is built, because a `*` preset
     * may name groups only some blocks have.
     *
     * @param array<mixed> $config
     * @return array<string,array<string,array<string,string>>>
     * @throws InvalidArgumentException on a malformed preset
     */
    private static function _presets(array $config): array
    {
        $presets = [];

        foreach ($config as $profile => $list) {
            foreach ((array)$list as $label => $values) {
                if (!is_string($label) || $label === '' || !is_array($values) || $values === []) {
                    throw new InvalidArgumentException("Design preset \"$label\" for \"$profile\" needs a label and a list of group => key choices.");
                }

                $presets[(string)$profile][$label] = array_map('strval', $values);
            }
        }

        return $presets;
    }

    /**
     * Applies labels, icons and the section set on the settings page, remembering the
     * labels and icons they replace.
     *
     * @param Group $group
     * @param array<string,array<string,array{label?:string,icon?:string}>> $meta
     * @param array<string,array<string,array{label:string,icon:?string}>> $baseOptions
     * @param array<string,string> $sections Group handle => heading in the panel
     * @return Group
     */
    private static function _optionMeta(Group $group, array $meta, array &$baseOptions, array $sections = []): Group
    {
        foreach ($group->options as $key => $option) {
            $baseOptions[$group->handle][(string)$key] ??= ['label' => $option['label'], 'icon' => $option['icon']];
        }

        $group = isset($meta[$group->handle]) ? $group->withOptionMeta($meta[$group->handle]) : $group;

        // The heading the option is listed under, from the settings page (wins over the config file).
        return isset($sections[$group->handle]) ? $group->withOverrides(['section' => $sections[$group->handle]]) : $group;
    }

    /**
     * Applies a site-wide style swap (e.g. icons → dropdown) where the group can take it.
     *
     * @param Group $group
     * @param array<string,string> $styles Choice value => choice value
     * @param string $profile For notes; '' for shared groups (not noted, profiles cover them)
     * @param list<array<string,string>> $notes
     * @return Group
     */
    private static function _swapStyle(Group $group, array $styles, string $profile, array &$notes): Group
    {
        $from = StyleChoices::value(['input' => $group->input, 'iconsOnly' => $group->iconsOnly]);
        $to = $styles[$from] ?? null;

        if ($to === null) {
            return $group;
        }

        if (!in_array($to, StyleChoices::values($group), true)) {
            if ($profile !== '') {
                $notes[] = ['kind' => 'unsupported', 'scope' => 'styles', 'profile' => $profile, 'group' => $group->handle, 'input' => $to, 'from' => $from];
            }
            return $group;
        }

        return $group->withOverrides(StyleChoices::style($to));
    }

    /**
     * Whether the config file picks an input style for this group on this profile.
     *
     * @param array<string,mixed> $config
     * @param string $profile
     * @param string $handle
     * @return bool
     */
    private static function _configSetsInput(array $config, string $profile, string $handle): bool
    {
        $definition = $config['profiles'][$profile][$handle] ?? null;

        return isset($config['groups'][$handle]['input'])
            || isset($config['groupOverrides'][$handle]['input'])
            || (is_array($definition) && isset($definition['input']))
            || isset($config['profileOverrides'][$profile][$handle]['input']);
    }

    /**
     * @param string $handle
     * @param array<string,mixed> $config
     * @param callable(string):array<string,mixed> $loadTokens
     * @return Group
     * @throws InvalidArgumentException on a reserved handle or invalid group
     */
    private static function _group(string $handle, array $config, callable $loadTokens): Group
    {
        if (in_array($handle, self::RESERVED_HANDLES, true)) {
            throw new InvalidArgumentException("\"$handle\" is reserved and cannot be a design group handle.");
        }

        $rawOptions = isset($config['tokens'])
            ? $loadTokens((string)$config['tokens'])
            : ($config['options'] ?? []);

        return Group::fromConfig($handle, $config, $rawOptions);
    }
}
