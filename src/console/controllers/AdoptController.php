<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\console\controllers;

use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;
use InvalidArgumentException;
use wmd\designfield\Plugin;
use yii\console\ExitCode;

/**
 * Moves entry types onto the Design field in place: field in, values copied and verified, old fields out.
 *
 * Runs as a dry run unless --apply is given. Old option fields leave the
 * layout only after every copied value is confirmed in the database; their
 * values stay in the content rows, so putting the fields back restores the
 * old setup. Run design-field/import first so every type has a profile.
 *
 *     craft design-field/adopt --types=blockCta --apply
 *     craft design-field/adopt --all --apply
 *
 * @author WMD
 * @since 1.0.0
 */
class AdoptController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?string Entry type handles, comma-separated.
     */
    public ?string $types = null;

    /**
     * @var bool Every entry type that has a profile.
     */
    public bool $all = false;

    /**
     * @var string Handle of the Design field.
     */
    public string $field = 'design';

    /**
     * @var bool Make the changes. Without it the command only reports.
     */
    public bool $apply = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['types', 'all', 'field', 'apply']);
    }

    /**
     * Moves entry types onto the Design field in place.
     *
     * @return int
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $handles = $this->all
            ? array_keys(array_filter(
                $plugin->getGroups()->getRegistry()->profiles,
                static fn($profile, $name) => !str_contains((string)$name, ':') && $name !== '*',
                ARRAY_FILTER_USE_BOTH,
            ))
            : array_values(array_filter(array_map('trim', explode(',', (string)$this->types))));

        if ($handles === []) {
            $this->stderr("Pass --types=<handles> or --all.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        App::maxPowerCaptain();
        $problems = 0;

        foreach ($handles as $handle) {
            try {
                $result = $plugin->getConverter()->adopt($handle, $this->field, !$this->apply);
            } catch (InvalidArgumentException $e) {
                $this->stdout("$handle: skipped, {$e->getMessage()}\n", Console::FG_YELLOW);
                continue;
            }

            $line = "$handle: {$result['status']}";
            if ($result['old'] !== []) {
                $line .= ' (' . implode(', ', $result['old']) . ')';
            }
            if ($result['report'] !== null) {
                $line .= sprintf(', %d entries, %d saved', $result['report']['scanned'], $result['report']['saved']);
            }
            $ok = in_array($result['status'], ['done', 'dry run', 'nothing to move'], true);
            $this->stdout($line . "\n", $ok ? Console::RESET : Console::FG_RED);

            foreach ($result['report']['failed'] ?? [] as $id => $error) {
                $this->stdout("  #$id $error\n", Console::FG_RED);
            }
            if ($result['unverified'] !== []) {
                $this->stdout('  not stored: #' . implode(', #', $result['unverified']) . "\n", Console::FG_RED);
            }
            $problems += $ok ? 0 : 1;
        }

        if (!$this->apply) {
            $this->stdout("Dry run. Run again with --apply to make the changes.\n");
        }

        return $problems === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
