<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

/**
 * The "Tidy up" tab's table: option fields per block type and what each would become.
 * Craft-free.
 *
 * @author WMD
 * @since 1.0.0
 */
class TidyRows
{
    // Public Methods
    // =========================================================================

    /**
     * @param list<array{type:string,typeName:string,field:string,name:string,kind:string,planned:?string,read?:bool}> $fields
     *     `planned` is the option `design-field/adopt` would move the field into (its MigrationPlan), null when none;
     *     without `read`, a field counts as read
     * @param array{fieldMap?:array<string,string>,skipped?:array<string,string>} $import Importer::fromFields() result,
     *     with the registry's fieldMap merged in
     * @param string[] $profiles Profile names the registry has
     * @return list<array{type:string,typeName:string,movable:bool,reason:string,fields:list<array{field:string,name:string,kind:string,group:string,status:string,note:string,read:bool}>}>
     *     `status`: moves (adopt moves it), clash (adopt would move it but the Importer flags it: the option lacks
     *     some stored values, or is meant for something else),
     *     skipped, new (not in the config yet); `reason` why a type is not movable: no-profile, clash, nothing
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function build(array $fields, array $import, array $profiles): array
    {
        $types = [];

        foreach ($fields as $field) {
            $skipped = $import['skipped'][$field['field']] ?? null;
            $planned = $field['planned'];
            $types[$field['type']] ??= ['type' => $field['type'], 'typeName' => $field['typeName'], 'movable' => false, 'reason' => '', 'fields' => []];
            $types[$field['type']]['fields'][] = [
                'field' => $field['field'],
                'name' => $field['name'],
                'kind' => $field['kind'],
                'group' => $planned ?? ($import['fieldMap'][$field['field']] ?? $field['field']),
                'status' => match (true) {
                    $planned !== null && $skipped !== null => 'clash',
                    $planned !== null => 'moves',
                    $skipped !== null => 'skipped',
                    default => 'new',
                },
                'note' => (string)$skipped,
                // Not read by any template yet: not built, or left over. Never moved blindly.
                'read' => $field['read'] ?? true,
            ];
        }

        // Movable exactly when adopt would do what the rows say: its own profile, something
        // to move, and no field adopt would move into an option meant for something else.
        foreach ($types as $handle => $type) {
            $statuses = array_column($type['fields'], 'status');
            $types[$handle]['reason'] = match (true) {
                !in_array($handle, $profiles, true) => 'no-profile',
                in_array('clash', $statuses, true) => 'clash',
                !in_array('moves', $statuses, true) => 'nothing',
                default => '',
            };
            $types[$handle]['movable'] = $types[$handle]['reason'] === '';
        }

        uasort($types, static fn(array $a, array $b) => strcasecmp($a['typeName'], $b['typeName']));

        return array_values($types);
    }
}
