<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

/**
 * A ready prompt for an agent converting a site to Design Field: its option fields and
 * the groups they map to, how templates read options (Twig) or what GraphQL returns
 * (headless), what the template check found, and the commands that move the data.
 * Craft-free.
 *
 * @author WMD
 * @since 1.0.0
 */
class AgentBrief
{
    // Public Methods
    // =========================================================================

    /**
     * @param list<array{type:string,typeName:string,movable:bool,reason?:string,fields:list<array{field:string,name:string,kind:string,group:string,status:string,note:string,read?:bool}>}> $types TidyRows::build()
     * @param array{reading:array<string,string>} $schema Schema::build()
     * @param list<array{kind:string,profile:string,group?:string,file?:string,layout?:string}> $findings TemplateCheck::analyze()
     * @param string $stack 'twig' or 'graphql'
     * @param bool $configFile Options live in config/design-field.php (else in the settings page's tables)
     * @return string Markdown
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function build(array $types, array $schema, array $findings, string $stack, bool $configFile = true): string
    {
        $lines = [
            '# Move this site\'s block options into Design Field',
            '',
            'Design Field puts every design option of a block (layout, tone, spacing…) in one field, stored as named keys. The full option list is in `docs/design-field-schema.md` (`php craft design-field/schema --agents=docs/design-field-schema.md`).',
            '',
            '## Option fields to move',
            '',
        ];

        foreach ($types as $type) {
            $lines[] = "### {$type['typeName']} (`{$type['type']}`)";
            foreach ($type['fields'] as $field) {
                $line = "- `{$field['field']}` ({$field['kind']}) → group `{$field['group']}`" . (($field['read'] ?? true) ? '' : ' (not read by any template yet: unfinished or left over, ask before moving)');
                $lines[] = $line . match ($field['status']) {
                    'moves' => ', moves with adopt',
                    'clash' => ": clashes, {$field['note']}",
                    'skipped' => ": not moved, {$field['note']}",
                    default => ', new option (not in the configuration yet)',
                };
            }
            $where = $configFile ? 'merge into `config/design-field.php`' : 'add in the plugin settings\' Configuration tables (a config file would override them)';
            $lines[] = match ($type['reason'] ?? ($type['movable'] ? '' : 'no-profile')) {
                '' => "Move the data: `php craft design-field/adopt --types={$type['type']}` (dry run), then add `--apply`.",
                'clash' => 'Not movable yet: fix what the field\'s note says first (add the missing values to the option, or map the field to another option with `fieldMap`).',
                default => "Add the options first: `php craft design-field/import --types={$type['type']}` shows them; $where.",
            };
            $lines[] = '';
        }

        $lines[] = '## Templates';
        $lines[] = '';
        $kinds = array_unique(array_merge(...array_map(static fn(array $type) => array_column($type['fields'], 'kind'), $types ?: [['fields' => []]])));
        if (in_array('Lightswitch', $kinds, true)) {
            $lines[] = '- A moved Lightswitch is an on/off option: test `design.showDate.key == \'on\'`, not `design.showDate` (an option is always truthy).';
        }
        if (in_array('Color palette', $kinds, true)) {
            $lines[] = '- A moved Color field stores the hex without `#` as its key (`1e40af`); its swatch is in the option\'s config.';
        }
        if (array_intersect(['Lightswitch', 'Color palette'], $kinds) !== []) {
            $lines[] = '';
        }
        if ($stack === 'graphql') {
            $lines[] = 'This site is headless. The Design field returns the chosen key per group as a JSON string in GraphQL (`design`). Parse it once per block and map keys to your own classes or components; keys, not labels, are stable.';
        } else {
            $lines[] = 'Read options in the block template instead of the old fields, then remove the old field reads:';
            $lines[] = '';
            $lines[] = '```twig';
            $lines[] = $schema['reading']['get'];
            $lines[] = $schema['reading']['key'];
            $lines[] = '```';
        }
        $lines[] = '';

        if ($findings !== []) {
            $lines[] = '## Template check';
            $lines[] = '';
            foreach ($findings as $finding) {
                $lines[] = match ($finding['kind']) {
                    'missing' => "- {$finding['file']} reads `{$finding['group']}`, which {$finding['profile']} does not offer",
                    'dead' => "- `{$finding['group']}` on {$finding['profile']} is offered but no template reads it",
                    default => "- {$finding['kind']}: {$finding['profile']}" . (isset($finding['layout']) ? " / {$finding['layout']}" : ''),
                };
            }
            $lines[] = '';
        }

        $lines[] = 'When done, `php craft design-field/check` should report nothing, and the "Tidy up" tab should list no option fields.';

        return implode("\n", $lines);
    }
}
