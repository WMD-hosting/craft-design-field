<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * Which format renders which field type. The single source of that knowledge.
 *
 * Works on class-name strings so it stays Craft-free; callers pass
 * `get_class($field)` and `class_parents($field)`.
 *
 * @author WMD
 * @since 1.1.0
 */
final class FormatPicker
{
    // Constants
    // =========================================================================

    /** Every format a row can name. `auto` picks one by field type. */
    public const FORMATS = ['auto', 'text', 'rich', 'badge', 'files', 'image', 'cover', 'links', 'chips', 'date', 'dateTime', 'time', 'price', 'table', 'icon', 'color', 'map', 'dates', 'items', 'pageBuilder', 'element', 'part'];

    /** Element attributes a row may name besides custom fields. `price`/`sku` resolve on Commerce products. */
    /** Parts of a Commerce product page, shown as rows of the product block. */
    public const PRODUCT_PARTS = ['gallery', 'context', 'rating', 'variants', 'cart', 'actions', 'specs', 'reviews', 'pager', 'related', 'similar'];

    public const NATIVE = ['title', 'postDate', 'dateUpdated', 'author', 'url', 'slug', 'price', 'sku', 'gallery', 'context', 'rating', 'variants', 'cart', 'actions', 'specs', 'reviews', 'pager', 'related', 'similar'];

    /** Native attribute => auto format; anything else is `text`. */
    private const NATIVE_FORMATS = [
        'postDate' => 'date', 'dateUpdated' => 'date', 'price' => 'price', 'url' => 'links',
        'gallery' => 'part', 'context' => 'part', 'rating' => 'part', 'variants' => 'part', 'cart' => 'part', 'actions' => 'part',
        'specs' => 'part', 'reviews' => 'part', 'pager' => 'part', 'related' => 'part', 'similar' => 'part',
    ];

    /** Field class => auto format. Checked against the class and its parents. */
    private const AUTO = [
        'craft\fields\PlainText' => 'text',
        'craft\ckeditor\Field' => 'rich',
        'craft\redactor\Field' => 'rich',
        'craft\fields\Lightswitch' => 'badge',
        'craft\fields\Assets' => 'files',
        'craft\fields\Matrix' => 'items',
        'craft\fields\Number' => 'text',
        'craft\fields\Dropdown' => 'text',
        'craft\fields\RadioButtons' => 'text',
        'craft\fields\ButtonGroup' => 'text',
        'craft\fields\Entries' => 'text',
        'craft\fields\Categories' => 'text',
        'craft\fields\Tags' => 'text',
        'craft\fields\Users' => 'text',
        'craft\fields\Email' => 'links',
        'craft\fields\Url' => 'links',
        'craft\fields\Link' => 'links',
        'verbb\hyper\fields\HyperField' => 'links',
        'craft\fields\Date' => 'date',
        'craft\fields\Time' => 'time',
        'craft\fields\Money' => 'price',
        'craft\fields\Table' => 'table',
        'justinholtweb\legs\fields\TableField' => 'table',
        'justinholtweb\awesemo\fields\IconField' => 'icon',
        'craft\fields\Color' => 'color',
        'craft\fields\Addresses' => 'map',
    ];

    /** Formats other than `auto` and `text` that only fit some field types. */
    private const NEEDS = [
        'rich' => ['craft\ckeditor\Field', 'craft\redactor\Field'],
        'badge' => ['craft\fields\Lightswitch'],
        'files' => ['craft\fields\Assets'],
        'image' => ['craft\fields\Assets'],
        'cover' => ['craft\fields\Assets'],
        'links' => ['verbb\hyper\fields\HyperField', 'craft\fields\Link', 'craft\fields\Url', 'craft\fields\Email', 'craft\fields\Entries', 'craft\fields\Categories'],
        'chips' => ['craft\fields\Entries', 'craft\fields\Categories', 'craft\fields\Tags', 'craft\fields\Users'],
        'date' => ['craft\fields\Date'],
        'dateTime' => ['craft\fields\Date'],
        'time' => ['craft\fields\Date', 'craft\fields\Time'],
        'price' => ['craft\fields\Money', 'craft\fields\Number', 'craft\fields\PlainText'],
        'table' => ['craft\fields\Table', 'justinholtweb\legs\fields\TableField', 'craft\fields\Matrix'],
        'icon' => ['justinholtweb\awesemo\fields\IconField'],
        'color' => ['craft\fields\Color'],
        'map' => ['craft\fields\PlainText', 'craft\fields\Addresses', 'craft\fields\Matrix'],
        'dates' => ['craft\fields\Matrix'],
        'items' => ['craft\fields\Matrix'],
        'pageBuilder' => ['craft\fields\Matrix'],
        'element' => [],
        'part' => [],
    ];

    // Properties
    // =========================================================================

    /** @var array<string, array{label:string, fits:list<string>}> Custom formats from config, by name */
    private static array $custom = [];

    // Public Methods
    // =========================================================================

