<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * Reads which slots a block template offers: a slot exists exactly where the
 * template calls `craft.entryFields.render(…, '<slot>')`. No hand-kept list.
 *
 * @author WMD
 * @since 1.1.0
 */
final class SlotScanner
{
    private const CALL = '/craft\.entryFields\.render\(\s*[^,()]+,\s*[^,()]+,\s*([\'"])([A-Za-z0-9_-]+)\1\s*\)/';

    /**
     * @param string $twig
     * @return list<string>
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function scan(string $twig): array
    {
        preg_match_all(self::CALL, $twig, $matches);

        return array_values(array_unique($matches[2]));
    }
}
