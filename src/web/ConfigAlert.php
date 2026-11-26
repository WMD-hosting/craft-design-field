<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web;

use CraftCms\Cms\Support\Html;
use wmd\designfield\helpers\Translations;
use wmd\designfield\http\SettingsScreen;
use wmd\designfield\services\Groups;

/**
 * The alert every control-panel page shows while the plugin reads no usable
 * configuration. Pages then render without design classes and elements show the
 * stored value instead of their options, and nothing else would say why.
 *
 * @author WMD
 * @since 2.0.0
 */
class ConfigAlert
{
    public function __construct(
        private readonly Groups $groups,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * The alert for the signed-in user, as HTML; null when there is nothing to say.
     *
     * An admin reads the reason and gets a link to the settings page. Anyone else is
     * told the options are away and whom to ask: the reason names the site's config.
     *
     * @param bool $admin Whether the user is an admin
     * @return ?string
     *
     * @author WMD
     * @since 2.0.0
     */
    public function html(bool $admin): ?string
    {
        $failure = $this->groups->failure();

        if ($failure !== null) {
            return $admin
                ? sprintf(
                    'Design Field: the configuration does not build, so pages render without design classes and elements do not show their Design options. %s <a class="go" href="%s">Review</a>',
                    Html::encode($failure),
                    Html::encode(SettingsScreen::url()),
                )
                : Html::encode(Translations::own('Design Field: the Design options are not available until an administrator fixes the plugin’s configuration.'));
        }

        // The file a Craft 5 site had, and none where Craft 6 reads it: nothing is configured.
        if ($admin && $this->groups->hasCraft5File() && !$this->groups->hasConfigFile()) {
            return sprintf(
                'Design Field: <code>config/%s</code> is not read on Craft 6, so no option is configured. Move it to <code>config/%s</code>.',
                Groups::CRAFT5_CONFIG_FILE,
                Groups::CONFIG_FILE,
            );
        }

        return null;
    }
}
