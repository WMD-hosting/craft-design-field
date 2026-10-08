<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\controllers;

use Craft;
use craft\web\Controller;
use InvalidArgumentException;
use wmd\designfield\helpers\Starter;
use wmd\designfield\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The settings page's first run: fills the empty settings tables with the starter options
 * for the site's CSS framework (helpers/Starter).
 *
 * @author WMD
 * @since 1.0.0
 */
class StarterController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @return Response
     * @throws BadRequestHttpException for an unknown framework, when options already exist, or for a request that is not a JSON POST
     * @throws ForbiddenHttpException for a user who is not an admin, or when admin changes are off
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionApply(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(true);

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // Only on a site with no options yet: never over a config file or filled tables.
        if ($settings->isOverridden() || $settings->groupRows !== [] || $settings->profileRows !== []) {
            throw new BadRequestHttpException(Craft::t('design-field', 'This site has options already.'));
        }

        try {
            $rows = Starter::rows((string)$this->request->getRequiredBodyParam('framework'));
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }

        // Every saved setting goes along: Craft saves only the keys it is given.
        $values = $rows + [
            'presetRows' => $settings->presetRows,
            'inputs' => $settings->inputs,
        ];

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            // Say why: e.g. presets that set options this site no longer has.
            $errors = $plugin->getSettings()->getErrorSummary(false);

            return $this->asFailure(Craft::t('design-field', 'Could not save the starter options.') . ($errors !== [] ? ' ' . $errors[0] : ''));
        }

        return $this->asSuccess(Craft::t('design-field', 'Starter options added. Add a Design field to your blocks to use them.'));
    }
}
