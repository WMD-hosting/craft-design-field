<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use Craft;
use craft\helpers\FileHelper;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\TemplateCheck;
use wmd\designfield\Plugin;
use yii\base\Component;

/**
 * Runs the template check (helpers/TemplateCheck) against the site's templates and
 * the configured profiles: which options no template reads, which template reads have
 * no option, and layout options without a template file.
 *
 * @author WMD
 * @since 1.0.0
 */
class TemplateChecker extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * @return list<array{kind:string,profile:string,group?:string,file?:string,layout?:string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function check(): array
    {
        $registry = Plugin::getInstance()->getGroups()->getRegistry();
        $config = Plugin::getInstance()->getSettings()->templateCheck + ['blocks' => '_blocks/{type}', 'everyBlock' => ['_blocks/_render'], 'code' => ['@root/modules']];
        $profiles = $this->_profiles($registry);
        $handles = [];
        foreach ($profiles as $profile) {
            $handles += array_fill_keys($profile['groups'], true);
        }

        return TemplateCheck::analyze($this->_templates(), $profiles, [
            'blocks' => (string)$config['blocks'],
            'everyBlock' => array_map('strval', (array)$config['everyBlock']),
            'readInCode' => $this->_codeReads(array_map('strval', (array)$config['code']), array_map('strval', array_keys($handles))),
        ]);
    }

    /**
     * Which plain fields each entry type's templates read, for the "Tidy up" tab's "not read
     * by any template yet" note: a block's own folder and what it includes, plus the site's
     * code. A type without a block folder (a header element, a tab item) is checked against
     * every template.
     *
     * @param array<string,list<string>> $byType Entry type handle => field handles
     * @return array<string,list<string>> Entry type handle => the handles read
     *
     * @author WMD
     * @since 1.0.0
     */
    public function fieldsRead(array $byType): array
    {
        $config = Plugin::getInstance()->getSettings()->templateCheck + ['blocks' => '_blocks/{type}', 'code' => ['@root/modules']];
        $files = $this->_templates();
        $result = [];

        foreach ($byType as $type => $handles) {
            $read = $this->_codeReads(array_map('strval', (array)$config['code']), $handles);
            $own = TemplateCheck::blockFiles($files, str_replace('{type}', (string)$type, (string)$config['blocks']));
            foreach ($own !== [] ? array_intersect_key($files, array_flip($own)) : $files as $source) {
                $read = array_merge($read, TemplateCheck::fieldReads($source, array_values(array_diff($handles, $read))));
            }
            $result[(string)$type] = array_values(array_unique($read));
        }

        return $result;
    }

    // Private Methods
    // =========================================================================

    /**
     * Option handles named in the site's PHP (Twig extensions, modules).
     *
     * @param list<string> $paths Folders, aliases allowed
     * @param list<string> $handles
     * @return list<string>
     */
    private function _codeReads(array $paths, array $handles): array
    {
        $found = [];

        foreach ($paths as $path) {
            $dir = Craft::getAlias($path, false);
            if (!is_string($dir) || !is_dir($dir)) {
                continue;
            }
            foreach (FileHelper::findFiles($dir, ['only' => ['*.php']]) as $file) {
                $found += array_fill_keys(TemplateCheck::codeReads((string)file_get_contents($file), $handles), true);
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * Every site template: path relative to the templates folder => source.
     *
     * @return array<string,string>
     */
    private function _templates(): array
    {
        $root = Craft::$app->getPath()->getSiteTemplatesPath();
        $files = [];

        foreach (FileHelper::findFiles($root, ['only' => ['*.twig'], 'except' => ['node_modules/']]) as $file) {
            $files[str_replace('\\', '/', substr($file, strlen($root) + 1))] = (string)file_get_contents($file);
        }
        ksort($files);

        return $files;
    }

    /**
     * Profiles that name an entry type, by that type: its option handles and layout keys.
     * A `field:type` profile counts for its type, merged with the type's own profile.
     *
     * @param Registry $registry
     * @return array<string,array{groups:list<string>,layouts:list<string>}>
     */
    private function _profiles(Registry $registry): array
    {
        $profiles = [];

        foreach ($registry->profiles as $name => $groups) {
            $type = str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name;
            if ($type === Registry::FALLBACK_PROFILE || Craft::$app->getEntries()->getEntryTypeByHandle($type) === null) {
                continue;
            }
            $layouts = isset($groups['variant']) ? array_map('strval', array_keys($groups['variant']->options)) : [];
            $profiles[$type] = [
                'groups' => array_values(array_unique(array_merge($profiles[$type]['groups'] ?? [], array_map('strval', array_keys($groups))))),
                'layouts' => array_values(array_unique(array_merge($profiles[$type]['layouts'] ?? [], $layouts))),
            ];
        }

        return $profiles;
    }
}
