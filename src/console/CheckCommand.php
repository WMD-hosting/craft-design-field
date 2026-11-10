<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\console;

use CraftCms\Cms\Support\Json;
use Illuminate\Console\Command;
use Override;
use wmd\designfield\services\Health;

/**
 * Checks the configuration and the templates against each other and this
 * install. Exits with 1 when there is a warning or an error, so CI can stop a deploy.
 *
 * @author WMD
 * @since 2.0.0
 */
class CheckCommand extends Command
{
    // Protected Properties
    // =========================================================================

    #[Override]
    protected $signature = 'design-field:check {--json : Print the findings as JSON}';

    #[Override]
    protected $description = 'Runs the Design Field configuration check and template check.';

    #[Override]
    protected $aliases = ['design-field/check'];

    // Public Methods
    // =========================================================================

    /**
     * @param Health $health
     * @return int
     */
    public function handle(Health $health): int
    {
        $findings = array_merge($health->check(), $health->templates());
        $failing = array_filter($findings, static fn(array $finding) => $finding['level'] !== Health::INFO);

        if ($this->option('json')) {
            $this->line(Json::encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } elseif ($findings === []) {
            $this->info('No problems found.');
        } else {
            foreach ($findings as $finding) {
                $style = match ($finding['level']) {
                    Health::ERROR => 'fg=red',
                    Health::WARNING => 'fg=yellow',
                    default => 'fg=gray',
                };
                $this->line(sprintf('<%s>%s</>  %s', $style, strtoupper($finding['level']), $finding['message']));
            }
            $this->newLine();
            $this->line(sprintf('%d findings, %d to fix', count($findings), count($failing)));
        }

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }
}
