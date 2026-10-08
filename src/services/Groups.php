<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\fields\Matrix;
use craft\helpers\Json;
use InvalidArgumentException;
use wmd\designfield\helpers\Registry;
use wmd\designfield\Plugin;
use yii\base\Component;

/**
 * Builds the registry from the plugin settings and reads the token files they point to.
 *
 * Token files use the Design Tokens plugin format and live in
 * `config/designtokens/{name}.json`, a folder Tailwind can scan.
 *
 * @author WMD
 * @since 1.0.0
 */
class Groups extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * Name of the config file, without extension.
     */
    public const CONFIG_FILE = 'design-field';

    /**
     * Folder holding token files, relative to the config path.
     */
    public const TOKENS_DIR = 'designtokens';

    // Private Properties
    // =========================================================================

    /**
     * @var ?Registry Memoized for the request
     */
    private ?Registry $_registry = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the registry built from the config file.
     *
     * @return Registry
     * @throws InvalidArgumentException if the config or a token file is invalid
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getRegistry(): Registry
    {
        if ($this->_registry !== null) {
            return $this->_registry;
        }

        // Plugin settings already carry `config/design-field.php` merged over the
        // control-panel tables (Craft merges a plugin's config file into its settings).
        $config = Plugin::getInstance()->getSettings()->toConfig();
        $config['tileImage'] = [$this, 'tileImage'];

        return $this->_registry = Registry::fromConfig($config, [$this, 'loadTokens']);
    }

    /**
     * A layout's picture under the web root (`tileImages`), with its file time so a redone
     * picture shows at once; null when there is no file.
     *
     * @param string $type Entry type handle
     * @param string $key Layout key
     * @return ?string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function tileImage(string $type, string $key): ?string
    {
        $path = self::tilePath($type, $key);
        if ($path === null) {
            return null;
        }
        $file = Craft::getAlias('@webroot') . $path;

        return is_file($file) ? $path . '?v=' . filemtime($file) : null;
    }

    /**
     * The web path of a layout's picture (`tileImages` filled in), null when tiles are off
     * or the type or key is not a plain handle.
     *
     * @param string $type
     * @param string $key
     * @return ?string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function tilePath(string $type, string $key): ?string
    {
        $pattern = trim(Plugin::getInstance()->getSettings()->tileImages);
        // Handles only: the path must never leave the tiles folder.
        if ($pattern === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $type) || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return null;
        }

        return '/' . ltrim(strtr($pattern, ['{type}' => $type, '{key}' => $key]), '/');
    }

    /**
     * Dropdown options for the profile columns of the settings tables.
     *
     * Every name a profile can match: `*`, entry types, `field:type` for each
     * Matrix field, Commerce product types and category groups. Values already
     * saved but no longer matching anything stay selectable, marked as unknown.
     *
     * @param string[] $current Values already used in the tables
     * @param bool $allowEmpty Add an empty first option (the groups table: shared)
     * @return array<int,array<string,string>>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function profileOptions(array $current, bool $allowEmpty): array
    {
        $options = $allowEmpty ? [['label' => Craft::t('design-field', 'Shared'), 'value' => '']] : [];
        $known = [];

        $add = static function(string $heading, array $choices) use (&$options, &$known) {
            if ($choices === []) {
                return;
            }

            $options[] = ['optgroup' => $heading];

            foreach ($choices as $value => $label) {
                $options[] = ['label' => $label, 'value' => (string)$value];
                $known[(string)$value] = true;
            }
        };

        $add(Craft::t('design-field', 'Fallback'), [Registry::FALLBACK_PROFILE => Craft::t('design-field', '* (everything else)')]);

        $entryTypes = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            $entryTypes[$type->handle] = "$type->name ($type->handle)";
        }
        $add(Craft::t('design-field', 'Entry types'), $entryTypes);

        $nested = [];
        foreach (Craft::$app->getFields()->getFieldsByType(Matrix::class) as $field) {
            if (!$field instanceof Matrix) {
                continue;
            }

            foreach ($field->getEntryTypes() as $type) {
                $nested["$field->handle:$type->handle"] = "$field->name: $type->name ($field->handle:$type->handle)";
            }
        }
        $add(Craft::t('design-field', 'Entry type in one Matrix field'), $nested);

        if (class_exists(Commerce::class) && Commerce::getInstance() !== null) {
            $productTypes = [];
            foreach (Commerce::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
                $productTypes[$type->handle] = "$type->name ($type->handle)";
            }
            $add(Craft::t('design-field', 'Product types'), $productTypes);
        }

        $categoryGroups = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $categoryGroups[$group->handle] = "$group->name ($group->handle)";
        }
        $add(Craft::t('design-field', 'Category groups'), $categoryGroups);

        $unknown = [];
        foreach (array_unique(array_filter($current)) as $value) {
            if (!isset($known[$value])) {
                $unknown[$value] = Craft::t('design-field', '{value} (not found)', ['value' => $value]);
            }
        }
        $add(Craft::t('design-field', 'Unknown'), $unknown);

        return $options;
    }

    /**
     * Dropdown options for the token file column: every `config/designtokens/*.json`.
     *
     * @param string[] $current Values already used in the table
     * @return array<int,array<string,string>>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function tokenFileOptions(array $current): array
    {
        $options = [['label' => Craft::t('design-field', 'None (options table)'), 'value' => '']];
        $dir = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . self::TOKENS_DIR;
        $names = array_map(static fn(string $path) => basename($path, '.json'), glob("$dir/*.json") ?: []);

        foreach (array_unique(array_merge($names, array_filter($current))) as $name) {
            $options[] = ['label' => in_array($name, $names, true) ? "$name.json" : Craft::t('design-field', '{value} (not found)', ['value' => "$name.json"]), 'value' => $name];
        }

        return $options;
    }

    /**
     * Dropdown options for the option table's group column, from the saved group rows.
     *
     * @param array<int|string,array<string,mixed>> $groupRows
     * @param string[] $current Values already used in the table
     * @return array<int,array<string,string>>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function groupRefOptions(array $groupRows, array $current): array
    {
        $options = [['label' => '', 'value' => '']];
        $known = [];

        foreach ($groupRows as $row) {
            $handle = trim((string)($row['handle'] ?? ''));

            if ($handle === '' || trim((string)($row['tokens'] ?? '')) !== '') {
                continue;
            }

            $profile = trim((string)($row['profile'] ?? ''));
            $ref = $profile !== '' ? "$profile/$handle" : $handle;
            $known[$ref] = true;
            $options[] = ['label' => $ref, 'value' => $ref];
        }

        foreach (array_unique(array_filter($current)) as $value) {
            if (!isset($known[$value])) {
                $options[] = ['label' => Craft::t('design-field', '{value} (not found)', ['value' => $value]), 'value' => $value];
            }
        }

        return $options;
    }

    /**
     * Reads one token file.
     *
     * @param string $name File name without `.json`
     * @return array<string,mixed>
     * @throws InvalidArgumentException if the file is missing or not a JSON object
     *
     * @author WMD
     * @since 1.0.0
     */
    public function loadTokens(string $name): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid token file name \"$name\".");
        }

        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . self::TOKENS_DIR . DIRECTORY_SEPARATOR . $name . '.json';

        if (!is_file($path)) {
            throw new InvalidArgumentException("Token file not found: $path");
        }

        $tokens = Json::decode((string)file_get_contents($path));

        if (!is_array($tokens)) {
            throw new InvalidArgumentException("Token file is not a JSON object: $path");
        }

        return $tokens;
    }
}
