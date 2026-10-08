<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * One Entry Fields row: which field of the entry to show, where, and how.
 *
 * Craft-free. Every value is whitelisted or trimmed here, so templates and the
 * renderer can trust a Row without re-checking it.
 *
 * @author WMD
 * @since 1.1.0
 */
final class Row
{
    // Constants
    // =========================================================================

    public const TAGS = ['p', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    public const DEFAULT_TAG = 'p';
    public const DEFAULT_STYLE = 'default';
    public const DEFAULT_FORMAT = 'auto';
    public const SPACINGS = ['none', 'sm', 'md', 'lg'];
    public const COLUMNS = ['gallery', 'details', 'below'];

    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly string $field,
        public readonly string $slot,
        public readonly string $format = self::DEFAULT_FORMAT,
        public readonly string $label = '',
        public readonly string $textAfter = '',
        public readonly string $tag = self::DEFAULT_TAG,
        public readonly string $style = self::DEFAULT_STYLE,
        public readonly ?string $icon = null,
        public readonly ?string $anchor = null,
        public readonly bool $divider = false,
        public readonly ?string $spacing = null,
        public readonly ?int $element = null,
        public readonly ?string $column = null,
    ) {
    }

    /**
     * Builds a row from stored or posted data; null when it names no field or slot.
     *
     * @param array<string,mixed> $data
     * @return self|null
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function fromArray(array $data): ?self
    {
        $field = self::_string($data['field'] ?? '');
        $slot = self::_string($data['slot'] ?? '');
        $format = self::_string($data['format'] ?? '');
        $format = in_array($format, FormatPicker::all(), true) ? $format : self::DEFAULT_FORMAT;
        $element = is_numeric($data['element'] ?? null) && (int)$data['element'] > 0 ? (int)$data['element'] : null;

        // A row shows a field, or (format `element`) a Global Element; it always needs a slot.
        if ($slot === '' || ($field === '' && ($format !== 'element' || $element === null)) || ($format === 'element' && $element === null)) {
            return null;
        }

        $tag = self::_string($data['tag'] ?? '');
        $spacing = self::_string($data['spacing'] ?? '');

        return new self(
            field: $field,
            slot: $slot,
            format: $format,
            label: self::_string($data['label'] ?? ''),
            textAfter: self::_string($data['textAfter'] ?? ''),
            tag: in_array($tag, self::TAGS, true) ? $tag : self::DEFAULT_TAG,
            style: self::_string($data['style'] ?? '') ?: self::DEFAULT_STYLE,
            icon: self::_string($data['icon'] ?? '') ?: null,
            anchor: self::_string($data['anchor'] ?? '') ?: null,
            divider: filter_var($data['divider'] ?? false, FILTER_VALIDATE_BOOLEAN),
            spacing: in_array($spacing, self::SPACINGS, true) ? $spacing : null,
            element: $element,
            column: in_array($column = self::_string($data['column'] ?? ''), self::COLUMNS, true) ? $column : null,
        );
    }

    /**
     * @return array<string,mixed>
     *
     * @author WMD
     * @since 1.1.0
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'slot' => $this->slot,
            'format' => $this->format,
            'label' => $this->label,
            'textAfter' => $this->textAfter,
            'tag' => $this->tag,
            'style' => $this->style,
            'icon' => $this->icon,
            'anchor' => $this->anchor,
            'divider' => $this->divider,
            'spacing' => $this->spacing,
            'element' => $this->element,
            'column' => $this->column,
        ];
    }

    // Private Methods
    // =========================================================================

    private static function _string(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
