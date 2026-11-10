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
 * Builds the design registry from `config/craft/design-field.php` and the
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
     * The config file, in Craft 6's place for plugin config.
     */
    public const CONFIG_FILE = 'craft/design-field.php';

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
        return $this->registry ??= Registry::fromConfig(
            ['tileImage' => $this->tileImage(...)] + $this->config(),
            $this->loadTokens(...),
        );
    }

    /**
     * A layout's picture under the web root (`tileImages`), with its file time so a redone
     * picture shows at once; null when there is no file.
     *
     * @param string $type Entry type handle
     * @param string $key Layout key
     * @return ?string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function tileImage(string $type, string $key): ?string
    {
        $path = $this->tilePath($type, $key);

        return $path !== null ? $this->picture($path) : null;
    }

    /**
     * The web path of a layout's picture (`tileImages` filled in), null when tiles are off
     * or the type or key is not a plain handle.
     *
     * @param string $type
     * @param string $key
     * @return ?string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function tilePath(string $type, string $key): ?string
    {
        $pattern = trim((string)$this->config()['tileImages']);

        // Handles only: the path must never leave the tiles folder.
        if ($pattern === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $type) || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return null;
        }

        return '/' . ltrim(strtr($pattern, ['{type}' => $type, '{key}' => $key]), '/');
    }

    /**
     * A picture as the panel shows it: one under the web root gets its file time, and is
     * null when the file is missing; a full URL is returned as it is.
     *
     * @param string $image A path from the web root, or a URL
     * @return ?string
     *
     * @author WMD
     * @since 2.0.0
     */
    public function picture(string $image): ?string
    {
        if (!str_starts_with($image, '/') || str_starts_with($image, '//')) {
            return $image !== '' ? $image : null;
        }

        $file = public_path(ltrim((string)parse_url($image, PHP_URL_PATH), '/'));

        if (!is_file($file)) {
            return null;
        }

        return str_contains($image, '?') ? $image : $image . '?v=' . filemtime($file);
    }

    /**
     * The config file's array with the defaults filled in.
     *
     * The file is read here rather than through Laravel's `config()`, which would also
     * load `design-field.generated.php` beside it as a config of its own.
     *
     * @return array<string,mixed>
     *
     * @author WMD
     * @since 2.0.0
     */
    public function config(): array
    {
        $path = config_path(self::CONFIG_FILE);
        $config = is_file($path) ? require $path : [];

        return $config + [
            'groups' => [],
            'profiles' => [],
            'fieldMap' => [],
            'groupOverrides' => [],
            'profileOverrides' => [],
            'presets' => [],
            'inputs' => [],
            'sections' => [],
            'tileImages' => '/design-field/{type}/{key}.jpg',
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
