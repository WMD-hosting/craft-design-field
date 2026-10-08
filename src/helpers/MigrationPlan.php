<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use InvalidArgumentException;
use wmd\designfield\models\Group;

/**
 * Which old option field feeds which Design group, and how an old value
 * becomes a design key. Craft-free, so it is unit tested.
 *
 * @author WMD
 * @since 1.0.0
 */
class MigrationPlan
{
    // Public Methods
    // =========================================================================

    /**
     * @param array<string,string> $fields Old field handle => group handle
     * @param array<string,Group> $groups Groups of the target profile
     *
     * @author WMD
     * @since 1.0.0
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $groups,
    ) {
    }

    /**
     * Builds the mapping from the old layout's field handles.
     *
     * Explicit pairs win; otherwise a field maps to the group with the same
     * handle, and `{type}Variant` maps to `variant`.
     *
     * @param string[] $oldHandles Field handles in the old layout
     * @param array<string,Group> $groups Groups of the target profile
     * @param string $typeHandle Old entry type handle
     * @param array<string,string> $explicit Old field handle => group handle
     * @param bool $strict Throw on explicit pairs whose field or group is missing; otherwise skip them
     * @return self
     * @throws InvalidArgumentException in strict mode, if an explicit pair names an unknown field or group
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function build(array $oldHandles, array $groups, string $typeHandle, array $explicit = [], bool $strict = true): self
    {
        $fields = [];

        foreach ($explicit as $field => $group) {
            if (!$strict && (!in_array($field, $oldHandles, true) || !isset($groups[$group]))) {
                continue;
            }

            if (!in_array($field, $oldHandles, true)) {
                throw new InvalidArgumentException("Field \"$field\" is not in the $typeHandle layout.");
            }

            if (!isset($groups[$group])) {
                throw new InvalidArgumentException("Group \"$group\" is not in the target profile.");
            }

            $fields[$field] = $group;
        }

        foreach ($oldHandles as $handle) {
            if (isset($fields[$handle])) {
                continue;
            }

            if (isset($groups[$handle])) {
                $fields[$handle] = $handle;
                continue;
            }

            if ($handle === $typeHandle . 'Variant' && isset($groups['variant'])) {
                $fields[$handle] = 'variant';
            }
        }

        return new self($fields, $groups);
    }

    /**
     * Parses `--map=accentToken:tone,paddingToken:spacing`.
     *
     * @param ?string $map
     * @return array<string,string>
     * @throws InvalidArgumentException on a malformed pair
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function parseMap(?string $map): array
    {
        $pairs = [];

        foreach (array_filter(array_map('trim', explode(',', (string)$map))) as $pair) {
            $parts = array_map('trim', explode(':', $pair, 2));

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new InvalidArgumentException("Expected field:group, got \"$pair\".");
            }

            $pairs[$parts[0]] = $parts[1];
        }

        return $pairs;
    }

    /**
     * Reads a key out of an old field value.
     *
     * Duck-typed so it needs no plugin classes: Design Tokens values expose
     * `key`, option fields (Button Group, Dropdown, Radio) expose `value`,
     * Lightswitch values are booleans, Color values give their hex.
     *
     * @param mixed $value
     * @return ?string Null when the field is empty
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function keyOf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // Color fields: the hex, lower case without '#', as FieldShapes keys a palette.
        if (is_object($value) && method_exists($value, 'getHex')) {
            return strtolower(ltrim((string)$value->getHex(), '#')) ?: null;
        }

        if (is_object($value)) {
            $key = $value->key ?? $value->value ?? null;

            return is_scalar($key) && (string)$key !== '' ? (string)$key : null;
        }

        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }

    /**
     * Turns old values into design keys.
     *
     * @param array<string,mixed> $values Old field handle => field value
     * @param array<string,string> $current Keys already stored in the Design field
     * @param bool $overwrite Replace keys that are already set
     * @return array{keys:array<string,string>,moved:array<string,string>,unknown:array<string,string>,kept:array<string,string>,empty:string[]}
     *
     * @author WMD
     * @since 1.0.0
     */
    public function apply(array $values, array $current, bool $overwrite = false): array
    {
        $keys = $current;
        $moved = [];
        $unknown = [];
        $kept = [];
        $empty = [];

        foreach ($this->fields as $field => $groupHandle) {
            $key = self::keyOf($values[$field] ?? null);

            // Nothing chosen: the group keeps its default, so the template's
            // fallback applies. Reported, because an old template may have
            // rendered an empty value differently from its intended default.
            if ($key === null) {
                $empty[] = $groupHandle;
                continue;
            }

            $resolved = $this->groups[$groupHandle]->resolveKey($key);

            if ($resolved === null) {
                $unknown[$groupHandle] = $key;
                continue;
            }

            // A stored default (saving fills every group) counts as unset.
            $existing = $current[$groupHandle] ?? '';
            $isSet = $existing !== '' && $existing !== Group::AUTO && $existing !== $this->groups[$groupHandle]->default;

            if (!$overwrite && $isSet) {
                $kept[$groupHandle] = $current[$groupHandle];
                continue;
            }

            $keys[$groupHandle] = $resolved;
            $moved[$groupHandle] = $resolved;
        }

        return ['keys' => $keys, 'moved' => $moved, 'unknown' => $unknown, 'kept' => $kept, 'empty' => $empty];
    }
}
