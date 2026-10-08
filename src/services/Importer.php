<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use Craft;
use craft\base\FieldInterface;
use craft\fields\BaseOptionsField;
use wmd\designfield\helpers\FieldShapes;
use wmd\designfield\models\Group;
use wmd\designfield\Plugin;
use yii\base\Component;

/**
 * Builds groups and profiles from the option fields a site already has, so a
 * whole install can adopt the Design field in one step.
 *
 * Every Button Group, Dropdown, Radio Buttons, Lightswitch, Button Box and
 * Color palette field becomes a group (helpers/FieldShapes); a `{type}Variant`
 * field becomes that type's own `variant` group; Design Tokens fields point at
 * their token file. Groups get an `auto` option so each template keeps its own
 * fallback (not on/off chips, which need exactly two).
 *
 * @author WMD
 * @since 1.0.0
 */
class Importer extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * Class of the Design Tokens plugin's field, read by name so the plugin is optional.
     */
    public const DESIGN_TOKENS_FIELD = FieldShapes::DESIGN_TOKENS;

    // Public Methods
    // =========================================================================

    /**
     * Builds the config array.
     *
     * @param string[] $exclude Field handles that stay normal fields (behaviour, not design)
     * @param array<string,string> $rename Field handle => group handle, for handles that clash
     * @param string[] $types Only these entry type handles; empty means all
     * @param ?string $templates Layout template pattern under the templates folder, e.g. `_blocks/{type}/{key}.twig`;
     *     groups read by only some layouts get a `variants` condition
     * @param ?string $images Layout thumbnail URL pattern under the web root, e.g. `/design-field/{type}/{key}.png`;
     *     layout groups with thumbnails become picture tiles
     * @return array{groups:array<string,array<string,mixed>>,profiles:array<string,array<int|string,mixed>>,fieldMap:array<string,string>,skipped:array<string,string>}
     *
     * @author WMD
     * @since 1.0.0
     */
    public function fromFields(array $exclude = [], array $rename = [], array $types = [], ?string $templates = null, ?string $images = null): array
    {
        $registry = Plugin::getInstance()->getGroups()->getRegistry();
        $fieldMap = $registry->fieldMap + $rename;

        $groups = [];
        $profiles = [];
        $skipped = [];

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            if ($types !== [] && !in_array($type->handle, $types, true)) {
                continue;
            }

            $profile = [];

            foreach ($type->getFieldLayout()->getCustomFields() as $field) {
                $handle = $field->handle;

                if (in_array($handle, $exclude, true)) {
                    continue;
                }

                // The block's own layout list becomes a group local to its profile.
                if ($handle === $type->handle . 'Variant' && $field instanceof BaseOptionsField) {
                    $profile['variant'] = array_filter([
                        'label' => 'Layout',
                        'instructions' => (string)$field->instructions,
                        'options' => $this->_options($field),
                        'default' => $this->_default($field),
                    ]);
                    continue;
                }

                $group = $fieldMap[$handle] ?? $handle;
                $definition = $this->_group($field);

                if ($definition === null) {
                    continue;
                }

                // A configured group whose name is the target of another field is a clash
                // (e.g. a status `tone` field vs a section `tone` group mapped from accentToken).
                $targetOfOther = in_array($group, array_diff_key($fieldMap, [$handle => true]), true) && !isset($fieldMap[$handle]);

                if (isset($registry->groups[$group]) && $targetOfOther) {
                    $skipped[$handle] = "group \"$group\" is already configured for something else; pass --rename=$handle:<group>";
                    continue;
                }

                // Reuse a group configured by hand (same handle, or mapped), but report
                // native options it lacks: saved values outside its options would be lost.
                if (isset($registry->groups[$group])) {
                    $missing = array_diff(array_keys($definition['options'] ?? []), array_keys($registry->groups[$group]->options));

                    if ($missing !== []) {
                        $skipped[$handle] = "uses the configured \"$group\" group, which lacks: " . implode(', ', $missing);
                    }

                    $profile[] = $group;
                    continue;
                }

                $groups[$group] ??= $definition;
                $profile[] = $group;

                if ($group !== $handle) {
                    $fieldMap[$handle] = $group;
                }
            }

            if ($profile !== [] && isset($profile['variant'])) {
                $profile = $this->_withImages($profile, $type->handle, $images);
                $profile = $this->_withConditions($profile, $type->handle, $templates);
            }

            if ($profile !== []) {
                $profiles[$type->handle] = $profile;
            }
        }

        ksort($groups);

        return [
            'groups' => $groups,
            'profiles' => $profiles,
            'fieldMap' => array_diff_key($fieldMap, $registry->fieldMap),
            'skipped' => $skipped,
        ];
    }

    /**
     * Adds conditions and picture tiles to an existing generated config, for
     * sites whose old option fields are already gone.
     *
     * @param array<string,mixed> $config A config array as written by `fromFields()`
     * @param ?string $templates See `fromFields()`
     * @param ?string $images See `fromFields()`
     * @return array<string,mixed>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function enrich(array $config, ?string $templates, ?string $images): array
    {
        foreach ($config['profiles'] ?? [] as $type => $profile) {
            if (!isset($profile['variant']) || str_contains((string)$type, ':')) {
                continue;
            }

            // Drop conditions from an earlier run so a template change can widen them again.
            foreach ($profile as $key => $value) {
                if (is_string($key) && $key !== 'variant' && is_array($value) && array_keys($value) === ['variants']) {
                    $profile = $this->_replaceKey($profile, $key, $key);
                }
            }

            $profile = $this->_withImages($profile, (string)$type, $images);
            $config['profiles'][$type] = $this->_withConditions($profile, (string)$type, $templates);
        }

        return $config;
    }

    /**
     * The field as FieldShapes reads it.
     *
     * @param FieldInterface $field
     * @return array{class:string,parents:string[],name:string,instructions:string,multi:bool,options:?array<int,array<string,mixed>>,settings:array<string,mixed>}
     *
     * @author WMD
     * @since 1.0.0
     */
    public function describe(FieldInterface $field): array
    {
        return [
            'class' => $field::class,
            'parents' => array_values(class_parents($field) ?: []),
            'name' => (string)$field->name,
            'instructions' => (string)$field->instructions,
            'multi' => $field instanceof BaseOptionsField && $field->getIsMultiOptionsField(),
            'options' => $field instanceof BaseOptionsField ? $field->options : null,
            'settings' => $field->getSettings(),
        ];
    }

    /**
     * Renders a config array as a readable PHP file.
     *
     * @param array<string,mixed> $config
     * @param string $header Comment placed at the top
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function toPhp(array $config, string $header): string
    {
        return "<?php\n/**\n * " . str_replace("\n", "\n * ", trim($header)) . "\n */\n\nreturn " . $this->_export($config, 0) . ";\n";
    }

    // Private Methods
    // =========================================================================

    /**
     * Turns the layout group into picture tiles when thumbnails exist.
     *
     * @param array<int|string,mixed> $profile
     * @param string $type
     * @param ?string $pattern
     * @return array<int|string,mixed>
     */
    private function _withImages(array $profile, string $type, ?string $pattern): array
    {
        if ($pattern === null) {
            return $profile;
        }

        $webroot = (string)Craft::getAlias('@webroot');
        $found = false;

        foreach ($profile['variant']['options'] ?? [] as $key => $option) {
            $url = strtr($pattern, ['{type}' => $type, '{key}' => (string)$key]);

            if (is_file($webroot . $url)) {
                $profile['variant']['options'][$key]['image'] = $url;
                $found = true;
            }
        }

        if ($found) {
            $profile['variant']['input'] = Group::INPUT_TILES;
        }

        return $profile;
    }

    /**
     * Adds `variants` conditions to groups that only some layouts read.
     *
     * Reads each layout template and the partials it includes (two levels)
     * for `design.<group>` style reads and the section frame macros. A group
     * no layout reads stays visible: the read may happen somewhere the scan
     * cannot see.
     *
     * @param array<int|string,mixed> $profile
     * @param string $type
     * @param ?string $pattern
     * @return array<int|string,mixed>
     */
    private function _withConditions(array $profile, string $type, ?string $pattern): array
    {
        if ($pattern === null) {
            return $profile;
        }

        $root = Craft::$app->getPath()->getSiteTemplatesPath();
        $variants = array_keys($profile['variant']['options'] ?? []);
        $readBy = [];
        $ownReadBy = [];

        foreach ($variants as $variant) {
            // A layout without its own template renders the default one, as the dispatcher does.
            $file = $root . DIRECTORY_SEPARATOR . strtr($pattern, ['{type}' => $type, '{key}' => (string)$variant]);
            if (!is_file($file)) {
                $file = $root . DIRECTORY_SEPARATOR . strtr($pattern, ['{type}' => $type, '{key}' => '_default']);
            }
            if (!is_file($file)) {
                return $profile;
            }

            foreach ($this->_groupsRead($file, $root, 2) as $group) {
                $readBy[$group][] = $variant;
            }
            foreach ($this->_groupsRead($file, $root, 2, true) as $group) {
                $ownReadBy[$group][] = $variant;
            }
        }

        // Groups the templates read but the profile lacks (an option the editor
        // could never set) are added, limited to the layouts that read them.
        $registry = Plugin::getInstance()->getGroups()->getRegistry();
        $listed = array_map(static fn($key, $value) => is_int($key) ? $value : $key, array_keys($profile), $profile);
        foreach ($ownReadBy as $group => $users) {
            if ($group !== 'variant' && !in_array($group, $listed, true) && isset($registry->groups[$group])) {
                $profile[] = $group;
            }
        }

        $result = [];

        foreach ($profile as $key => $value) {
            $group = is_int($key) ? $value : $key;
            $users = array_values(array_unique($readBy[$group] ?? []));

            if ($group === 'variant' || $users === [] || count($users) === count($variants)) {
                $result[$key] = $value;
                continue;
            }

            $result[$group] = (is_array($value) ? $value : []) + ['variants' => $users];
        }

        return $result;
    }

    /**
     * Group handles a template reads, following its includes.
     *
     * @param string $file
     * @param string $root
     * @param int $depth
     * @param bool $ownOnly Only the block's own `design.<group>` reads, not child elements'
     * @return string[]
     */
    private function _groupsRead(string $file, string $root, int $depth, bool $ownOnly = false): array
    {
        $source = (string)file_get_contents($file);
        preg_match_all($ownOnly ? '/\bdesign\.(\w+)|designField\.of\(block\b[^)]*\)\.(\w+)/' : '/\b\w*[dD]esign\.(\w+)|designField\.of\([^)]*\)\.(\w+)/', $source, $matches);
        $groups = array_filter(array_merge($matches[1], $matches[2]));

        if (str_contains($source, 'frame.sectionClass')) {
            array_push($groups, 'tone', 'spacing');
        }
        if (str_contains($source, 'frame.containerClass')) {
            $groups[] = 'container';
        }

        if ($depth > 0) {
            preg_match_all("/\{%-?\s*(?:include|embed|import)\s+'([^']+)'/", $source, $includes);
            foreach ($includes[1] as $include) {
                if (str_starts_with($include, '_blocks/')) {
                    continue;
                }
                $path = $root . DIRECTORY_SEPARATOR . (str_ends_with($include, '.twig') ? $include : "$include.twig");
                if (is_file($path)) {
                    $groups = array_merge($groups, $this->_groupsRead($path, $root, $depth - 1, $ownOnly));
                }
            }
        }

        return array_values(array_unique(array_diff($groups, ['keyOr', 'isAuto', 'key', 'get', 'has', 'classes', 'keys'])));
    }

    /**
     * @param FieldInterface $field
     * @return ?array<string,mixed> Null when the field is not an option field
     */
    private function _group(FieldInterface $field): ?array
    {
        return FieldShapes::group($this->describe($field));
    }

    /**
     * @param BaseOptionsField $field
     * @return array<string,array<string,string>>
     */
    private function _options(BaseOptionsField $field): array
    {
        $options = [];

        foreach ($field->options as $option) {
            $value = (string)($option['value'] ?? '');

            if (isset($option['optgroup']) || $value === '' || $value === Group::AUTO) {
                continue;
            }

            $options[$value] = array_filter([
                'label' => (string)($option['label'] ?? $value),
                'icon' => (string)($option['icon'] ?? ''),
            ]);
        }

        return $options;
    }

    /**
     * @param BaseOptionsField $field
     * @return string
     */
    private function _default(BaseOptionsField $field): string
    {
        foreach ($field->options as $option) {
            if (!empty($option['default']) && (string)($option['value'] ?? '') !== '') {
                return (string)$option['value'];
            }
        }

        return '';
    }

    /**
     * Replaces a keyed profile entry with a plain list entry, keeping its position.
     *
     * @param array<int|string,mixed> $profile
     * @param string $key
     * @param string $group
     * @return array<int|string,mixed>
     */
    private function _replaceKey(array $profile, string $key, string $group): array
    {
        $result = [];
        foreach ($profile as $k => $v) {
            if ($k === $key) {
                $result[] = $group;
                continue;
            }
            is_int($k) ? $result[] = $v : $result[$k] = $v;
        }

        return $result;
    }

    /**
     * Short-array PHP export with stable indentation.
     *
     * @param mixed $value
     * @param int $depth
     * @param bool $keyed Always write keys (`aliases`: `'0' => 'off'` is a map, not a list)
     * @return string
     */
    private function _export(mixed $value, int $depth, bool $keyed = false): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $depth + 1);
        $list = !$keyed && array_is_list($value);
        $lines = [];

        foreach ($value as $key => $item) {
            $lines[] = $pad . ($list ? '' : var_export((string)$key, true) . ' => ') . $this->_export($item, $depth + 1, $key === 'aliases') . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }
}
