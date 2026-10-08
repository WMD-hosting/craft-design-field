<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use Craft;
use craft\helpers\UrlHelper;
use InvalidArgumentException;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\Registry;
use wmd\designfield\models\Group;
use wmd\designfield\Plugin;
use yii\base\Component;

/**
 * Checks the configuration against the install: what a typo or a renamed entry type
 * would otherwise only show as an odd Design panel.
 *
 * @author WMD
 * @since 1.0.0
 */
class Health extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * Breaks a panel or a save.
     */
    public const ERROR = 'error';

    /**
     * Works, but not as intended.
     */
    public const WARNING = 'warning';

    /**
     * Worth knowing.
     */
    public const INFO = 'info';

    /**
     * Examples listed per finding before "and N more".
     */
    private const EXAMPLES = 6;

    // Public Methods
    // =========================================================================

    /**
     * Runs every check.
     *
     * @return array<int,array{level:string,message:string,url:?string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function check(): array
    {
        // A configuration that does not build (unknown group, empty token file…) is the
        // one finding that matters; the other checks need a registry.
        try {
            $registry = Plugin::getInstance()->getGroups()->getRegistry();
        } catch (InvalidArgumentException $e) {
            return [$this->_finding(self::ERROR, $e->getMessage())];
        }

        return array_merge(
            $this->_unknownProfiles($registry),
            $this->_fieldProfiles($registry),
            $this->_presetChoices($registry),
            $this->_missingImages($registry),
            $this->_typesWithoutProfile($registry),
            $this->_inputStyles($registry),
        );
    }

    /**
     * The template check as readable findings: options no template reads, template
     * reads of options a block lacks, and layout options without a template file.
     *
     * @return array<int,array{level:string,message:string,url:?string,block:string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function templates(): array
    {
        try {
            $registry = Plugin::getInstance()->getGroups()->getRegistry();
            $raw = Plugin::getInstance()->getTemplateChecker()->check();
        } catch (InvalidArgumentException) {
            return [];
        }

        $findings = [];
        $quiet = [];
        $dead = [];

        foreach ($raw as $finding) {
            $type = Craft::$app->getEntries()->getEntryTypeByHandle($finding['profile']);
            $block = $type !== null ? $type->name : $finding['profile'];
            $url = $type !== null ? UrlHelper::cpUrl("settings/entry-types/$type->id") : null;
            $groups = $registry->groupsFor($finding['profile']);
            $option = isset($finding['group']) ? ($groups[$finding['group']]->label ?? $registry->groups[$finding['group']]->label ?? $finding['group']) : '';
            $params = ['block' => $block, 'option' => $option, 'handle' => $finding['group'] ?? '', 'file' => $finding['file'] ?? '', 'layout' => $finding['layout'] ?? ''];

            // Unread options are grouped by option below: one line per option, listing its blocks.
            if ($finding['kind'] === 'dead') {
                $dead[$option]['blocks'][] = $block;
                $dead[$option]['url'] ??= $url;
                continue;
            }

            $message = match ($finding['kind']) {
                'missing' => Craft::t('design-field', '{file} reads “{handle}”, which {block} does not offer: it always renders the default.', $params),
                'layout-without-file' => Craft::t('design-field', '{block}: layout “{layout}” has no template file, so it renders the default layout.', $params),
                'file-without-layout' => Craft::t('design-field', '{block}: template “{layout}” is not a layout option, so editors cannot pick it.', $params),
                default => null,
            };

            if ($message === null) {
                $quiet[] = $block;
                continue;
            }

            $level = $finding['kind'] === 'file-without-layout' ? self::INFO : self::WARNING;
            $findings[] = ['level' => $level, 'message' => $message, 'url' => $url, 'block' => $block];
        }

        $deadFindings = [];
        foreach ($dead as $option => $info) {
            $blocks = array_values(array_unique($info['blocks']));
            $deadFindings[] = ['level' => self::WARNING, 'message' => count($blocks) === 1
                ? Craft::t('design-field', '“{option}” is offered on {blocks}, but no template reads it, so changing it does nothing. Remove it from the block, or use it in a template.', ['option' => $option, 'blocks' => $blocks[0]])
                : Craft::t('design-field', '“{option}” is offered on {n} blocks ({blocks}), but none of their templates read it, so changing it does nothing. Remove it from those blocks, or use it in their templates.', ['option' => $option, 'n' => count($blocks), 'blocks' => $this->_list($blocks)]),
                'url' => count($blocks) === 1 ? $info['url'] : null, 'block' => implode(', ', $blocks), ];
        }
        $findings = array_merge($deadFindings, $findings);

        if ($quiet !== []) {
            $findings[] = ['level' => self::INFO, 'message' => Craft::t('design-field', 'No templates of their own (rendered by another block, or outside the block folder): {blocks}.', ['blocks' => $this->_list($quiet)]), 'url' => null, 'block' => ''];
        }

        return $findings;
    }

    // Private Methods
    // =========================================================================

    /**
     * Profiles named after nothing on this install (a renamed or deleted type).
     *
     * @param Registry $registry
     * @return array<int,array{level:string,message:string,url:?string}>
     */
    private function _unknownProfiles(Registry $registry): array
    {
        $known = $this->_knownProfiles();
        $unknown = array_values(array_filter($registry->profileNames(), static fn(string $name) => !isset($known[$name])));

        return $unknown === [] ? [] : [$this->_finding(self::ERROR, Craft::t('design-field', 'Profiles that match no entry type, Matrix entry type, product type or category group: {list}', ['list' => $this->_list($unknown)]))];
    }

    /**
     * Design fields set to a profile that does not exist.
     *
     * @param Registry $registry
     * @return array<int,array{level:string,message:string,url:?string}>
     */
    private function _fieldProfiles(Registry $registry): array
    {
        $findings = [];

        foreach (Craft::$app->getFields()->getFieldsByType(Design::class) as $field) {
            if ($field instanceof Design && $field->profile !== '' && !isset($registry->profiles[$field->profile])) {
                $findings[] = $this->_finding(
                    self::ERROR,
                    Craft::t('design-field', 'The field “{field}” uses profile “{profile}”, which does not exist.', ['field' => $field->name, 'profile' => $field->profile]),
                    UrlHelper::cpUrl("settings/fields/edit/$field->id"),
                );
            }
        }

        return $findings;
    }

    /**
     * Preset choices no block can use (the presets table is checked on save; config files are not).
     *
     * @param Registry $registry
     * @return array<int,array{level:string,message:string,url:?string}>
     */
    private function _presetChoices(Registry $registry): array
    {
        return array_map(fn(string $message) => $this->_finding(self::ERROR, $message), $registry->invalidPresetChoices());
    }

    /**
     * Option pictures (tiles) that point at a file missing under the web root.
     *
     * @param Registry $registry
     * @return array<int,array{level:string,message:string,url:?string}>
     */
    private function _missingImages(Registry $registry): array
    {
        $webroot = Craft::getAlias('@webroot');
        $missing = [];

        foreach ($this->_allGroups($registry) as $where => $group) {
            foreach ($group->options as $key => $option) {
                $image = $option['image'] ?? null;

                if ($image !== null && str_starts_with($image, '/') && !str_starts_with($image, '//') && !is_file($webroot . parse_url($image, PHP_URL_PATH))) {
                    $missing[] = "$where: $key";
                }
            }
        }

        return $missing === [] ? [] : [$this->_finding(self::WARNING, Craft::t('design-field', 'Option pictures missing under the web root: {list}', ['list' => $this->_list($missing)]))];
    }

    /**
     * Entry types with a Design field and no profile of their own: they show the `*` groups.
     *
     * @param Registry $registry
     * @return array<int,array{level:string,message:string,url:?string}>
     */
    private function _typesWithoutProfile(Registry $registry): array
    {
        $profiled = [];

        foreach ($registry->profileNames() as $name) {
            $profiled[str_contains($name, ':') ? substr($name, strpos($name, ':') + 1) : $name] = true;
        }

        $bare = [];

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            $designFields = array_filter($type->getFieldLayout()->getCustomFields(), static fn($field) => $field instanceof Design);

            if ($designFields === [] || isset($profiled[$type->handle])) {
                continue;
            }

            foreach ($designFields as $field) {
                /** @var Design $field */
                if ($field->profile === '') {
                    $bare[] = "$type->name ($type->handle)";
                    continue 2;
                }
            }
        }

        if ($bare === []) {
            return [];
        }

        return [$this->_finding(
            self::INFO,
            Craft::t('design-field', 'Entry types with a Design field but no profile of their own show the `*` groups: {list}', ['list' => $this->_list($bare)]),
        )];
    }

    /**
     * Styles picked on the settings page that some blocks cannot take, that replace a
     * style set in the config file, or that point at a removed block or group.
     *
     * @param Registry $registry
     * @return array<int,array{level:string,message:string,url:?string}>
     */
    private function _inputStyles(Registry $registry): array
    {
        $grouped = [];

        foreach ($registry->inputNotes as $note) {
            $key = implode('|', [$note['kind'], $note['scope'], $note['group'], $note['input'], $note['config'] ?? '', $note['from'] ?? '']);
            $grouped[$key]['note'] = $note;
            $grouped[$key]['profiles'][] = $note['profile'];
        }

        $findings = [];

        foreach ($grouped as ['note' => $note, 'profiles' => $profiles]) {
            $params = ['group' => $note['group'], 'input' => $note['input'], 'config' => $note['config'] ?? '', 'from' => $note['from'] ?? '', 'blocks' => $this->_list(array_unique($profiles))];

            // A site-wide swap is meant to skip what it cannot fit: worth knowing, not a problem.
            if ($note['scope'] === 'styles') {
                $findings[] = $this->_finding(self::INFO, Craft::t('design-field', 'Site-wide style {from} → {input} skips "{group}" on {blocks}: it cannot be shown that way there.', $params));
                continue;
            }

            $findings[] = match ($note['kind']) {
                'unsupported' => $this->_finding(self::WARNING, Craft::t('design-field', '"{group}" is set to {input} on the settings page, but {blocks} cannot show it that way and keep their own style.', $params)),
                'hidden' => $this->_finding(self::INFO, Craft::t('design-field', '"{group}": {input} from the settings page replaces {config} from config/design-field.php on {blocks}.', $params)),
                default => $this->_finding(self::WARNING, $note['group'] === ''
                    ? Craft::t('design-field', 'Styles on the settings page for {blocks}, which is no longer a profile. Save the settings to drop them.', $params)
                    : Craft::t('design-field', 'A style on the settings page for "{group}", which {blocks} no longer shows. Save the settings to drop it.', ['blocks' => $note['scope'] === 'groups' ? Craft::t('design-field', 'no block') : $params['blocks']] + $params)),
            };
        }

        return $findings;
    }

    /**
     * Shared groups and every profile's own groups, keyed for messages.
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
     * Profile names that match something on this install.
     *
     * @return array<string,bool>
     */
    private function _knownProfiles(): array
    {
        $known = [];

        foreach (Plugin::getInstance()->getGroups()->profileOptions([], false) as $option) {
            if (isset($option['value'])) {
                $known[$option['value']] = true;
            }
        }

        return $known;
    }

    /**
     * @param string[] $items
     * @return string
     */
    private function _list(array $items): string
    {
        $shown = array_slice($items, 0, self::EXAMPLES);
        $more = count($items) - count($shown);

        return implode(', ', $shown) . ($more > 0 ? ' ' . Craft::t('design-field', 'and {n} more', ['n' => $more]) : '');
    }

    /**
     * @param string $level
     * @param string $message
     * @param ?string $url
     * @return array{level:string,message:string,url:?string}
     */
    private function _finding(string $level, string $message, ?string $url = null): array
    {
        return ['level' => $level, 'message' => $message, 'url' => $url];
    }
}
