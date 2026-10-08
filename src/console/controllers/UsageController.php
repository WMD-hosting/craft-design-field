<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use DateTimeImmutable;
use wmd\designfield\helpers\UsageReport;
use wmd\designfield\Plugin;
use yii\console\ExitCode;

/**
 * Shows which design choices editors actually use, so unused options can go and defaults can follow real use.
 *
 * Per entry type and group: options never changed from the default (drop
 * them from the profile, or hardcode them in the template), groups where
 * every entry picked the same non-default choice (make it the default), and
 * choices nobody uses. Drafts and revisions are not counted.
 *
 *     craft design-field/usage
 *     craft design-field/usage --types=blockTeam,blockCta --all
 *     craft design-field/usage --json
 *
 * @author WMD
 * @since 1.0.0
 */
class UsageController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?string Entry type handles, comma-separated.
     */
    public ?string $types = null;

    /**
     * @var bool Also list groups that are in normal use.
     */
    public bool $all = false;

    /**
     * @var int Skip entry types with fewer entries than this; too few to judge.
     */
    public int $min = 1;

    /**
     * @var int Below this many entries a type's findings are marked as a hint only.
     */
    public int $sample = 5;

    /**
     * @var bool Print the report as JSON.
     */
    public bool $json = false;

    /**
     * @var int Only entries saved in the last this many months (e.g. 12 for a yearly review); 0 for all.
     */
    public int $months = 0;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['types', 'all', 'min', 'sample', 'json', 'months']);
    }

    /**
     * Shows which design choices editors actually use.
     *
     * @return int
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionIndex(): int
    {
        $types = array_values(array_filter(array_map('trim', explode(',', (string)$this->types))));
        $report = array_filter(
            Plugin::getInstance()->getUsage()->report($types, $this->months > 0 ? new DateTimeImmutable("-$this->months months") : null),
            fn(array $type) => $type['entries'] >= $this->min,
        );

        if ($this->json) {
            $this->stdout(Json::encode($report, JSON_PRETTY_PRINT) . "\n");

            return ExitCode::OK;
        }

        $summary = UsageReport::summarize($report, $this->all);

        foreach ($summary['types'] as $type => $data) {
            $hint = $data['entries'] < $this->sample ? ' - few entries, treat as a hint' : '';
            $this->stdout(sprintf("%s (%d %s%s)\n", $type, $data['entries'], $data['entries'] === 1 ? 'entry' : 'entries', $hint), Console::BOLD);

            foreach ($data['findings'] as $finding) {
                $unused = $finding['unused'] !== [] ? ' · never picked: ' . implode(', ', $finding['unused']) : '';
                $this->stdout(sprintf("  %-22s %s%s\n", $finding['label'], $finding['text'], $unused));
            }
        }

        // Site-wide: options no editor changed in any block are the clearest cleanup.
        if ($summary['nowhere'] !== []) {
            $this->stdout("\nNever changed in any block:\n", Console::BOLD);
            foreach ($summary['nowhere'] as $group) {
                $this->stdout(sprintf("  %-22s %d blocks, %d entries\n", $group['label'], $group['types'], $group['entries']));
            }
        }

        $this->stdout(sprintf(
            "\n%d entry types · %d options never changed · %d always the same value\n",
            count($report),
            $summary['neverChanged'],
            $summary['oneValue'],
        ));

        return ExitCode::OK;
    }
}
