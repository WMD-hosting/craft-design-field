<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\console;

use CraftCms\Cms\Support\Json;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Override;
use wmd\designfield\helpers\UsageReport;
use wmd\designfield\services\Usage;

/**
 * Shows which design choices editors actually use, so unused options can go and defaults can follow real use.
 *
 * Per entry type and group: options never changed from the default (drop
 * them from the profile, or hardcode them in the template), groups where
 * every entry picked the same non-default choice (make it the default), and
 * choices nobody uses. Drafts and revisions are not counted.
 *
 * @author WMD
 * @since 2.0.0
 */
class UsageCommand extends Command
{
    // Protected Properties
    // =========================================================================

    #[Override]
    protected $signature = 'design-field:usage
        {--types= : Entry type handles, comma-separated}
        {--all : Also list groups that are in normal use}
        {--min=1 : Skip entry types with fewer entries than this; too few to judge}
        {--sample=5 : Below this many entries a type\'s findings are marked as a hint only}
        {--months=0 : Only entries saved in the last this many months; 0 for all}
        {--json : Print the report as JSON}';

    #[Override]
    protected $description = 'Shows which design choices editors actually use.';

    #[Override]
    protected $aliases = ['design-field/usage'];

    // Public Methods
    // =========================================================================

    /**
     * @param Usage $usage
     * @return int
     */
    public function handle(Usage $usage): int
    {
        $types = array_values(array_filter(array_map('trim', explode(',', (string)$this->option('types')))));
        $months = (int)$this->option('months');
        $min = (int)$this->option('min');
        $report = array_filter(
            $usage->report($types, $months > 0 ? new DateTimeImmutable("-$months months") : null),
            static fn(array $type) => $type['entries'] >= $min,
        );

        if ($this->option('json')) {
            $this->line(Json::encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $summary = UsageReport::summarize($report, (bool)$this->option('all'));

        foreach ($summary['types'] as $type => $data) {
            $hint = $data['entries'] < (int)$this->option('sample') ? ' - few entries, treat as a hint' : '';
            $this->line(sprintf('<options=bold>%s (%d %s%s)</>', $type, $data['entries'], $data['entries'] === 1 ? 'entry' : 'entries', $hint));

            foreach ($data['findings'] as $finding) {
                $unused = $finding['unused'] !== [] ? ' · never picked: ' . implode(', ', $finding['unused']) : '';
                $this->line(sprintf('  %-22s %s%s', $finding['label'], $finding['text'], $unused));
            }
        }

        // Site-wide: options no editor changed in any block are the clearest cleanup.
        if ($summary['nowhere'] !== []) {
            $this->newLine();
            $this->line('<options=bold>Never changed in any block:</>');
            foreach ($summary['nowhere'] as $group) {
                $this->line(sprintf('  %-22s %d blocks, %d entries', $group['label'], $group['types'], $group['entries']));
            }
        }

        $this->newLine();
        $this->line(sprintf('%d entry types · %d options never changed · %d always the same value', count($report), $summary['neverChanged'], $summary['oneValue']));

        return self::SUCCESS;
    }
}
