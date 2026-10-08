<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\models;

use InvalidArgumentException;
use wmd\designfield\helpers\Motion;

/**
 * A design option group (tone, spacing, alignment...) and its options.
 *
 * Craft-free on purpose: built from plain arrays so it can be unit tested.
 *
 * @author WMD
 * @since 1.0.0
 */
class Group
{
    // Const Properties
    // =========================================================================

    /**
     * Input style: a row of buttons.
     */
    public const INPUT_BUTTONS = 'buttons';

    /**
     * Input style: a dropdown.
     */
    public const INPUT_SELECT = 'select';

    /**
     * Input style: colour chips (options carry a `swatch` CSS colour or gradient).
     */
    public const INPUT_SWATCHES = 'swatches';

    /**
     * Input style: a grid of picture cards (options carry an `image` URL).
     */
    public const INPUT_TILES = 'tiles';

    /**
     * Input style: a clickable grid of cells, for alignment and position.
     */
    public const INPUT_POSITION = 'position';

    /**
     * Input style: a slider over the options in order, for scales.
     */
    public const INPUT_SLIDER = 'slider';

    /**
     * Input style: an on/off switch for a group of exactly two options (off, then on).
     */
    public const INPUT_TOGGLE = 'toggle';

    /**
     * Input style: an on/off pill for a group of exactly two options (off, then on); a
     * section's chips share one row.
     */
    public const INPUT_CHIP = 'chip';

    /**
     * Input style: small tiles that play each choice's motion on hover (helpers/Motion).
     */
    public const INPUT_MOTION = 'motion';

    /**
     * Input style: small cards drawn with each choice's own classes and the site's stylesheet
     * (corner radius, card style), so they show the brand's real values.
     */
    public const INPUT_RENDERED = 'rendered';

    /**
     * Every input style.
     */
    public const INPUTS = [
        self::INPUT_BUTTONS,
        self::INPUT_SELECT,
        self::INPUT_SWATCHES,
        self::INPUT_TILES,
        self::INPUT_POSITION,
        self::INPUT_SLIDER,
        self::INPUT_TOGGLE,
        self::INPUT_CHIP,
        self::INPUT_MOTION,
        self::INPUT_RENDERED,
    ];

    /**
     * Option keys that describe the option itself rather than a class part.
     */
    public const META_KEYS = ['label', 'icon', 'swatch', 'image', 'description', 'sample', 'motion', 'preview'];

    /**
     * Groups with more options than this render as a dropdown by default.
     */
    public const BUTTONS_MAX = 5;

    /**
     * Rough pixel budget for a button row inside one grid cell of the input.
     */
    public const BUTTONS_MAX_WIDTH = 250;

    /**
     * Key of the optional "let the template decide" option.
     */
    public const AUTO = 'auto';

    // Public Methods
    // =========================================================================

