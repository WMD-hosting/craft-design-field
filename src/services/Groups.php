<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use CraftCms\Cms\Support\Json;
use InvalidArgumentException;
use wmd\designfield\helpers\Registry;

/**
 * Builds the design registry from the config file and the
 * `config/designtokens/*.json` files.
 *
 * There are no control-panel tables on Craft 6 yet, so the config file is the
 * whole configuration (its `groups`, `profiles` and the rest).
 *
 * @author WMD
 * @since 1.0.0
 */
class Groups
{
    // Const Properties
    // =========================================================================

    public const TOKENS_DIR = 'designtokens';

    /**
     * Config files, first one found wins: Craft 6's place for plugin config,
     * then the file a project brings over from Craft 5.
     */
    public const CONFIG_FILES = ['craft/design-field.php', 'design-field.php'];

    // Private Properties
    // =========================================================================

    private ?Registry $registry = null;

    // Public Methods
    // =========================================================================

    /**
     * The registry, built once per request.
     *
     * @return Registry
     * @throws InvalidArgumentException if the config or a token file is invalid
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getRegistry(): Registry
    {
        return $this->registry ??= Registry::fromConfig($this->config(), $this->loadTokens(...));
    }

    /**
     * The config file's array with the defaults filled in.
     *
     * The file is read here rather than through `config('design-field')`: Laravel also
     * loads `design-field.generated.php` and nests it under that key.
     *
     * @return array<string,mixed>
     *
     * @author WMD
     * @since 2.0.0
     */
    public function config(): array
    {
        $config = [];

        foreach (self::CONFIG_FILES as $file) {
            if (is_file($path = config_path($file))) {
                $config = require $path;
                break;
            }
        }

        return $config + [
            'groups' => [],
            'profiles' => [],
            'fieldMap' => [],
            'groupOverrides' => [],
            'profileOverrides' => [],
            'presets' => [],
            'inputs' => [],
            'sections' => [],
            'tileImage' => static fn(): ?string => null,
        ];
    }

    /**
     * Reads `config/designtokens/{name}.json`.
     *
     * @param string $name File name without `.json`
     * @return array<string,mixed>
     * @throws InvalidArgumentException if the file is missing or not a JSON object
     *
     * @author WMD
     * @since 1.0.0
     */
    public function loadTokens(string $name): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid token file name \"$name\".");
        }

        $path = config_path(self::TOKENS_DIR . DIRECTORY_SEPARATOR . $name . '.json');

        if (!is_file($path)) {
            throw new InvalidArgumentException("Token file not found: $path");
        }

        $tokens = Json::decode((string)file_get_contents($path));

        if (!is_array($tokens)) {
            throw new InvalidArgumentException("Token file is not a JSON object: $path");
        }

        return $tokens;
    }
}
