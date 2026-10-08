<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web\assets\tiles;

use craft\web\AssetBundle;

/**
 * modern-screenshot 4.4.39 (MIT, see dist/modern-screenshot.LICENSE), for "Make tile pictures"
 * on the settings page: it draws a layout's preview to a JPEG in the browser, so no headless
 * browser is needed on the server.
 *
 * @author WMD
 * @since 1.0.0
 */
class TilesAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = __DIR__ . '/dist';

    /**
     * @inheritdoc
     */
    public $js = ['modern-screenshot.js'];
}