    /**
     * @param string $handle
     * @param string $label
     * @param array<string,array{label:string,icon:?string,swatch:?string,image:?string,description:?string,sample:?string,motion:?string,preview:?string,parts:array<string,string>}> $options
     * @param string $default Key of the default option
     * @param array<string,string> $aliases Old key => current key
     * @param string $input One of the INPUT_* constants
     * @param string $instructions Help text, shown as an info icon next to the label
     * @param bool $iconsOnly Buttons show only their icon; the label becomes the tooltip
     * @param array<string,string[]> $showIf Show the group only when another group has one of these keys
     * @param int $columns Cells per row for the position grid (0 = one row)
     * @param bool $textOnly Buttons show only their label: no icon, no "Aa" sample
     * @param string $section Heading the option is listed under in the panel ('' for none)
     * @param string[] $hidden Keys the picker leaves out unless a block has them (still valid options)
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $label,
        public readonly array $options,
        public readonly string $default,
        public readonly array $aliases = [],
        public readonly string $input = self::INPUT_BUTTONS,
        public readonly string $instructions = '',
        public readonly bool $iconsOnly = false,
        public readonly array $showIf = [],
        public readonly int $columns = 0,
        public readonly bool $textOnly = false,
        public readonly string $section = '',
        public readonly array $hidden = [],
    ) {
    }

    /**
     * Builds a group from its config array and the raw options.
     *
     * Raw options use the Design Tokens JSON format: a key maps either to a
     * class string, or to an object of named class strings plus optional
     * `label` and `icon`.
     *
     * @param string $handle
     * @param array<string,mixed> $config
     * @param array<string,mixed> $rawOptions
     * @return self
     * @throws InvalidArgumentException if the group has no options or an invalid default
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function fromConfig(string $handle, array $config, array $rawOptions): self
    {
        $options = [];

        // `auto: true` (or a label) prepends an empty option that lets the
        // template decide, e.g. a different image shape per variant.
        if (!empty($config['auto'])) {
            $auto = is_array($config['auto']) ? $config['auto'] : ['label' => is_string($config['auto']) ? $config['auto'] : 'Auto'];
            $options[self::AUTO] = self::_normalizeOption(self::AUTO, $auto + ['label' => 'Auto']);
        }

        foreach ($rawOptions as $key => $raw) {
            $options[(string)$key] = self::_normalizeOption((string)$key, $raw);
        }

        if ($options === []) {
            throw new InvalidArgumentException("Design group \"$handle\" has no options.");
        }

        // `optionMeta` adds a label, icon, swatch, image or description to options
        // defined elsewhere (a token file, or a generated config).
        foreach ($config['optionMeta'] ?? [] as $key => $meta) {
            if (!isset($options[(string)$key]) || !is_array($meta)) {
                continue;
            }
            foreach (self::META_KEYS as $name) {
                if (isset($meta[$name]) && (string)$meta[$name] !== '') {
                    $options[(string)$key][$name] = $name === 'motion'
                        ? Motion::resolve((string)$meta[$name], (string)$key)
                        : (string)$meta[$name];
                }
            }
        }

        // Rendered tiles: an option's own `preview` classes, or with `preview: true` its token
        // value (`rounded-2xl`), or with a list the token parts to draw (`['bg', 'text']`), on
        // top of `previewBase` (what makes the shape visible).
        $base = trim((string)($config['previewBase'] ?? ''));
        $parts = $config['preview'] ?? null;
        foreach ($options as $key => $option) {
            $own = $option['preview'] ?? match (true) {
                is_array($parts) => implode(' ', array_filter(array_map(static fn($part) => trim((string)($option['parts'][$part] ?? '')), $parts))) ?: null,
                !empty($parts) => $option['parts']['value'] ?? null,
                default => null,
            };
            $options[$key]['preview'] = $key !== self::AUTO && $own !== null ? trim("$base $own") : null;
        }

        $default = (string)($config['default'] ?? array_key_first($options));

        if (!isset($options[$default])) {
            throw new InvalidArgumentException("Design group \"$handle\" default \"$default\" is not one of its options.");
        }

        $iconsOnly = !empty($config['iconsOnly']);
        $input = $config['input'] ?? null;

        // A toggle only makes sense for exactly two options (off, then on).
        if (!self::_inputFits($input, $options)) {
            $input = self::_fitsButtons($options, $iconsOnly) ? self::INPUT_BUTTONS : self::INPUT_SELECT;
        }

        return new self(
            handle: $handle,
            label: (string)($config['label'] ?? self::humanize($handle)),
            options: $options,
            default: $default,
            aliases: array_map('strval', $config['aliases'] ?? []),
            input: $input,
            instructions: (string)($config['instructions'] ?? ''),
            iconsOnly: $iconsOnly,
            showIf: self::_showIf($config),
            columns: (int)($config['columns'] ?? 0),
            textOnly: !empty($config['textOnly']),
            section: trim((string)($config['section'] ?? '')),
        );
    }

    /**
     * Returns a copy with another default, used by per-profile overrides.
     *
     * @param string $default
     * @return self
     * @throws InvalidArgumentException if the key is not an option of this group
     *
     * @author WMD
     * @since 1.0.0
     */
    public function withDefault(string $default): self
    {
        return $this->withOverrides(['default' => $default]);
    }

