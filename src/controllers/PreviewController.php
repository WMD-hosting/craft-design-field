<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\controllers;

use craft\helpers\Json;
use craft\web\Controller;
use InvalidArgumentException;
use wmd\designfield\helpers\InputOverrides;
use wmd\designfield\helpers\Registry;
use wmd\designfield\Plugin;
use wmd\designfield\web\SettingsPreview;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The settings page preview: one block's panel with unsaved input styles.
 *
 * @author WMD
 * @since 1.0.0
 */
class PreviewController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Renders a block's panel with the input styles picked so far, nothing saved, plus
     * the scripts its inputs need.
     *
     * @return Response
     * @throws BadRequestHttpException for an unknown profile or a configuration that does not build
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionPanel(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        // Same audience as the settings page; reading only, so admin changes may be off.
        $this->requireAdmin(false);

        $request = $this->request;
        $profile = (string)$request->getRequiredBodyParam('profile');
        $plugin = Plugin::getInstance();
        $config = array_replace($plugin->getSettings()->toConfig(), [
            'inputs' => InputOverrides::clean(Json::decodeIfJson((string)$request->getBodyParam('inputs', ''))),
            // Layout pictures on disk, as the registry the site uses has them.
            'tileImage' => [$plugin->getGroups(), 'tileImage'],
        ]);

        try {
            $registry = Registry::fromConfig($config, [$plugin->getGroups(), 'loadTokens']);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }

        if (!isset($registry->profiles[$profile])) {
            throw new BadRequestHttpException("Unknown profile \"$profile\".");
        }

        // Craft's inputs (button groups, lightswitches) start from scripts the view
        // registers; the page appends them after swapping the panel in.
        $view = $this->getView();
        $payload = SettingsPreview::payload($registry, $profile);

        return $this->asJson($payload + [
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }
}
