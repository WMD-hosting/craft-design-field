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
use wmd\designfield\Plugin;
use wmd\designfield\services\Health;
use yii\console\ExitCode;

/**
 * Checks the configuration and the templates against each other: options no template
 * reads, template reads of options a block lacks, layout options without a template
 * file. Exits with 1 when there is a warning or an error, so CI can stop a deploy.
 *
 * @author WMD
 * @since 1.0.0
 */
class CheckController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Print the findings as JSON.
     */
    public bool $json = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['json']);
    }

    /**
     * Runs the configuration check and the template check.
     *
     * @return int
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionIndex(): int
    {
        $health = Plugin::getInstance()->getHealth();
        $findings = array_merge($health->check(), $health->templates());
        $failing = array_filter($findings, static fn(array $finding) => $finding['level'] !== Health::INFO);

        if ($this->json) {
            $this->stdout(Json::encode($findings, JSON_PRETTY_PRINT) . "\n");
        } elseif ($findings === []) {
            $this->stdout("No problems found.\n", Console::FG_GREEN);
        } else {
            foreach ($findings as $finding) {
                $color = match ($finding['level']) {
                    Health::ERROR => Console::FG_RED,
                    Health::WARNING => Console::FG_YELLOW,
                    default => Console::FG_GREY,
                };
                $this->stdout(strtoupper($finding['level']) . '  ', $color);
                $this->stdout($finding['message'] . "\n");
            }
            $this->stdout(sprintf("\n%d findings, %d to fix\n", count($findings), count($failing)));
        }

        return $failing === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
