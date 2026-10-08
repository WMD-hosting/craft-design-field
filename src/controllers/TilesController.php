<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use wmd\designfield\Plugin;
use wmd\designfield\services\Groups;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Saves a layout picture captured in the browser ("Make tile pictures" on the settings
 * page) to `tileImages` under the web root. The pictures are site files that belong in the
 * repository, so admin changes must be allowed.
 *
 * @author WMD
 * @since 1.0.0
 */
class TilesController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * Tile width in pixels, as the showcase script shoots them.
     */
    public const WIDTH = 480;

    /**
     * Largest accepted picture, in bytes.
     */
    public const MAX_BYTES = 2_000_000;

    // Public Methods
    // =========================================================================

    /**
     * @return Response
     * @throws BadRequestHttpException for an unknown block or layout, or a picture that is not a 480 px JPEG
     * @throws ForbiddenHttpException for a user who is not an admin, or when admin changes are off
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(true);

        $type = (string)$this->request->getRequiredBodyParam('type');
        $key = (string)$this->request->getRequiredBodyParam('key');
        $variant = Plugin::getInstance()->getGroups()->getRegistry()->profiles[$type]['variant'] ?? null;
        $path = Groups::tilePath($type, $key);

        if ($variant === null || !isset($variant->options[$key]) || $path === null) {
            throw new BadRequestHttpException(Craft::t('design-field', 'Unknown layout {type} / {key}.', ['type' => $type, 'key' => $key]));
        }

        $data = (string)$this->request->getRequiredBodyParam('image');
        $prefix = 'data:image/jpeg;base64,';
        $jpeg = str_starts_with($data, $prefix) ? base64_decode(substr($data, strlen($prefix)), true) : false;
        $size = is_string($jpeg) && strlen($jpeg) <= self::MAX_BYTES && str_starts_with($jpeg, "\xFF\xD8\xFF") ? getimagesizefromstring($jpeg) : false;

        if ($size === false || $size[0] !== self::WIDTH || $size[2] !== IMAGETYPE_JPEG) {
            throw new BadRequestHttpException(Craft::t('design-field', 'The picture must be a 480 px wide JPEG under 2 MB.'));
        }

        $file = Craft::getAlias('@webroot') . $path;
        FileHelper::createDirectory(dirname($file));
        FileHelper::writeToFile($file, $jpeg);

        return $this->asJson(['url' => Plugin::getInstance()->getGroups()->tileImage($type, $key)]);
    }
}
