<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use wmd\designfield\Plugin;
use yii\console\ExitCode;

/**
 * Writes groups and profiles for every entry type from the option fields it already has.
 *
 * Every Button Group, Dropdown, Radio Buttons and Design Tokens field becomes
 * a group, each `{type}Variant` field becomes that type's own layout group,
 * and each entry type with options gets a profile. The result goes to
 * config/design-field.generated.php; merge it under your hand-written config:
 *
 *     $generated = require __DIR__ . '/design-field.generated.php';
 *
 * Nothing changes on any entry. Run design-field/migrate afterwards to move data.
 *
 * @author WMD
 * @since 1.0.0
 */
class ImportController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?string Field handles that stay normal fields (behaviour, not design), comma-separated.
     */
    public ?string $exclude = null;

    /**
     * @var ?string Field to group renames for clashing handles: tone:statusTone
     */
    public ?string $rename = null;

    /**
     * @var ?string Only these entry type handles, comma-separated.
     */
    public ?string $types = null;

    /**
     * @var ?string Layout template pattern for "show only what applies" conditions: _blocks/{type}/{key}.twig
     */
    public ?string $templates = null;

    /**
     * @var ?string Layout thumbnail URL pattern under the web root, for picture tiles: /design-field/{type}/{key}.png
     */
    public ?string $images = null;

    /**
     * @var bool Add conditions (--templates) and picture tiles (--images) to the existing generated file instead of reading option fields.
     */
    public bool $enrich = false;

    /**
     * @var bool Write even if the result loses groups or profiles the existing file has.
     */
    public bool $force = false;

    /**
     * @var bool Write the file. Without it the command only reports.
     */
    public bool $write = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['exclude', 'rename', 'types', 'templates', 'images', 'enrich', 'force', 'write']);
    }

    /**
     * Builds groups and profiles from existing option fields.
     *
     * @return int
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionIndex(): int
    {
        $list = static fn(?string $value) => array_values(array_filter(array_map('trim', explode(',', (string)$value))));
        $rename = [];

        foreach ($list($this->rename) as $pair) {
            [$field, $group] = array_pad(explode(':', $pair, 2), 2, '');

            if ($field === '' || $group === '') {
                $this->stderr("Expected field:group in --rename, got \"$pair\".\n", Console::FG_RED);

                return ExitCode::USAGE;
            }

            $rename[$field] = $group;
        }

        $importer = Plugin::getInstance()->getImporter();
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'design-field.generated.php';
        $existing = is_file($path) ? require $path : [];

        // Reuse the choices of the run that wrote the file, unless given again.
        $saved = $existing['importOptions'] ?? [];
        $exclude = $this->exclude !== null ? $list($this->exclude) : ($saved['exclude'] ?? []);
        $rename = $rename !== [] ? $rename : ($saved['rename'] ?? []);

        if ($this->enrich) {
            if ($existing === []) {
                $this->stderr("Nothing to enrich: write config/design-field.generated.php first.\n", Console::FG_RED);

                return ExitCode::USAGE;
            }
            $result = $importer->enrich($existing, $this->templates, $this->images) + ['skipped' => []];
        } else {
            $result = $importer->fromFields($exclude, $rename, $list($this->types), $this->templates, $this->images);
        }

        $result['importOptions'] = ['exclude' => $exclude, 'rename' => $rename];

        // Never let a rerun quietly drop what the file describes, e.g. after the
        // option fields moved to the Design field and there is less left to read.
        $lostGroups = array_diff(array_keys($existing['groups'] ?? []), array_keys($result['groups']));
        $lostProfiles = array_diff(array_keys($existing['profiles'] ?? []), array_keys($result['profiles']));

        if (($lostGroups !== [] || $lostProfiles !== []) && !$this->force) {
            $this->stdout(sprintf(
                "This would drop %d groups and %d profiles the existing file has (%s). Use --enrich to add conditions or tiles to it, or --force to replace it.\n",
                count($lostGroups),
                count($lostProfiles),
                implode(', ', array_slice(array_merge($lostGroups, $lostProfiles), 0, 8)),
            ), Console::FG_YELLOW);

            if ($this->write) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $this->stdout(sprintf("%d groups, %d profiles\n", count($result['groups']), count($result['profiles'])), Console::BOLD);

        foreach ($result['profiles'] as $type => $profile) {
            $names = array_map(
                static fn($key, $value) => (is_int($key) ? $value : $key) . (is_array($value) && isset($value['variants']) ? ' (' . implode('|', $value['variants']) . ')' : ''),
                array_keys($profile),
                $profile,
            );
            $this->stdout("  $type: " . implode(', ', $names) . "\n");
        }

        foreach ($result['skipped'] as $field => $reason) {
            $this->stdout("  skipped $field: $reason\n", Console::FG_YELLOW);
        }

        if (!$this->write) {
            $this->stdout("Dry run. Run again with --write to create config/design-field.generated.php.\n");

            return ExitCode::OK;
        }

        unset($result['skipped']);
        $header = "Generated by `craft design-field/import` from the site's option fields.\nRegenerate instead of editing; put hand-written changes in config/design-field.php.";
        file_put_contents($path, $importer->toPhp($result, $header));
        $this->stdout("Wrote $path\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
