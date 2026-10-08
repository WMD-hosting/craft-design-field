<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

use wmd\designfield\models\Group;

/**
 * Counts which choices editors actually make, per group, so options nobody
 * changes can be dropped or hardcoded and defaults can follow real use.
 * Craft-free, so it is unit tested.
 *
 * @author WMD
 * @since 1.0.0
 */
class UsageReport
{
    // Const Properties
    // =========================================================================

    /**
     * Every entry is on the group's default.
     */
    public const NEVER_CHANGED = 'never-changed';

    /**
     * Every entry picked the same choice, and it is not the default.
     */
    public const ONE_VALUE = 'one-value';

    /**
     * Editors use more than one choice.
     */
    public const USED = 'used';

    // Public Methods
    // =========================================================================

    /**
     * @param array<string,Group> $groups Group definitions by handle
     * @param array<int,array<string,string>> $entries One map of group handle => resolved key per entry
     * @return array<string,array{label:string,default:string,entries:int,changed:int,counts:array<string,int>,unused:string[],verdict:string,value:?string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function analyze(array $groups, array $entries): array
    {
        $report = [];

        foreach ($groups as $handle => $group) {
            $counts = [];
            $total = 0;

            foreach ($entries as $keys) {
                if (!isset($keys[$handle])) {
                    continue;
                }
                $counts[$keys[$handle]] = ($counts[$keys[$handle]] ?? 0) + 1;
                $total++;
            }

            if ($total === 0) {
                continue;
            }

            arsort($counts);
            $changed = $total - ($counts[$group->default] ?? 0);
            $only = count($counts) === 1 ? (string)array_key_first($counts) : null;

            $verdict = match (true) {
                $changed === 0 => self::NEVER_CHANGED,
                $only !== null => self::ONE_VALUE,
                default => self::USED,
            };

            $unused = array_values(array_filter(
                array_map('strval', array_keys($group->options)),
                static fn(string $key) => !isset($counts[$key]) && $key !== $group->default && $key !== Group::AUTO,
            ));

            $report[$handle] = [
                'label' => $group->label,
                'default' => $group->default,
                'entries' => $total,
                'changed' => $changed,
                'counts' => $counts,
                'unused' => $unused,
                'verdict' => $verdict,
                'value' => $verdict === self::ONE_VALUE ? $only : null,
            ];
        }

        return $report;
    }

    /**
     * Turns per-type reports (Usage::report()) into findings, shared by the console
     * command and the settings page.
     *
     * Per type: groups never changed from their default, groups where every entry
     * picked the same choice, a layout list where only the default is used, and
     * (with `$all`) groups in normal use. Site-wide: groups no editor changed in any
     * of the blocks that show them.
     *
     * @param array<string,array{entries:int,groups:array<string,array<string,mixed>>}> $report
     * @param bool $all Also list groups in normal use
     * @return array{types:array<string,array{entries:int,findings:array<int,array{handle:string,label:string,verdict:string,text:string,unused:string[],counts:array<string,int>,default:string,value:?string}>}>,nowhere:array<int,array{label:string,types:int,entries:int}>,neverChanged:int,oneValue:int}
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function summarize(array $report, bool $all = false): array
    {
        $types = [];
        $site = [];
        $neverChanged = 0;
        $oneValue = 0;

        foreach ($report as $type => $data) {
            $findings = [];

            foreach ($data['groups'] as $handle => $group) {
                $site[$handle]['label'] = $group['label'];
                $site[$handle]['entries'] = ($site[$handle]['entries'] ?? 0) + $group['entries'];
                $site[$handle]['changed'] = ($site[$handle]['changed'] ?? 0) + $group['changed'];
                $site[$handle]['types'] = ($site[$handle]['types'] ?? 0) + 1;

                $text = match (true) {
                    $handle === 'variant' && $group['verdict'] === self::NEVER_CHANGED => 'only the default layout is used',
                    $group['verdict'] === self::NEVER_CHANGED => "never changed from \"{$group['default']}\": drop it from this block, or hardcode it",
                    $group['verdict'] === self::ONE_VALUE => "always \"{$group['value']}\" ({$group['entries']} of {$group['entries']}): make it the default?",
                    $all => 'used: ' . implode(', ', array_map(static fn($key, $n) => "$key $n", array_keys($group['counts']), $group['counts'])),
                    default => null,
                };

                if ($text === null) {
                    continue;
                }

                $neverChanged += $group['verdict'] === self::NEVER_CHANGED ? 1 : 0;
                $oneValue += $group['verdict'] === self::ONE_VALUE ? 1 : 0;
                $findings[] = [
                    'handle' => (string)$handle,
                    'label' => $group['label'],
                    'verdict' => $group['verdict'],
                    'text' => $text,
                    // Unpicked choices matter for a layout list; for other groups only on request.
                    'unused' => $handle === 'variant' || $all ? $group['unused'] : [],
                    // The facts behind the text, for the settings page (counts per choice).
                    'counts' => $group['counts'],
                    'default' => $group['default'],
                    'value' => $group['value'],
                ];
            }

            if ($findings !== []) {
                $types[(string)$type] = ['entries' => $data['entries'], 'findings' => $findings];
            }
        }

        $nowhere = [];
        foreach ($site as $group) {
            if ($group['changed'] === 0 && $group['types'] > 1) {
                $nowhere[] = ['label' => $group['label'], 'types' => $group['types'], 'entries' => $group['entries']];
            }
        }

        return ['types' => $types, 'nowhere' => $nowhere, 'neverChanged' => $neverChanged, 'oneValue' => $oneValue];
    }
}
