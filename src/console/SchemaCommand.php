<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\console;

use CraftCms\Cms\Entry\EntryTypes;
use CraftCms\Cms\Support\Json;
use Illuminate\Console\Command;
use Override;
use wmd\designfield\helpers\Schema;
use wmd\designfield\services\Groups;

/**
 * Prints the option model (groups, options, profiles) for agents and tools,
 * or writes it into a markdown file such as AGENTS.md.
 *
 * @author WMD
 * @since 2.0.0
 */
class SchemaCommand extends Command
{
    // Protected Properties
    // =========================================================================

    #[Override]
    protected $signature = 'design-field:schema
        {--markdown : Print a markdown reference instead of JSON}
        {--agents= : Write the markdown reference into this file (relative to the project root, or absolute), between the design-field markers}
        {--check : With --agents: change nothing, exit 1 when the file is out of date (for CI)}';

    #[Override]
    protected $description = 'Prints or writes the Design Field option model.';

    #[Override]
    protected $aliases = ['design-field/schema'];

    // Public Methods
    // =========================================================================

    /**
     * @param Groups $groups
     * @param EntryTypes $entryTypes
     * @return int
     */
    public function handle(Groups $groups, EntryTypes $entryTypes): int
    {
        $labels = [];
        foreach ($entryTypes->getAllEntryTypes() as $type) {
            $labels[$type->handle] = $type->name;
        }
        $schema = Schema::build($groups->getRegistry(), $labels);
        $agents = $this->option('agents');

        if ($agents === null) {
            $this->line($this->option('markdown')
                ? Schema::markdown($schema)
                : Json::encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $path = str_starts_with($agents, '/') ? $agents : base_path($agents);
        $current = is_file($path) ? (string)file_get_contents($path) : '';
        $next = Schema::inject($current, Schema::markdown($schema));

        if ($this->option('check')) {
            if ($next === $current) {
                $this->info("$agents is up to date.");

                return self::SUCCESS;
            }
            $this->error("$agents is out of date: run design-field:schema --agents=$agents");

            return self::FAILURE;
        }

        if ($next !== $current) {
            file_put_contents($path, $next);
        }
        $this->info(($next === $current ? 'No change: ' : 'Wrote ') . $agents);

        return self::SUCCESS;
    }
}
