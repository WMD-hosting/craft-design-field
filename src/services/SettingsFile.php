<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use CraftCms\Cms\Support\File;
use CraftCms\Cms\Support\Json;
use Throwable;
use wmd\designfield\helpers\InputOverrides;

/**
 * What the settings page saves: the looks and the presets edited on it, in a JSON file
 * beside the config file. It is a site file like the config itself: written only where
 * admin changes are allowed, committed with the site and deployed with the code.
 *
 * The Groups service lays it over `design-field.php`; groups, options and profiles are
 * never in it.
 *
 * @author WMD
 * @since 2.0.0
 */
class SettingsFile
{
    // Const Properties
    // =========================================================================

    /**
     * The file, in the project's config folder.
     */
    public const FILE = 'craft/design-field.settings.json';

    /**
     * @param ?string $path Another place for the file (tests)
     */
    public function __construct(
        private readonly ?string $path = null,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * @return string The file's full path
     *
     * @author WMD
     * @since 2.0.0
     */
    public function path(): string
    {
        return $this->path ?? config_path(self::FILE);
    }

    /**
     * The saved looks and presets; empty when there is no file, or it is not what the
     * page writes (a hand edit gone wrong must not take the site's panels down).
     *
     * @return array{inputs:array<string,mixed>,presets:array<string,array<string,array<string,string>>>}
     *
     * @author WMD
     * @since 2.0.0
     */
    public function read(): array
    {
        $data = [];

        if (is_file($this->path())) {
            try {
                $data = Json::decode((string)file_get_contents($this->path()));
            } catch (Throwable) {
                $data = [];
            }
        }

        $presets = [];
        foreach (is_array($data['presets'] ?? null) ? $data['presets'] : [] as $profile => $list) {
            foreach (is_array($list) ? $list : [] as $name => $choices) {
                $choices = array_filter(is_array($choices) ? $choices : [], is_scalar(...));
                if ($choices !== []) {
                    $presets[(string)$profile][(string)$name] = array_map(strval(...), $choices);
                }
            }
        }

        return [
            'inputs' => array_filter(InputOverrides::clean(is_array($data) ? ($data['inputs'] ?? []) : [])),
            'presets' => $presets,
        ];
    }

    /**
     * Writes the looks and presets; with nothing left to keep the file goes.
     *
     * @param array<string,mixed> $inputs
     * @param array<string,array<string,array<string,string>>> $presets
     * @return void
     *
     * @author WMD
     * @since 2.0.0
     */
    public function write(array $inputs, array $presets): void
    {
        $data = array_filter([
            'inputs' => array_filter(InputOverrides::clean($inputs)),
            'presets' => $presets,
        ]);

        if ($data === []) {
            File::delete($this->path());

            return;
        }

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
