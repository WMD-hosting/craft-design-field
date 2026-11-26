<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield;

use CraftCms\Cms\Cp\Events\CpAlertsResolving;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\Ui\UiControlTypes;
use Illuminate\Support\Facades\Event;
use Override;
use Throwable;
use wmd\designfield\http\SettingsScreen;
use wmd\designfield\console\CheckCommand;
use wmd\designfield\console\SchemaCommand;
use wmd\designfield\console\UsageCommand;
use wmd\designfield\fields\Design;
use wmd\designfield\services\Groups;
use wmd\designfield\variables\DesignFieldVariable;
use wmd\designfield\web\ConfigAlert;
use wmd\designfield\web\controls\BlockPicker;
use wmd\designfield\web\controls\Grid;
use wmd\designfield\web\controls\PresetBlock;
use wmd\designfield\web\controls\Rendered;
use wmd\designfield\web\controls\Steps;

use function CraftCms\Cms\currentUser;

/**
 * Design Field for Craft 6: the Design field type with its panel and its Variant
 * condition rule, the registry service, `craft.designField` and the settings page
 * (http/SettingsScreen).
 *
 * @author WMD
 * @since 1.0.0
 */
class Plugin extends BasePlugin
{
    // Public Properties
    // =========================================================================

    public string $schemaVersion = '2.0.0';

    /**
     * The config file is read by the Groups service, from the project's own `config/`.
     */
    public bool $config = false;

    /**
     * The settings page has no settings model: it opens read only where admin changes are
     * off, and what it saves goes to site files through its own routes (routes/cp.php).
     */
    public bool $hasCpSettings = true;

    public bool $hasReadOnlyCpSettings = true;

    // Protected Properties
    // =========================================================================

    protected array $fieldTypes = [Design::class];

    /**
     * Loaded on control-panel pages: the larger look at a layout tile on hover and keyboard focus.
     */
    protected array $scripts = [__DIR__ . '/web/assets/tiles/dist/tile-preview.js' => 'js/tile-preview.js'];

    /**
     * Loaded on control-panel pages: motion tiles that play their animation on hover, and
     * what a choice shows in its button (drawn icons, colour chips, samples, width bars,
     * position grids).
     */
    protected array $styles = [
        __DIR__ . '/web/assets/tiles/dist/motion-tiles.css' => 'css/motion-tiles.css',
        __DIR__ . '/web/assets/tiles/dist/option-looks.css' => 'css/option-looks.css',
    ];

    /**
     * Loaded on control-panel pages before the panel boots: the Vue controls the panel's
     * own Controls name (`design-field:steps`, `design-field:grid`, `design-field:rendered`,
     * `design-field:presets`, and the settings page's `design-field:preview` and
     * `design-field:block-picker`), built by `npm run build` into `public/build`.
     */
    protected array $vite = ['resources/js/cp.ts'];

    protected array $commands = [SchemaCommand::class, CheckCommand::class, UsageCommand::class];

    // Public Methods
    // =========================================================================

    #[Override]
    public function register(): void
    {
        // Scoped: a long-running worker reads the configuration again for each request or
        // job, so a look saved on the settings page shows without a restart.
        $this->app->scoped(Groups::class);
    }

    #[Override]
    public function getSettingsResponse(): mixed
    {
        return app(SettingsScreen::class)->response(request());
    }

    #[Override]
    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->getSettingsResponse();
    }

    public function boot(UiControlTypes $controlTypes): void
    {
        CraftVariable::macro('designField', fn() => app(DesignFieldVariable::class));
        $controlTypes->register(Steps::class, Grid::class, Rendered::class, PresetBlock::class, BlockPicker::class);

        // A configuration that does not build no longer stops a page, so the control
        // panel has to say it. Alerts load on every page: never let this one break them.
        Event::listen(CpAlertsResolving::class, static function(CpAlertsResolving $event): void {
            try {
                $alert = app(ConfigAlert::class)->html((bool)currentUser()?->isAdmin());
            } catch (Throwable $e) {
                report($e);

                return;
            }

            if ($alert !== null) {
                $event->alerts[] = $alert;
            }
        });
    }
}
