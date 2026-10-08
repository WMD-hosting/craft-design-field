<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

/**
 * Compares what block templates read from the Design field with what each profile
 * offers: options no template reads (editors change them and nothing happens),
 * template reads of options the profile lacks (always the default), and layout
 * options without a template file, or template files no layout reaches.
 *
 * Craft-free: works on a map of template path => source, so it can be unit tested.
 *
 * @author WMD
 * @since 1.0.0
 */
class TemplateCheck
{
    // Const Properties
    // =========================================================================

    /**
     * DesignValue methods, not option handles, when they follow a design variable.
     */
    private const METHODS = ['has', 'get', 'classes', 'keys', 'groups', 'toArray', 'invalid', 'changes'];

    /**
     * `craft.designField.of(...)` with up to one level of nested parentheses in its arguments.
     */
    private const OF = 'craft\.designField\.of\(((?:[^()]|\([^()]*\))*)\)';

    // Public Methods
    // =========================================================================

    /**
     * Option handles a template reads for its own block (`block`), sorted.
     *
     * Reads through a variable set from `craft.designField.of(block)` or named like
     * `design` / `cardDesign`, directly on `craft.designField.of(block)`, or on
     * `block.design`. A read through `of(x, 'otherProfile')` belongs to that other
     * profile and is left out; reads on other elements are otherReads().
     *
     * @param string $twig
     * @return list<string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function reads(string $twig): array
    {
        return self::_scan($twig)['own'];
    }

    /**
     * Option handles a template reads on other elements than its block, such as a
     * header reading `craft.designField.of(el).headerElementAlign` for each of its
     * elements, or `item.design.x`. They cannot be tied to a profile, so they keep the
     * option from counting as unread anywhere and never count as missing.
     *
     * @param string $twig
     * @return list<string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function otherReads(string $twig): array
    {
        return self::_scan($twig)['other'];
    }

    /**
     * Option handles read for a named profile: `craft.designField.of(hb, 'blockHeading').x`
     * reads `x` for blockHeading, wherever the template is.
     *
     * @param string $twig
     * @return array<string,list<string>> Profile => handles
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function namedReads(string $twig): array
    {
        return self::_scan($twig)['named'];
    }

    /**
     * Template paths a template pulls in by a fixed name (include, embed, extends,
     * import, from, the include() function), sorted.
     *
     * @param string $twig
     * @return list<string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function includes(string $twig): array
    {
        preg_match_all('/\{%-?\s*(?:include|embed|extends|import|from)\s+[\'"]([^\'"]+)[\'"](?!\s*~)/', $twig, $tags);
        preg_match_all('/\binclude\(\s*[\'"]([^\'"]+)[\'"](?!\s*~)\s*[,)]/', $twig, $calls);
        $paths = array_values(array_unique(array_merge($tags[1], $calls[1])));
        sort($paths);

        return $paths;
    }

    /**
     * How many includes build their path at runtime (not followed).
     *
     * @param string $twig
     * @return int
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function dynamicIncludes(string $twig): int
    {
        return preg_match_all('/\{%-?\s*(?:include|embed|extends)\s+(?:[^\'"\s%]|[\'"][^\'"]*[\'"]\s*~)/', $twig)
            + preg_match_all('/\binclude\(\s*(?:[^\'"\s)]|[\'"][^\'"]*[\'"]\s*~)/', $twig);
    }

    /**
     * Option handles a PHP file names as a quoted string ('autoplay', "speed"): sites
     * often read options in Twig extensions or modules, not in templates. A name, not a
     * proof of use, so it only keeps an option from counting as unread.
     *
     * @param string $php
     * @param list<string> $handles The option handles to look for
     * @return list<string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function codeReads(string $php, array $handles): array
    {
        preg_match_all('/[\'"](\w+)[\'"]/', $php, $m);
        $quoted = array_fill_keys($m[1], true);

        return array_values(array_filter($handles, static fn(string $handle) => isset($quoted[$handle])));
    }

    /**
     * Plain field handles a template reads: `block.showDate`, `block['showDate']`,
     * `getFieldByHandle('showDate')`. A query method (`.orderBy(…)`) and Twig comments
     * are not reads.
     *
     * @param string $twig
     * @param string[] $handles
     * @return string[] The handles read, sorted
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function fieldReads(string $twig, array $handles): array
    {
        $twig = (string)preg_replace('/\{#.*?#\}/s', '', $twig);
        $read = array_filter($handles, static function(string $handle) use ($twig): bool {
            $h = preg_quote($handle, '/');

            $quoted = '[\'"]' . $h . '[\'"]';

            return (bool)preg_match('/\.' . $h . '\b(?!\s*\()|\[\s*' . $quoted . '\s*\]|getFieldByHandle\(\s*' . $quoted . '/', $twig);
        });
        sort($read);

        return array_values($read);
    }

    /**
     * A block's own templates (in its folder) and every template they include, sorted.
     *
     * @param array<string,string> $files Template path (relative, with .twig) => source
     * @param string $folder The block's folder, e.g. `_blocks/blockCta`
     * @return list<string>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function blockFiles(array $files, string $folder): array
    {
        $folder = rtrim($folder, '/');
        $queue = array_values(array_filter(array_keys($files), static fn(string $path) => str_starts_with($path, "$folder/") || $path === "$folder.twig"));
        $seen = [];

        while ($queue !== []) {
            $file = array_shift($queue);
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            foreach (self::includes($files[$file]) as $path) {
                $path = ltrim($path, '/');
                foreach ([$path, "$path.twig", "$path/index.twig"] as $candidate) {
                    if (isset($files[$candidate])) {
                        $queue[] = $candidate;
                        break;
                    }
                }
            }
        }

        $list = array_keys($seen);
        sort($list);

        return $list;
    }

    /**
     * Findings for every profile.
     *
     * @param array<string,string> $files Template path (relative, with .twig) => source
     * @param array<string,array{groups:list<string>,layouts:list<string>}> $profiles Profile (entry type handle) => its option handles and layout keys
     * @param array{blocks:string,everyBlock:list<string>,readInCode?:list<string>} $config `blocks` is the folder of a block's templates with `{type}`; `everyBlock` templates render for every block; `readInCode` options are read in PHP (codeReads())
     * @return list<array{kind:string,profile:string,group?:string,file?:string,layout?:string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function analyze(array $files, array $profiles, array $config): array
    {
        $resolve = static function(string $path) use ($files): ?string {
            $path = ltrim($path, '/');
            foreach ([$path, "$path.twig", "$path/index.twig"] as $candidate) {
                if (isset($files[$candidate])) {
                    return $candidate;
                }
            }
            return null;
        };

        $reads = [];
        $reach = static function(array $start) use ($files, $resolve, &$reads): array {
            $seen = [];
            $queue = $start;
            while ($queue !== []) {
                $file = array_shift($queue);
                if ($file === null || isset($seen[$file])) {
                    continue;
                }
                $seen[$file] = true;
                $reads[$file] ??= self::reads($files[$file]);
                foreach (self::includes($files[$file]) as $path) {
                    $queue[] = $resolve($path);
                }
            }
            return array_keys($seen);
        };

        $everyBlock = $reach(array_map($resolve, $config['everyBlock']));
        $readAnywhere = array_fill_keys($config['readInCode'] ?? [], true);
        // Read on nested elements somewhere (a header for its items): read for every profile.
        $readFor = [];
        foreach ($files as $source) {
            $scan = self::_scan($source);
            $readAnywhere += array_fill_keys($scan['other'], true);
            foreach ($scan['named'] as $profile => $handles) {
                $readFor[$profile] = ($readFor[$profile] ?? []) + array_fill_keys($handles, true);
            }
        }
        $findings = [];

        foreach ($profiles as $profile => $info) {
            $folder = str_replace('{type}', $profile, rtrim($config['blocks'], '/'));
            $own = [];
            foreach (array_keys($files) as $path) {
                if (str_starts_with($path, "$folder/") || $path === "$folder.twig") {
                    $own[] = $path;
                }
            }

            if ($own === []) {
                $findings[] = ['kind' => 'no-templates', 'profile' => $profile];
                continue;
            }

            $read = $readAnywhere + ($readFor[$profile] ?? []);
            foreach (array_merge($reach($own), $everyBlock) as $file) {
                $read += array_fill_keys($reads[$file] ?? [], true);
            }

            foreach ($info['groups'] as $group) {
                if (!isset($read[$group])) {
                    $findings[] = ['kind' => 'dead', 'profile' => $profile, 'group' => $group];
                }
            }

            // Missing: only the block's own files (shared partials read safely on any block).
            foreach ($own as $file) {
                foreach ($reads[$file] ?? [] as $group) {
                    if (!in_array($group, $info['groups'], true)) {
                        $findings[] = ['kind' => 'missing', 'profile' => $profile, 'group' => $group, 'file' => $file];
                    }
                }
            }

            // Layouts render `{folder}/{key}.twig`; `_default` is the fallback, `_*` files are partials.
            if ($info['layouts'] !== []) {
                $layoutFiles = [];
                foreach ($own as $file) {
                    if (str_starts_with($file, "$folder/") && !str_contains(substr($file, strlen($folder) + 1), '/')) {
                        $layoutFiles[] = basename($file, '.twig');
                    }
                }
                foreach ($info['layouts'] as $layout) {
                    if ($layout !== '_default' && $layout !== 'auto' && !in_array($layout, $layoutFiles, true)) {
                        $findings[] = ['kind' => 'layout-without-file', 'profile' => $profile, 'layout' => $layout];
                    }
                }
                foreach ($layoutFiles as $layout) {
                    if (!str_starts_with($layout, '_') && !in_array($layout, $info['layouts'], true)) {
                        $findings[] = ['kind' => 'file-without-layout', 'profile' => $profile, 'layout' => $layout];
                    }
                }
            }
        }

        return $findings;
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads on the template's own block and on other elements.
     *
     * @param string $twig
     * @return array{own:list<string>,other:list<string>,named:array<string,list<string>>}
     */
    private static function _scan(string $twig): array
    {
        $found = ['own' => [], 'other' => [], 'named' => []];
        $add = static function(string $where, string $handle) use (&$found) {
            if (in_array($handle, self::METHODS, true)) {
                return;
            }
            if (str_starts_with($where, 'named:')) {
                $found['named'][substr($where, 6)][$handle] = true;
                return;
            }
            $found[$where][$handle] = true;
        };
        // `of(block…)` is this block (a profile named next to it changes nothing);
        // `of(el, 'profile')` reads for that profile; `of(el)`, `of(card)`… another element.
        $whose = static function(string $arguments): string {
            if (preg_match('/^\s*block\s*(?:,|$)/', $arguments)) {
                return 'own';
            }
            return preg_match('/,\s*[\'"]([\w:*]+)[\'"]\s*$/', $arguments, $profile) ? 'named:' . $profile[1] : 'other';
        };

        $variables = [];
        preg_match_all('/\{%-?\s*set\s+(\w+)\s*=\s*' . self::OF . '\s*-?%\}/', $twig, $sets, PREG_SET_ORDER);
        foreach ($sets as $set) {
            $variables[$set[1]] = $whose($set[2]);
        }
        // Passed in from elsewhere (`design`, `cardDesign`…): taken as the block's.
        preg_match_all('/\b(_?[a-zA-Z]*[dD]esign)\b(?=\s*[.\[])/', $twig, $named);
        foreach ($named[1] as $name) {
            $variables[$name] ??= 'own';
        }

        foreach ($variables as $variable => $where) {
            $v = preg_quote($variable, '/');
            foreach (['/(?<![\w.])' . $v . '\.(\w+)/', '/(?<![\w.])' . $v . '\.(?:has|get)\(\s*[\'"](\w+)[\'"]/', '/(?<![\w.])' . $v . '\[\s*[\'"](\w+)[\'"]\s*\]/'] as $pattern) {
                preg_match_all($pattern, $twig, $m);
                foreach ($m[1] as $handle) {
                    $add($where, $handle);
                }
            }
            preg_match_all('/(?<![\w.])' . $v . '\.classes\(([^)]*)\)/', $twig, $m);
            foreach ($m[1] as $list) {
                preg_match_all('/[\'"](\w+)[\'"]/', $list, $names);
                foreach ($names[1] as $handle) {
                    $add($where, $handle);
                }
            }
        }

        preg_match_all('/' . self::OF . '\.(\w+)(?:\(\s*[\'"](\w+)[\'"])?/', $twig, $direct, PREG_SET_ORDER);
        foreach ($direct as $read) {
            $where = $whose($read[1]);
            $handle = in_array($read[2], ['has', 'get'], true) ? ($read[3] ?? null) : $read[2];
            if ($handle !== null) {
                $add($where, $handle);
            }
        }

        preg_match_all('/\b(\w+)\.design\.(\w+)(?:\(\s*[\'"](\w+)[\'"])?/', $twig, $field, PREG_SET_ORDER);
        foreach ($field as $read) {
            $handle = in_array($read[2], ['has', 'get'], true) ? ($read[3] ?? null) : $read[2];
            if ($handle !== null) {
                $add($read[1] === 'block' ? 'own' : 'other', $handle);
            }
        }

        $sorted = static function(array $set): array {
            $handles = array_map('strval', array_keys($set));
            sort($handles);
            return $handles;
        };
        $named = array_map($sorted, $found['named']);
        ksort($named);

        return ['own' => $sorted($found['own']), 'other' => $sorted($found['other']), 'named' => $named];
    }
}
