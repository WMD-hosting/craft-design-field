<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web\assets\starter;

use craft\web\AssetBundle;

/**
 * The starter options' stylesheet (plain-CSS classes and entrances), for the site's front end:
 * `{% do craft.designField.starterCss() %}`.
 *
 * @author WMD
 * @since 1.0.0
 */
class StarterAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = __DIR__ . '/dist';

    /**
     * @inheritdoc
     */
    public $css = ['design-field.css'];
}