    /**
     * Returns a copy with some settings changed for one profile: `default`,
     * `label`, `instructions`, `input`, `iconsOnly`, `textOnly`, `columns`, `section`, and
     * `showIf` (or its `variants` shorthand). Options stay those of the group.
     *
     * @param array<string,mixed> $overrides
     * @return self
     * @throws InvalidArgumentException if the default is not an option of this group
     *
     * @author WMD
     * @since 1.0.0
     */
    public function withOverrides(array $overrides): self
    {
        $default = isset($overrides['default']) ? (string)$overrides['default'] : $this->default;

        if (!isset($this->options[$default])) {
            throw new InvalidArgumentException("Design group \"$this->handle\" has no option \"$default\".");
        }

        $input = $overrides['input'] ?? $this->input;

        return new self(
            handle: $this->handle,
            label: (string)($overrides['label'] ?? $this->label),
            options: $this->options,
            default: $default,
            aliases: $this->aliases,
            input: self::_inputFits($input, $this->options) ? $input : $this->input,
            instructions: (string)($overrides['instructions'] ?? $this->instructions),
            iconsOnly: (bool)($overrides['iconsOnly'] ?? $this->iconsOnly),
            showIf: (isset($overrides['showIf']) || isset($overrides['variants'])) ? self::_showIf($overrides) : $this->showIf,
            columns: (int)($overrides['columns'] ?? $this->columns),
            textOnly: (bool)($overrides['textOnly'] ?? $this->textOnly),
            section: trim((string)($overrides['section'] ?? $this->section)),
            hidden: array_values(array_diff($this->hidden, [$default])),
        );
    }

    /**
     * Returns a copy with other labels or icons on some options (what editors read and
     * see; the keys, and so saved values and templates, stay the same).
     *
     * @param array<string,array{label?:string,icon?:string}> $meta Option key => label and/or icon
     * @return self
     *
     * @author WMD
     * @since 1.0.0
     */
    public function withOptionMeta(array $meta): self
    {
        $options = $this->options;

        foreach ($meta as $key => $changes) {
            if (!isset($options[$key])) {
                continue;
            }
            foreach (['label', 'icon'] as $name) {
                if (isset($changes[$name]) && $changes[$name] !== '') {
                    $options[$key][$name] = $changes[$name];
                }
            }
        }

        return new self(
            handle: $this->handle,
            label: $this->label,
            options: $options,
            default: $this->default,
            aliases: $this->aliases,
            input: $this->input,
            instructions: $this->instructions,
            iconsOnly: $this->iconsOnly,
            showIf: $this->showIf,
            columns: $this->columns,
            textOnly: $this->textOnly,
            section: $this->section,
            hidden: $this->hidden,
        );
    }

    /**
     * Returns a copy with pictures on options that have none yet (a picture set in the
     * config stays). Used for layout tiles found on disk.
     *
     * @param array<string,string> $images Option key => image URL
     * @return self
     *
     * @author WMD
     * @since 1.0.0
     */
    public function withImages(array $images): self
    {
        $options = $this->options;
        foreach ($images as $key => $url) {
            if (isset($options[$key]) && $options[$key]['image'] === null && $url !== '') {
                $options[$key]['image'] = $url;
            }
        }

        if ($options === $this->options) {
            return $this;
        }

        return new self(
            handle: $this->handle,
            label: $this->label,
            options: $options,
            default: $this->default,
            aliases: $this->aliases,
            input: $this->input,
            instructions: $this->instructions,
            iconsOnly: $this->iconsOnly,
            showIf: $this->showIf,
            columns: $this->columns,
            textOnly: $this->textOnly,
            section: $this->section,
            hidden: $this->hidden,
        );
    }

