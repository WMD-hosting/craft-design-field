<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\models;

use Stringable;

/**
 * One resolved design choice: the selected option of a group.
 *
 * Mirrors the Design Tokens plugin API (`.key`, `.get('bg')`, string cast),
 * so templates can switch from a single token field to the Design field
 * without rewriting their reads.
 *
 * @author WMD
 * @since 1.0.0
 */
class Token implements Stringable
{
    // Public Methods
    // =========================================================================

    /**
     * @param string $group Handle of the group this token belongs to
     * @param string $key Selected option key, empty for an unknown group
     * @param string $label Human-readable option label
     * @param array<string,string> $parts Named class strings (`value`, or `bg`/`text`/...)
     * @param bool $isDefault Whether this is the group's default option
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __construct(
        public readonly string $group,
        public readonly string $key,
        public readonly string $label = '',
        public readonly array $parts = [],
        public readonly bool $isDefault = false,
    ) {
    }

    /**
     * Returns an empty token, used for groups that are not configured.
     *
     * @param string $group
     * @return self
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function none(string $group): self
    {
        return new self($group, '');
    }

    /**
     * Returns one named part of the token, e.g. `get('bg')`.
     *
     * @param string $part
     * @param string $default Returned when the part is not defined
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function get(string $part, string $default = ''): string
    {
        return $this->parts[$part] ?? $default;
    }

    /**
     * Whether the editor left the choice to the template (the `auto` option).
     *
     * @return bool
     *
     * @author WMD
     * @since 1.0.0
     */
    public function isAuto(): bool
    {
        return $this->key === Group::AUTO;
    }

    /**
     * The key, or a fallback when the choice is `auto` or the group is unknown.
     *
     * `design.imageShape.keyOr('round')` gives each variant its own default.
     *
     * @param string $fallback
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function keyOr(string $fallback): string
    {
        return $this->key === '' || $this->isAuto() ? $fallback : $this->key;
    }

    /**
     * The classes, or a fallback when the choice is `auto`, unknown or empty.
     *
     * `design.aspectRatio.classOr('aspect-[21/9]')` gives each layout its own default.
     *
     * @param string $fallback
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function classOr(string $fallback): string
    {
        return $this->key === '' || $this->isAuto() ? $fallback : (string)$this;
    }

    /**
     * Whether the token resolves to a known option.
     *
     * @return bool
     *
     * @author WMD
     * @since 1.0.0
     */
    public function exists(): bool
    {
        return $this->key !== '';
    }

    /**
     * All parts joined, which is the class string to print.
     *
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __toString(): string
    {
        return trim(implode(' ', array_filter($this->parts, static fn(string $part) => $part !== '')));
    }
}
