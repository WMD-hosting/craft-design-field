<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use CraftCms\Cms\Entry\EntryTypes;
use Symfony\Component\Finder\Finder;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\TemplateCheck;

/**
 * Runs the template check (helpers/TemplateCheck) against the site's templates and
 * the configured profiles: which options no template reads, which template reads have
 * no option, and layout options without a template file.
 *
 * @author WMD
 * @since 1.0.0
 */
class TemplateChecker
{
    public function __construct(
        private readonly Groups $groups,
        private readonly EntryTypes $entryTypes,
    ) {
    }

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
        $registry = $this->groups->getRegistry();
        $config = $this->_config();
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
        $config = $this->_config();
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
     * The `templateCheck` config with its defaults: the site's templates folder
     * (`resources/views`, else `templates`), the block folder pattern, the templates
     * every block goes through, and the folders of PHP that read options.
     *
     * @return array{templates:string,blocks:string,everyBlock:list<string>,code:list<string>}
     */
    private function _config(): array
    {
        $config = (array)($this->groups->config()['templateCheck'] ?? []);

        return $config + [
            'templates' => is_dir(resource_path('views')) ? resource_path('views') : base_path('templates'),
            'blocks' => '_blocks/{type}',
            'everyBlock' => ['_blocks/_render'],
            'code' => ['app'],
        ];
    }

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
            $dir = str_starts_with($path, '/') ? $path : base_path($path);
            if (!is_dir($dir)) {
                continue;
            }
            foreach (Finder::create()->files()->in($dir)->name('*.php') as $file) {
                $found += array_fill_keys(TemplateCheck::codeReads($file->getContents(), $handles), true);
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
        $root = (string)$this->_config()['templates'];
        $files = [];

        if (!is_dir($root)) {
            return $files;
        }

        foreach (Finder::create()->files()->in($root)->name('*.twig')->exclude('node_modules') as $file) {
            $files[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
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
            if ($type === Registry::FALLBACK_PROFILE || $this->entryTypes->getEntryTypeByHandle($type) === null) {
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
