<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\Ui\UiControlTypes;
use Override;
use wmd\designfield\http\SettingsScreen;
use wmd\designfield\console\CheckCommand;
use wmd\designfield\console\SchemaCommand;
use wmd\designfield\console\UsageCommand;
use wmd\designfield\fields\Design;
use wmd\designfield\services\Groups;
use wmd\designfield\variables\DesignFieldVariable;
use wmd\designfield\web\controls\Grid;
use wmd\designfield\web\controls\PresetBlock;
use wmd\designfield\web\controls\Rendered;
use wmd\designfield\web\controls\Steps;

/**
 * Design Field for Craft 6: the Design field type with its panel and its Variant
 * condition rule, the registry service, `craft.designField` and the settings page
 * (http/SettingsScreen).
 *
 * Not ported yet: the block preview.
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
     * The settings page only reads (the configuration check and the usage report), so
     * it also opens where admin changes are off.
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
     * `design-field:presets`), built by `npm run build` into `public/build`.
     */
    protected array $vite = ['resources/js/cp.ts'];

    protected array $commands = [SchemaCommand::class, CheckCommand::class, UsageCommand::class];

    // Public Methods
    // =========================================================================

    #[Override]
    public function register(): void
    {
        $this->app->singleton(Groups::class);
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
        $controlTypes->register(Steps::class, Grid::class, Rendered::class, PresetBlock::class);
    }
}
