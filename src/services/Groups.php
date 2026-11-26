<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use CraftCms\Cms\Support\Json;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ParseError;
use Throwable;
use wmd\designfield\helpers\InputOverrides;
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

    /**
     * Where the Craft 5 plugin had its config file. Craft 6 does not read it there.
     */
    public const CRAFT5_CONFIG_FILE = 'design-field.php';

    // Private Properties
    // =========================================================================

    private ?Registry $registry = null;

    /**
     * @var ?InvalidArgumentException Why the registry does not build, kept so a page of
     * many blocks does not build it again for each of them
     */
    private ?InvalidArgumentException $failure = null;

    private ?Registry $standIn = null;

    /**
     * @var array<string,mixed>|null The config file's array, read once per request
     */
    private ?array $config = null;

    // Public Methods
    // =========================================================================

    /**
     * The registry, built once per request. For the check, the settings page and the
     * console: they report why it does not build. Whatever reads a value to render a
     * page or a form asks {@see safeRegistry()} instead.
     *
     * @return Registry
     * @throws InvalidArgumentException if the config or a token file is invalid
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getRegistry(): Registry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            return $this->registry = $this->build([]);
        } catch (InvalidArgumentException $e) {
            throw $this->failure = $e;
        }
    }

    /**
     * The registry for reading values: it never throws. When the configuration does not
     * build, the reason is logged once and a registry without options stands in
     * ({@see Registry::standIn()}), so pages keep rendering, with the templates' own
     * defaults instead of the design classes, and stored values stay as they are.
     *
     * @return Registry
     *
     * @author WMD
     * @since 2.0.0
     */
    public function safeRegistry(): Registry
    {
        try {
            return $this->getRegistry();
        } catch (InvalidArgumentException $e) {
            if ($this->standIn === null) {
                Log::error('Design Field: the configuration does not build, so design values are read without their options. ' . $e->getMessage(), ['exception' => $e]);
            }

            return $this->standIn ??= Registry::standIn();
        }
    }

    /**
     * Why the configuration does not build, null when it does.
     *
     * @return ?string
     *
     * @author WMD
     * @since 2.0.0
     */
    public function failure(): ?string
    {
        try {
            $this->getRegistry();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Whether a config file sits where the Craft 5 plugin read it (`config/design-field.php`).
     * Craft 6 reads `config/craft/design-field.php` only, so that file configures nothing.
     *
     * @return bool
     *
     * @author WMD
     * @since 2.0.0
     */
    public function hasCraft5File(): bool
    {
        return is_file(config_path(self::CRAFT5_CONFIG_FILE));
    }

    /**
     * Whether the config file exists at its Craft 6 path.
     *
     * @return bool
     *
     * @author WMD
     * @since 2.0.0
     */
    public function hasConfigFile(): bool
    {
        return is_file(config_path(self::CONFIG_FILE));
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
     * load `design-field.generated.php` beside it as a config of its own. It is read once
     * per request: building the registry asks for `tileImages` once per layout, and every
     * run of the file costs its full time again, plus memory that PHP keeps until the
     * process ends for each closure the file declares.
     *
     * @return array<string,mixed>
     *
     * @author WMD
     * @since 2.0.0
     */
    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $config = $this->fileConfig();
        $saved = app(SettingsFile::class)->read();

        // What the settings page saved comes last: its looks win entry by entry, and its
        // presets apply unless the file names `presets` itself (the page then shows them read only).
        $config['inputs'] = InputOverrides::merge($config['inputs'] ?? [], $saved['inputs']);
        if (!array_key_exists('presets', $config)) {
            $config['presets'] = $saved['presets'];
        }

        return $this->config = $config + [
            'groups' => [],
            'profiles' => [],
            'fieldMap' => [],
            'groupOverrides' => [],
            'profileOverrides' => [],
            'presets' => [],
            'inputs' => [],
            'sections' => [],
            'tileImages' => '/design-field/{type}/{key}.jpg',
            'previewStylesheet' => '',
            'blockPreviewUrl' => '',
            'tileSkip' => '',
            'previewAttributes' => [],
        ];
    }

    /**
     * What `config/craft/design-field.php` returns, as written: no defaults, and nothing
     * the settings page saved.
     *
     * @return array<string,mixed>
     * @throws InvalidArgumentException if the file does not load (a syntax error, an exception it throws)
     *
     * @author WMD
     * @since 2.0.0
     */
    public function fileConfig(): array
    {
        $path = config_path(self::CONFIG_FILE);

        try {
            $config = is_file($path) ? require $path : [];
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf(
                'config/%s does not load: %s%s.',
                self::CONFIG_FILE,
                rtrim($e->getMessage(), '.'),
                $e instanceof ParseError ? ' on line ' . $e->getLine() : '',
            ), previous: $e);
        }

        return is_array($config) ? $config : [];
    }

    /**
     * A registry for the configuration with some keys replaced, to check looks or
     * presets before the settings page saves them.
     *
     * @param array<string,mixed> $overrides
     * @return Registry
     * @throws InvalidArgumentException if the configuration does not build with them
     *
     * @author WMD
     * @since 2.0.0
     */
    public function registryWith(array $overrides): Registry
    {
        return $this->build($overrides);
    }

    /**
     * Runs `$build` with the registry built from other looks than the saved ones, for the
     * settings page's preview of a look before it is saved. The request's own registry is
     * back when it returns: nothing else reads the unsaved looks.
     *
     * @template T
     * @param array<string,mixed> $inputs The `inputs` to build with, whole
     * @param callable():T $build
     * @return T
     * @throws InvalidArgumentException if the configuration does not build with them
     *
     * @author WMD
     * @since 2.0.0
     */
    public function withInputs(array $inputs, callable $build): mixed
    {
        $own = $this->registry;
        $this->registry = $this->registryWith(['inputs' => $inputs]);

        try {
            return $build();
        } finally {
            $this->registry = $own;
        }
    }

    /**
     * Builds a registry from the configuration with some keys replaced.
     *
     * Whatever stops it is one kind of exception: a config of the wrong shape (a group
     * that is a string, a profile that is no list) makes PHP raise a TypeError or a
     * warning deep in the build, which nothing that catches a configuration error expects.
     *
     * @param array<string,mixed> $overrides
     * @return Registry
     * @throws InvalidArgumentException if the configuration does not build
     */
    private function build(array $overrides): Registry
    {
        try {
            return Registry::fromConfig($overrides + ['tileImage' => $this->tileImage(...)] + $this->config(), $this->loadTokens(...));
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('The configuration does not have the shape the plugin reads: ' . rtrim($e->getMessage(), '.') . '.', previous: $e);
        }
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
