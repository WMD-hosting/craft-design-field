<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\Console;
use InvalidArgumentException;
use wmd\designfield\helpers\MigrationPlan;
use wmd\designfield\Plugin;
use yii\console\ExitCode;

/**
 * Moves old option field values into a Design field, so blocks switch without losing their settings.
 *
 * Runs as a dry run unless --apply is given. Old fields map to the group with
 * the same handle, `{type}Variant` maps to `variant`, and --map adds pairs for
 * fields with other names. Revisions are not touched.
 *
 * In place: add the Design field to the block's layout, run
 *     craft design-field/migrate --type=blockTeam --map=accentToken:tone --apply
 * then remove the old fields from the layout.
 *
 * Switching to a Design version of the block:
 *     craft design-field/migrate --type=blockTeam --to-type=blockTeamDesign --apply
 *
 * @author WMD
 * @since 1.0.0
 */
class MigrateController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?string Entry type handle whose entries hold the old option fields.
     */
    public ?string $type = null;

    /**
     * @var ?string Entry type handle to switch the entries to (it must have the Design field).
     */
    public ?string $toType = null;

    /**
     * @var string Handle of the Design field.
     */
    public string $field = 'design';

    /**
     * @var ?string Extra pairs, old field to group: accentToken:tone,paddingToken:spacing
     */
    public ?string $map = null;

    /**
     * @var bool Replace choices already made in the Design field.
     */
    public bool $overwrite = false;

    /**
     * @var bool Include drafts.
     */
    public bool $drafts = false;

    /**
     * @var ?string Only these entry IDs, comma-separated, to try it on one block first.
     */
    public ?string $entries = null;

    /**
     * @var bool Save the changes. Without it the command only reports.
     */
    public bool $apply = false;

    /**
     * @var bool List every entry, not only the totals.
     */
    public bool $detail = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'type',
            'toType',
            'field',
            'map',
            'overwrite',
            'drafts',
            'entries',
            'apply',
            'detail',
        ]);
    }

    /**
     * Moves old option field values of one entry type into its Design field.
     *
     * @return int
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionIndex(): int
    {
        if ($this->type === null) {
            $this->stderr("Pass --type=<entry type handle>.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        App::maxPowerCaptain();

        try {
            $report = Plugin::getInstance()->getConverter()->migrate(
                typeHandle: $this->type,
                toTypeHandle: $this->toType,
                fieldHandle: $this->field,
                explicitMap: MigrationPlan::parseMap($this->map),
                overwrite: $this->overwrite,
                dryRun: !$this->apply,
                drafts: $this->drafts,
                ids: array_map('intval', array_filter(array_map('trim', explode(',', (string)$this->entries)))),
                onEntry: $this->detail ? function(Entry $entry, array $result) {
                    $this->stdout(sprintf(
                        "  #%d %s  moved: %s%s\n",
                        $entry->id,
                        $entry->title ?? '',
                        $result['moved'] ? json_encode($result['moved']) : '-',
                        $result['unknown'] ? '  unknown: ' . json_encode($result['unknown']) : '',
                    ));
                } : null,
            );
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $this->stdout(($this->apply ? 'Applied' : 'Dry run, nothing saved') . "\n", Console::BOLD);

        foreach ($report['mapping'] as $profile => $fields) {
            $pairs = array_map(static fn($field, $group) => "$field > $group", array_keys($fields), $fields);
            $this->stdout("Mapping ($profile): " . ($pairs ? implode(', ', $pairs) : 'none') . "\n");
        }

        $this->stdout(sprintf("Entries: %d scanned, %d to change, %d saved\n", $report['scanned'], $report['changed'], $report['saved']));

        foreach ($report['moved'] as $group => $count) {
            $this->stdout("  $group: $count moved\n", Console::FG_GREEN);
        }

        foreach ($report['kept'] as $group => $count) {
            $this->stdout("  $group: $count kept (already set; --overwrite replaces)\n", Console::FG_YELLOW);
        }

        foreach ($report['empty'] as $group => $count) {
            $this->stdout("  $group: empty on $count entries, the template's default applies (check the old template rendered empty the same way)\n");
        }

        foreach ($report['unknown'] as $group => $keys) {
            foreach ($keys as $key => $count) {
                $this->stdout("  $group: \"$key\" is not an option, skipped on $count entries (add it or an alias)\n", Console::FG_YELLOW);
            }
        }

        foreach ($report['failed'] as $id => $error) {
            $this->stderr("  #$id failed: $error\n", Console::FG_RED);
        }

        if (!$this->apply && $report['changed'] > 0) {
            $this->stdout("Run again with --apply to save.\n");
        }

        return $report['failed'] === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
