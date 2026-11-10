<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use CraftCms\Cms\Entry\EntryTypes;
use CraftCms\Cms\Field\Fields;
use CraftCms\Cms\Field\Matrix;
use InvalidArgumentException;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\Registry;
use wmd\designfield\models\Group;

/**
 * Checks the configuration against this install: profiles named after a type
 * that is gone, fields set to a missing profile, preset choices no block has,
 * option pictures missing under the web root, and what the templates read.
 *
 * @author WMD
 * @since 1.0.0
 */
class Health
{
    // Const Properties
    // =========================================================================

    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    private const EXAMPLES = 6;

    public function __construct(
        private readonly Groups $groups,
        private readonly TemplateChecker $templateChecker,
        private readonly EntryTypes $entryTypes,
        private readonly Fields $fields,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * Runs every configuration check.
     *
     * @return list<array{level:string,message:string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function check(): array
    {
        // A configuration that does not build (unknown group, empty token file…) is the
        // one finding that matters; the other checks need a registry.
        try {
            $registry = $this->groups->getRegistry();
        } catch (InvalidArgumentException $e) {
            return [$this->_finding(self::ERROR, $e->getMessage())];
        }

        return array_merge(
            $this->_unknownProfiles($registry),
            $this->_fieldProfiles($registry),
            array_map(fn(string $message) => $this->_finding(self::ERROR, $message), $registry->invalidPresetChoices()),
            $this->_missingImages($registry),
            $this->_typesWithoutProfile($registry),
        );
    }

    /**
     * The template check as readable findings: options no template reads, template
     * reads of options a block lacks, and layout options without a template file.
     *
     * @return list<array{level:string,message:string,block:string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function templates(): array
    {
        try {
            $registry = $this->groups->getRegistry();
            $raw = $this->templateChecker->check();
        } catch (InvalidArgumentException) {
            return [];
        }

        $findings = [];
        $quiet = [];
        $dead = [];

        foreach ($raw as $finding) {
            $block = $this->entryTypes->getEntryTypeByHandle($finding['profile'])?->name ?? $finding['profile'];
            $groups = $registry->groupsFor($finding['profile']);
            $option = isset($finding['group']) ? ($groups[$finding['group']]->label ?? $registry->groups[$finding['group']]->label ?? $finding['group']) : '';

            // Unread options are grouped by option below: one line per option, listing its blocks.
            if ($finding['kind'] === 'dead') {
                $dead[$option][] = $block;
                continue;
            }

            $message = match ($finding['kind']) {
                'missing' => sprintf('%s reads “%s”, which %s does not offer: it always renders the default.', $finding['file'] ?? '', $finding['group'] ?? '', $block),
                'layout-without-file' => sprintf('%s: layout “%s” has no template file, so it renders the default layout.', $block, $finding['layout'] ?? ''),
                'file-without-layout' => sprintf('%s: template “%s” is not a layout option, so editors cannot pick it.', $block, $finding['layout'] ?? ''),
                default => null,
            };

            if ($message === null) {
                $quiet[] = $block;
                continue;
            }

            $findings[] = ['level' => $finding['kind'] === 'file-without-layout' ? self::INFO : self::WARNING, 'message' => $message, 'block' => $block];
        }

        $deadFindings = [];
        foreach ($dead as $option => $blocks) {
            $blocks = array_values(array_unique($blocks));
            $deadFindings[] = [
                'level' => self::WARNING,
                'message' => count($blocks) === 1
                    ? sprintf('“%s” is offered on %s, but no template reads it, so changing it does nothing. Remove it from the block, or use it in a template.', $option, $blocks[0])
                    : sprintf('“%s” is offered on %d blocks (%s), but none of their templates read it, so changing it does nothing. Remove it from those blocks, or use it in their templates.', $option, count($blocks), $this->_list($blocks)),
                'block' => implode(', ', $blocks),
            ];
        }
        $findings = array_merge($deadFindings, $findings);

        if ($quiet !== []) {
            $findings[] = ['level' => self::INFO, 'message' => sprintf('No templates of their own (rendered by another block, or outside the block folder): %s.', $this->_list(array_values(array_unique($quiet)))), 'block' => ''];
        }

        return $findings;
    }

    // Private Methods
    // =========================================================================

    /**
     * Profiles named after nothing on this install (a renamed or deleted type).
     *
     * @param Registry $registry
     * @return list<array{level:string,message:string}>
     */
    private function _unknownProfiles(Registry $registry): array
    {
        $known = $this->_knownProfiles();
        $unknown = array_values(array_filter($registry->profileNames(), static fn(string $name) => !isset($known[$name])));

        return $unknown === [] ? [] : [$this->_finding(self::ERROR, 'Profiles that match no entry type or Matrix entry type: ' . $this->_list($unknown))];
    }

    /**
     * Design fields set to a profile that does not exist.
     *
     * @param Registry $registry
     * @return list<array{level:string,message:string}>
     */
    private function _fieldProfiles(Registry $registry): array
    {
        $findings = [];

        foreach ($this->fields->getFieldsByType(Design::class) as $field) {
            if ($field instanceof Design && $field->profile !== '' && !isset($registry->profiles[$field->profile])) {
                $findings[] = $this->_finding(self::ERROR, sprintf('The field “%s” uses profile “%s”, which does not exist.', $field->name, $field->profile));
            }
        }

        return $findings;
    }

    /**
     * Option pictures (tiles) that point at a file missing under the web root.
     *
     * @param Registry $registry
     * @return list<array{level:string,message:string}>
     */
    private function _missingImages(Registry $registry): array
    {
        $missing = [];

        foreach ($this->_allGroups($registry) as $where => $group) {
            foreach ($group->options as $key => $option) {
                $image = $option['image'] ?? null;

                if ($image !== null && str_starts_with($image, '/') && !str_starts_with($image, '//') && !is_file(public_path((string)parse_url($image, PHP_URL_PATH)))) {
                    $missing[] = "$where: $key";
                }
            }
        }

        return $missing === [] ? [] : [$this->_finding(self::WARNING, 'Option pictures missing under the web root: ' . $this->_list($missing))];
    }

    /**
     * Entry types with a Design field and no profile of their own: they show the `*` groups.
     *
     * @param Registry $registry
     * @return list<array{level:string,message:string}>
     */
    private function _typesWithoutProfile(Registry $registry): array
    {
        $profiled = [];

        foreach ($registry->profileNames() as $name) {
            $profiled[str_contains($name, ':') ? substr($name, strpos($name, ':') + 1) : $name] = true;
        }

        $bare = [];

        foreach ($this->entryTypes->getAllEntryTypes() as $type) {
            if (isset($profiled[$type->handle])) {
                continue;
            }

            foreach ($type->getFieldLayout()->getCustomFields() as $field) {
                if ($field instanceof Design && $field->profile === '') {
                    $bare[] = "$type->name ($type->handle)";
                    break;
                }
            }
        }

        return $bare === [] ? [] : [$this->_finding(self::INFO, 'Entry types with a Design field but no profile of their own show the `*` groups: ' . $this->_list($bare))];
    }

    /**
     * Every group, shared ones by handle and profile-only ones as `profile/handle`.
     *
     * @param Registry $registry
     * @return array<string,Group>
     */
    private function _allGroups(Registry $registry): array
    {
        $groups = $registry->groups;

        foreach ($registry->profiles as $profile => $profileGroups) {
            foreach ($profileGroups as $handle => $group) {
                if (($registry->groups[$handle] ?? null) !== $group && !isset($groups[$handle])) {
                    $groups["$profile/$handle"] = $group;
                }
            }
        }

        return $groups;
    }

    /**
     * Names a profile can have on this install: `*`, every entry type, and
     * `{matrixField}:{type}` for the entry types of each Matrix field.
     *
     * @return array<string,true>
     */
    private function _knownProfiles(): array
    {
        $known = [Registry::FALLBACK_PROFILE => true];

        foreach ($this->entryTypes->getAllEntryTypes() as $type) {
            $known[$type->handle] = true;
        }

        foreach ($this->fields->getFieldsByType(Matrix::class) as $field) {
            foreach ($field->getEntryTypes() as $type) {
                $known["$field->handle:$type->handle"] = true;
            }
        }

        return $known;
    }

    /**
     * @param list<string> $items
     * @return string The first few, then "and N more"
     */
    private function _list(array $items): string
    {
        $shown = array_slice($items, 0, self::EXAMPLES);
        $more = count($items) - count($shown);

        return implode(', ', $shown) . ($more > 0 ? " and $more more" : '');
    }

    /**
     * @param string $level
     * @param string $message
     * @return array{level:string,message:string}
     */
    private function _finding(string $level, string $message): array
    {
        return ['level' => $level, 'message' => $message];
    }
}
