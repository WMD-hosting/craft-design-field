<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\models;

use ArrayAccess;
use LogicException;
use Stringable;
use wmd\designfield\helpers\Registry;

/**
 * The value of a Design field: one token per group.
 *
 * Twig reads groups as properties (`block.design.tone.get('bg')`). Groups the
 * profile does not include still resolve to their default, and unknown groups
 * resolve to an empty token, so a template never throws on a missing group.
 *
 * @author WMD
 * @since 1.0.0
 * @implements ArrayAccess<string,Token>
 */
class DesignValue implements ArrayAccess, Stringable
{
    // Private Properties
    // =========================================================================

    /**
     * @var array<string,Token> Resolved tokens, memoized per group
     */
    private array $_tokens = [];

    // Public Methods
    // =========================================================================

    /**
     * @param array<string,mixed> $keys Raw group handle => option key, as stored or submitted
     * @param array<string,Group> $groups Groups of the active profile
     * @param array<string,Group> $allGroups Every configured group
     * @param bool $lenient Read from old fields or mock data: keep every key the
     *     template used to see, also for groups outside the profile
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __construct(
        private readonly array $keys,
        private readonly array $groups,
        private readonly array $allGroups,
        private readonly bool $lenient = false,
    ) {
    }

    /**
     * Returns the token of a group.
     *
     * @param string $group
     * @return Token
     *
     * @author WMD
     * @since 1.0.0
     */
    public function get(string $group): Token
    {
        if (isset($this->_tokens[$group])) {
            return $this->_tokens[$group];
        }

        $definition = $this->groups[$group] ?? $this->allGroups[$group] ?? null;

        if ($definition === null) {
            return Token::none($group);
        }

        $key = isset($this->groups[$group]) || $this->lenient ? $this->_rawKey($group) : null;

        return $this->_tokens[$group] = $definition->token($key, $this->lenient);
    }

    /**
     * Whether the active profile includes a group.
     *
     * @param string $group
     * @return bool
     *
     * @author WMD
     * @since 1.0.0
     */
    public function has(string $group): bool
    {
        return isset($this->groups[$group]);
    }

    /**
     * Class string of the given groups, or of every profile group.
     *
     * @param string ...$groups
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function classes(string ...$groups): string
    {
        $groups = $groups ?: array_keys($this->groups);
        $classes = array_map(fn(string $group) => (string)$this->get($group), $groups);

        return trim(implode(' ', array_filter($classes)));
    }

    /**
     * Groups of the active profile.
     *
     * @return array<string,Group>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * Resolved option key per profile group.
     *
     * @return array<string,string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function keys(): array
    {
        $keys = [];

        foreach (array_keys($this->groups) as $group) {
            $keys[$group] = $this->get($group)->key;
        }

        return $keys;
    }

    /**
     * Choices that differ from their group's default, among the groups the current
     * layout shows: what a summary of this block's design lists.
     *
     * @return array<string,string> Group handle => option key
     *
     * @author WMD
     * @since 1.0.0
     */
    public function changes(): array
    {
        $keys = $this->keys();
        $changes = [];

        foreach ($this->groups as $handle => $group) {
            if ($keys[$handle] !== $group->default && $group->appliesTo($keys)) {
                $changes[$handle] = $keys[$handle];
            }
        }

        return $changes;
    }

    /**
     * Submitted or stored keys that match no option or alias of their group.
     *
     * @return array<string,string> Group handle => unknown key
     *
     * @author WMD
     * @since 1.0.0
     */
    public function invalid(): array
    {
        $invalid = [];

        foreach ($this->groups as $handle => $group) {
            $raw = $this->_rawKey($handle);

            if ($raw !== null && $group->resolveKey($raw) === null) {
                $invalid[$handle] = $raw;
            }
        }

        return $invalid;
    }

    /**
     * Data to store: resolved keys for profile groups, raw keys kept for the rest.
     *
     * Keys of groups outside the profile survive a save, so switching a
     * block's profile back and forth loses nothing.
     *
     * @return array<string,string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function toArray(): array
    {
        $stored = array_filter(array_map(
            static fn(mixed $key) => is_scalar($key) ? (string)$key : '',
            $this->keys,
        ));

        return array_merge($stored, $this->keys());
    }

    /**
     * Twig property access: `design.tone`.
     *
     * @param string $name
     * @return Token
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __get(string $name): Token
    {
        return $this->get($name);
    }

    /**
     * Twig checks this before reading a property.
     *
     * True for any name except this class's own methods: a template reading a
     * group that is no longer configured gets an empty token instead of a
     * Twig error, so removing a group from the settings never breaks a page.
     *
     * @param string $name
     * @return bool
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __isset(string $name): bool
    {
        return !in_array($name, Registry::RESERVED_HANDLES, true);
    }

    /**
     * @inheritdoc
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->groups[(string)$offset]) || isset($this->allGroups[(string)$offset]);
    }

    /**
     * @inheritdoc
     */
    public function offsetGet(mixed $offset): Token
    {
        return $this->get((string)$offset);
    }

    /**
     * @inheritdoc
     * @throws LogicException always; values are immutable
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Design values are immutable.');
    }

    /**
     * @inheritdoc
     * @throws LogicException always; values are immutable
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Design values are immutable.');
    }

    /**
     * Printing the value outputs every profile class: `class="{{ block.design }}"`.
     *
     * @return string
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __toString(): string
    {
        return $this->classes();
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $group
     * @return ?string
     */
    private function _rawKey(string $group): ?string
    {
        $raw = $this->keys[$group] ?? null;

        return is_scalar($raw) && $raw !== '' ? (string)$raw : null;
    }
}
