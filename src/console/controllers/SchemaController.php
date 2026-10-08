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
use craft\helpers\Json;
use wmd\designfield\helpers\Schema;
use wmd\designfield\Plugin;
use yii\console\ExitCode;

/**
 * Prints the option model for agents, MCP tools and CI: every block's options with
 * their handles, looks, defaults and keys. JSON by default; `--markdown` for a readable
 * reference; `--agents=AGENTS.md` writes that reference into the file between markers.
 *
 * @author WMD
 * @since 1.0.0
 */
class SchemaController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Print a markdown reference instead of JSON.
     */
    public bool $markdown = false;

    /**
     * @var string|null Write the markdown reference into this file (relative to the project root, or absolute),
     *                  between `<!-- design-field:start -->` and `<!-- design-field:end -->`.
     */
    public ?string $agents = null;

    /**
     * @var bool With --agents: change nothing, exit 1 when the file is out of date (for CI).
     */
    public bool $check = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['markdown', 'agents', 'check']);
    }

    /**
     * Prints or writes the option model.
     *
     * @return int
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionIndex(): int
    {
        $labels = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            $labels[$type->handle] = $type->name;
        }
        $schema = Schema::build(Plugin::getInstance()->getGroups()->getRegistry(), $labels);

        if ($this->agents === null) {
            $this->stdout(($this->markdown ? Schema::markdown($schema) : Json::encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
            return ExitCode::OK;
        }

        $path = str_starts_with($this->agents, '/') ? $this->agents : Craft::getAlias('@root') . '/' . $this->agents;
        $current = is_file($path) ? (string)file_get_contents($path) : '';
        $next = Schema::inject($current, Schema::markdown($schema));

        if ($this->check) {
            if ($next === $current) {
                $this->stdout("$this->agents is up to date.\n", Console::FG_GREEN);
                return ExitCode::OK;
            }
            $this->stderr("$this->agents is out of date: run craft design-field/schema --agents=$this->agents\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($next !== $current) {
            file_put_contents($path, $next);
        }
        $this->stdout(($next === $current ? 'No change: ' : 'Wrote ') . "$this->agents\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
