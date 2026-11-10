<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use Override;
use wmd\designfield\console\SchemaCommand;
use wmd\designfield\fields\Design;
use wmd\designfield\services\Groups;
use wmd\designfield\variables\DesignFieldVariable;

/**
 * Design Field for Craft 6: the Design field type, the registry service and
 * `craft.designField`.
 *
 * The control-panel input (swatches, tiles, sliders), the settings page and the
 * Variant condition rule are not ported yet; the field's input is a JSON textarea.
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

    // Protected Properties
    // =========================================================================

    protected array $fieldTypes = [Design::class];

    protected array $commands = [SchemaCommand::class];

    // Public Methods
    // =========================================================================

    #[Override]
    public function register(): void
    {
        $this->app->singleton(Groups::class);
    }

    public function boot(): void
    {
        CraftVariable::macro('designField', fn() => app(DesignFieldVariable::class));
    }
}
