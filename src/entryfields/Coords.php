<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * "lat,lng" as editors type it into a coords field, checked before it reaches a map URL.
 *
 * @author WMD
 * @since 1.1.0
 */
final class Coords
{
    /**
     * @param mixed $value
     * @return string|null normalised "lat,lng", or null when not a valid coordinate pair
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function parse(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $value, $m)) {
            return null;
        }

        [$lat, $lng] = [(float)$m[1], (float)$m[2]];

        return abs($lat) <= 90 && abs($lng) <= 180 ? $m[1] . ',' . $m[2] : null;
    }
}