    /**
     * Returns a copy whose picker leaves out some choices (the settings page's "Hide this
     * choice", per block). They stay options: saved blocks, presets and validation keep
     * them, and a block that has one still sees it. `auto` and the default always show.
     *
     * @param string[] $keys
     * @return self
     *
     * @author WMD
     * @since 1.0.0
     */
    public function withHidden(array $keys): self
    {
        $hidden = array_values(array_intersect(
            array_diff(array_map('strval', $keys), [self::AUTO, $this->default]),
            array_map('strval', array_keys($this->options)),
        ));

        return new self(
            handle: $this->handle,
            label: $this->label,
            options: $this->options,
            default: $this->default,
            aliases: $this->aliases,
            input: $this->input,
            instructions: $this->instructions,
            iconsOnly: $this->iconsOnly,
            showIf: $this->showIf,
            columns: $this->columns,
            textOnly: $this->textOnly,
            section: $this->section,
            hidden: $hidden,
        );
    }

    /**
     * Input styles this group can be shown as, for choices made in the control panel.
     *
     * Config files are not held to this: a config `tiles` group with missing pictures
     * still renders (Health warns about the pictures instead).
     *
     * @return list<string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function supportedInputs(): array
    {
        $real = array_diff_key($this->options, [self::AUTO => true]);
        $with = static fn(string $meta) => count(array_filter($real, static fn(array $option) => $option[$meta] !== null));
        $inputs = [self::INPUT_BUTTONS, self::INPUT_SELECT];

        if (count($this->options) >= 2) {
            $inputs[] = self::INPUT_SLIDER;
        }

        if (self::_inputFits(self::INPUT_TOGGLE, $this->options)) {
            $inputs[] = self::INPUT_TOGGLE;
            $inputs[] = self::INPUT_CHIP;
        }

        if ($real !== [] && $with('swatch') === count($real)) {
            $inputs[] = self::INPUT_SWATCHES;
        }

        if ($with('image') > 0) {
            $inputs[] = self::INPUT_TILES;
        }

        if ($real !== [] && $with('preview') === count($real)) {
            $inputs[] = self::INPUT_RENDERED;
        }

        // Two choices with a motion by their key, or one that names its motion: a lone `slide`
        // in a list of layouts is not an animation.
        $named = array_filter($real, static fn(array $option, string $key) => $option['motion'] !== null && $option['motion'] !== Motion::match($key), ARRAY_FILTER_USE_BOTH);
        if ($with('motion') >= 2 || $named !== []) {
            $inputs[] = self::INPUT_MOTION;
        }

        // A grid of cells, or one row of icon cells (cells without icons show only dots).
        if ($this->columns > 0 || ($real !== [] && $with('icon') === count($real))) {
            $inputs[] = self::INPUT_POSITION;
        }

        return $inputs;
    }

    /**
     * Whether the group applies, given the other groups' current keys.
     *
     * @param array<string,string> $keys Group handle => current key
     * @return bool
     *
     * @author WMD
     * @since 1.0.0
     */
    public function appliesTo(array $keys): bool
    {
        foreach ($this->showIf as $group => $allowed) {
            if (!in_array($keys[$group] ?? '', $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolves a stored key (following aliases) to a current option key.
     *
     * @param ?string $key
     * @return ?string The option key, or null when the key is unknown
     *
     * @author WMD
     * @since 1.0.0
     */
    public function resolveKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        if (isset($this->options[$key])) {
            return $key;
        }

        $alias = $this->aliases[$key] ?? null;

        if ($alias !== null && isset($this->options[$alias])) {
            return $alias;
        }

        return null;
    }

    /**
     * Builds the token for an option key; falls back to the default.
     *
     * @param ?string $key
     * @param bool $lenient Pass unknown keys through instead of using the default
     * @return Token
     *
     * @author WMD
     * @since 1.0.0
     */
    public function token(?string $key, bool $lenient = false): Token
    {
        // Lenient (reading old fields or mock data): a key the template used to
        // receive passes through unchanged, even if it is not an option here.
        if ($lenient && $key !== null && $key !== '' && $this->resolveKey($key) === null) {
            return new Token($this->handle, $key, self::humanize($key));
        }

        $key = $this->resolveKey($key) ?? $this->default;
        $option = $this->options[$key];

        return new Token($this->handle, $key, $option['label'], $option['parts'], $key === $this->default);
    }

    /**
     * Turns a handle or key into a label: `surfaceStrong` → `Surface strong`.
     *
     * @param string $value
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function humanize(string $value): string
    {
        $words = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|[-_]+/', ' ', $value) ?? $value;

        return ucfirst(strtolower(trim($words)));
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether the options fit as a button row: Craft's button groups never wrap,
     * so a long row is clipped. Estimates ~7px per character plus button padding.
     *
     * @param array<string,array{label:string,icon:?string,swatch:?string,image:?string,description:?string,sample:?string,motion:?string,preview:?string,parts:array<string,string>}> $options
     * @param bool $iconsOnly
     * @return bool
     */
    private static function _fitsButtons(array $options, bool $iconsOnly): bool
    {
        if (!$iconsOnly && count($options) > self::BUTTONS_MAX) {
            return false;
        }

        $width = 0;

        foreach ($options as $option) {
            $width += $iconsOnly && $option['icon'] !== null ? 36 : mb_strlen($option['label']) * 7 + 20;
        }

        return $width <= self::BUTTONS_MAX_WIDTH;
    }

    /**
     * Whether an input style is known and, for a toggle or chip, the group has exactly two options.
     *
     * @param mixed $input
     * @param array<string,mixed> $options
     * @return bool
     */
    private static function _inputFits(mixed $input, array $options): bool
    {
        $onOff = $input === self::INPUT_TOGGLE || $input === self::INPUT_CHIP;

        return in_array($input, self::INPUTS, true) && (!$onOff || count($options) === 2);
    }

    /**
     * Reads `showIf` (group => keys) or its `variants` shorthand.
     *
     * @param array<string,mixed> $config
     * @return array<string,string[]>
     */
    private static function _showIf(array $config): array
    {
        $showIf = $config['showIf'] ?? [];

        if (isset($config['variants'])) {
            $showIf['variant'] = $config['variants'];
        }

        return array_map(static fn($keys) => array_map('strval', (array)$keys), (array)$showIf);
    }

    /**
     * @param string $key
     * @param mixed $raw
     * @return array{label:string,icon:?string,swatch:?string,image:?string,description:?string,sample:?string,motion:?string,preview:?string,parts:array<string,string>}
     */
    private static function _normalizeOption(string $key, mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['label' => self::humanize($key), 'icon' => null, 'swatch' => null, 'image' => null, 'description' => null, 'sample' => null, 'motion' => Motion::match($key), 'preview' => null, 'parts' => ['value' => (string)$raw]];
        }

        $parts = [];

        foreach ($raw as $name => $value) {
            if (in_array($name, self::META_KEYS, true) || !is_scalar($value)) {
                continue;
            }

            $parts[(string)$name] = (string)$value;
        }

        $meta = static fn(string $name) => isset($raw[$name]) && (string)$raw[$name] !== '' ? (string)$raw[$name] : null;

        return [
            'label' => $meta('label') ?? self::humanize($key),
            'icon' => $meta('icon'),
            'swatch' => $meta('swatch'),
            'image' => $meta('image'),
            'description' => $meta('description'),
            'sample' => $meta('sample'),
            'motion' => Motion::resolve($meta('motion'), $key),
            'preview' => $meta('preview'),
            'parts' => $parts,
        ];
    }
}