    /**
     * Registers the developer's own formats (plugin setting `entryFieldCustomFormats`): each renders
     * `_atoms/entry-field/custom/{name}.twig`. Replaces any earlier registration.
     *
     * A name must be camelCase letters and digits (it becomes a template path) and must not be a
     * built-in format; anything else is dropped. `fits` lists field classes, short names meaning
     * `craft\fields\{Name}`; without it the format fits every field type Entry Fields supports.
     *
     * @param array<mixed> $formats name => ['label' => string, 'fits' => list<string>]
     *
     * @author WMD
     * @since 1.2.0
     */
    public static function useCustom(array $formats): void
    {
        self::$custom = [];
        foreach ($formats as $name => $config) {
            if (!is_string($name) || !preg_match('/^[a-z][A-Za-z0-9]*$/', $name) || in_array($name, self::FORMATS, true) || !is_array($config)) {
                continue;
            }
            $fits = array_map(
                static fn(string $class) => str_contains($class, '\\') ? ltrim($class, '\\') : 'craft\\fields\\' . $class,
                array_values(array_filter((array)($config['fits'] ?? []), 'is_string')),
            );
            self::$custom[$name] = [
                'label' => is_string($config['label'] ?? null) && $config['label'] !== '' ? $config['label'] : $name,
                'fits' => $fits,
            ];
        }
    }

    /**
     * Every format a row can name: the built-ins, then the registered custom formats.
     *
     * @return list<string>
     *
     * @author WMD
     * @since 1.2.0
     */
    public static function all(): array
    {
        return [...self::FORMATS, ...array_keys(self::$custom)];
    }

    /**
     * @param string $format
     * @return bool
     *
     * @author WMD
     * @since 1.2.0
     */
    public static function isCustom(string $format): bool
    {
        return isset(self::$custom[$format]);
    }

    /**
     * The name shown in the Format dropdown: a custom format's label, else the format itself.
     *
     * @param string $format
     * @return string
     *
     * @author WMD
     * @since 1.2.0
     */
    public static function label(string $format): string
    {
        return self::$custom[$format]['label'] ?? $format;
    }

    /**
     * The format for a field type, or null when no format can print it: such rows are skipped.
     * Matrix returns `items`; the renderer refines it with forMatrix().
     *
     * @param string $fieldClass
     * @param list<string> $parents
     * @return string|null
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function auto(string $fieldClass, array $parents = []): ?string
    {
        foreach ([$fieldClass, ...array_values($parents)] as $class) {
            if (isset(self::AUTO[$class])) {
                return self::AUTO[$class];
            }
        }

        return null;
    }

    /**
     * @param string $attribute
     * @return string
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function forNative(string $attribute): string
    {
        return self::NATIVE_FORMATS[$attribute] ?? 'text';
    }

    /**
     * @param string $format
     * @param string $attribute
     * @return bool
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function nativeFits(string $format, string $attribute): bool
    {
        // Title and price also render as product parts (the h1 and the live price block).
        return in_array($format, ['auto', 'text', self::forNative($attribute)], true)
            || ($format === 'part' && in_array($attribute, ['title', 'price'], true));
    }

    /**
     * Whether an explicitly chosen format can render this field type.
     *
     * @param string $format
     * @param string $fieldClass
     * @param list<string> $parents
     * @return bool
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function fits(string $format, string $fieldClass, array $parents = []): bool
    {
        if (!in_array($format, self::all(), true) || self::auto($fieldClass, $parents) === null) {
            return false;
        }

        if (isset(self::$custom[$format])) {
            $fits = self::$custom[$format]['fits'];

            return $fits === [] || array_intersect($fits, [$fieldClass, ...array_values($parents)]) !== [];
        }

        if ($format === 'auto' || $format === 'text') {
            return true;
        }

        return array_intersect(self::NEEDS[$format], [$fieldClass, ...array_values($parents)]) !== [];
    }

    /**
     * The shape of a Matrix, from its nested entry types.
     *
     * @param list<array{handle:string, fields:list<string>, block:bool}> $types
     * @return string `pageBuilder` when every type is a page-builder block, `map` when one has `coords`,
     *                `dates` when one has `date`, else `items`
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function forMatrix(array $types): string
    {
        if ($types !== [] && array_filter($types, static fn(array $t) => !$t['block']) === []) {
            return 'pageBuilder';
        }

        foreach (['coords' => 'map', 'date' => 'dates'] as $handle => $format) {
            foreach ($types as $type) {
                if (in_array($handle, $type['fields'], true)) {
                    return $format;
                }
            }
        }

        return 'items';
    }

    /**
     * @param list<string> $kinds Asset kinds of the field's value
     * @return string `image` when every asset is an image, else `files`
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function refineAssets(array $kinds): string
    {
        return $kinds !== [] && array_unique($kinds) === ['image'] ? 'image' : 'files';
    }

    /**
     * @param bool $showDate
     * @param bool $showTime
     * @return string
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function refineDate(bool $showDate, bool $showTime): string
    {
        return match (true) {
            $showDate && $showTime => 'dateTime',
            !$showDate && $showTime => 'time',
            default => 'date',
        };
    }
}
