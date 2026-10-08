<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * Keeps stored select values that the current options no longer offer.
 *
 * A select without its value shows the first option, and the next save would
 * silently replace the stored value. Listing it under its own group keeps it
 * visible and saved as it is.
 *
 * @author WMD
 * @since 1.1.0
 */
final class SelectOptions
{
    /**
     * @param list<array<string,string>> $options Craft select options (`label`/`value`, or `optgroup`)
     * @param list<string> $values Stored values of this column
     * @param string $groupLabel Heading for the missing values
     * @return list<array<string,string>>
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function withMissing(array $options, array $values, string $groupLabel): array
    {
        $offered = array_column($options, 'value');
        $missing = array_values(array_unique(array_filter($values, static fn(string $v) => $v !== '' && !in_array($v, $offered, true))));

        if ($missing === []) {
            return $options;
        }

        $options[] = ['optgroup' => $groupLabel];
        foreach ($missing as $value) {
            $options[] = ['label' => $value, 'value' => $value];
        }

        return $options;
    }
}
