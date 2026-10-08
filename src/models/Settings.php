<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\models;

use craft\base\Model;
use craft\helpers\Json;
use InvalidArgumentException;
use wmd\designfield\helpers\InputOverrides;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\SettingsRows;
use wmd\designfield\Plugin;

/**
 * Plugin settings: the three tables edited in the control panel, or the
 * `groups` / `profiles` arrays from `config/design-field.php`, which win.
 *
 * @author WMD
 * @since 1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var array<int|string,array<string,mixed>> Group table rows
     */
    public array $groupRows = [];

    /**
     * @var array<int|string,array<string,mixed>> Option table rows
     */
    public array $optionRows = [];

    /**
     * @var array<int|string,array<string,mixed>> Profile table rows
     */
    public array $profileRows = [];

    /**
     * @var array<int,array<string,string>> Presets table rows: profile, name, choices (`group=key, group=key`)
     */
    public array $presetRows = [];

    /**
     * @var ?array<string,mixed> Groups from `config/design-field.php`; overrides the tables
     */
    public ?array $groups = null;

    /**
     * @var ?array<string,mixed> Profiles from `config/design-field.php`; overrides the tables
     */
    public ?array $profiles = null;

    /**
     * @var array<string,string> Old option field handle => group handle, from `config/design-field.php`
     */
    public array $fieldMap = [];

    /**
     * @var array<string,array<string,mixed>> Group handle => settings changed everywhere, from `config/design-field.php`
     */
    public array $groupOverrides = [];

    /**
     * @var array<string,array<string,array<string,mixed>>> Profile => group => settings changed for that profile, from `config/design-field.php`
     */
    public array $profileOverrides = [];

    /**
     * @var ?array<string,array<string,array<string,string>>> Profile => preset label => group => key, from `config/design-field.php`; null uses the presets table
     */
    public ?array $presets = null;

    /**
     * @var array<string,array<string,mixed>> Entry Fields presets: name => label and rows, from `config/design-field.php`
     */
    public array $entryFieldPresets = [];

    /**
     * @var list<string> Block types whose Entry Fields offer only their owner's fields (or the owner's target section)
     */
    public array $entryFieldOwnerBlocks = ['blockContent', 'blockProductDetail'];

    /**
     * @var string Handle of the section field on layout entries that names the section they drive; '' to ignore
     */
    public string $entryFieldTargetSectionField = 'targetSection';

    /**
     * @var string Handle of a block's own section field (the Entry List's Section): while sections are
     *             ticked there, the block's Field dropdown offers only their fields; '' to ignore
     */
    public string $entryFieldSectionField = 'sectionRef';

    /**
     * @var string Handle of the section whose entries rows with format `element` can render
     */
    public string $entryFieldElementSection = 'globalElements';

    /**
     * @var array<string,array{label?:string, fits?:list<string>}> The developer's own Entry Fields formats:
     * name => label and the field types it fits (`Assets`, `Number`, or a full class name). Each renders
     * `templates/_atoms/entry-field/custom/{name}.twig` with `value`, `entry`, `row`, `field`, `block`.
     */
    public array $entryFieldCustomFormats = [];

    /**
     * @var array{styles:array<string,string>,groups:array<string,array{input:string,iconsOnly:bool,textOnly?:bool}>,profiles:array<string,array<string,array{input:string,iconsOnly:bool,textOnly?:bool}>>,meta:array<string,array<string,array{label?:string,icon?:string}>>,sections:array<string,string>,defaults:array<string,array<string,string>>,hidden:array<string,array<string,string[]>>}
     * Looks picked on the settings page: a style swapped site-wide, one option on every block, one
     * option on one block, other labels or icons for options, section headings, and from the Tidy
     * up tab a block's own default and the choices its picker leaves out. They win over the config file.
     */
    public array $inputs = ['styles' => [], 'groups' => [], 'profiles' => [], 'meta' => [], 'sections' => [], 'defaults' => [], 'hidden' => []];

    /**
     * @var string `inputs` as posted by the settings page (JSON), read on validate; never saved
     */
    public string $inputsJson = '';

    /**
     * @var string Live block preview on the settings page: a URL with `{type}` (entry type handle)
     *             and `{design}` (the panel's keys as JSON); from `config/design-field.php`, '' for none
     */
    public string $blockPreviewUrl = '';

    /**
     * @var string Where layout pictures live, under the web root, with `{type}` (entry type handle) and
     *             `{key}` (layout key). A layout without a picture in the config gets the file found here;
     *             "Make tile pictures" on the settings page writes them. '' turns it off.
     */
    public string $tileImages = '/design-field/{type}/{key}.jpg';

    /**
     * @var string CSS selector of things on the preview page that never belong in a tile picture,
     *             such as a dev toolbar or an accessibility badge; '' for none
     */
    public string $tileSkip = '';

    /**
     * @var string The site's stylesheet, for Rendered tiles: they are drawn with it, so a corner radius
     *             or shadow shows the brand's own value. A URL, alias or env var; '' falls back to buttons.
     */
    public string $previewStylesheet = '';

    /**
     * @var array<string,string> Attributes on the root the tiles are drawn in, e.g. the brand the
     *      stylesheet themes: `['data-brand' => 'default']`
     */
    public array $previewAttributes = [];

    /**
     * @var array<string,string> Option handle => section heading in the panel (Layout, Colour…), for every
     *      option with that handle, including a block's own lists; from `config/design-field.php`. The
     *      settings page's sections win.
     */
    public array $sections = [];

    /**
     * @var array{blocks?:string,everyBlock?:list<string>,code?:list<string>} Template check: `blocks` is a
     *      block's template folder with `{type}` (default `_blocks/{type}`); `everyBlock` lists templates
     *      rendered for every block, such as the dispatcher (default `_blocks/_render`); `code` lists PHP
     *      folders that read options too (default `@root/modules`). From `config/design-field.php`.
     */
    public array $templateCheck = [];

    // Public Methods
    // =========================================================================

    /**
     * Whether `config/design-field.php` defines the groups and profiles.
     *
     * @return bool
     *
     * @author WMD
     * @since 1.0.0
     */
    public function isOverridden(): bool
    {
        return $this->groups !== null || $this->profiles !== null;
    }

    /**
     * Whether `config/design-field.php` defines the presets, so the presets table is not used.
     *
     * @return bool
     *
     * @author WMD
     * @since 1.0.0
     */
    public function isPresetsOverridden(): bool
    {
        return $this->presets !== null;
    }

    /**
     * The presets table rows, one per preset (older one-choice rows merged).
     *
     * @return array<int,array{profile:string,name:string,choices:string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function presetTableRows(): array
    {
        try {
            return SettingsRows::presetRows(SettingsRows::presets($this->presetRows));
        } catch (InvalidArgumentException) {
            // A row that does not parse is shown as typed, with the save error next to it.
            return $this->presetRows;
        }
    }

    /**
     * The config array the registry is built from.
     *
     * @return array<string,mixed>
     *
     * @author WMD
     * @since 1.0.0
     */
    public function toConfig(): array
    {
        if ($this->isOverridden()) {
            return ['groups' => $this->groups ?? [], 'profiles' => $this->profiles ?? []] + $this->_extras();
        }

        return SettingsRows::toConfig($this->groupRows, $this->optionRows, $this->profileRows) + $this->_extras();
    }

    /**
     * Only the tables and the picked input styles are saved to project config; file overrides stay in the file.
     *
     * @inheritdoc
     */
    public function fields(): array
    {
        return ['groupRows', 'optionRows', 'profileRows', 'presetRows', 'inputs'];
    }

    /**
     * Validator: the tables must build a valid registry.
     *
     * @return void
     *
     * @author WMD
     * @since 1.0.0
     */
    public function validateTables(): void
    {
        try {
            $registry = Registry::fromConfig($this->toConfig(), [Plugin::getInstance()->getGroups(), 'loadTokens']);
        } catch (InvalidArgumentException $e) {
            $this->addError($this->isOverridden() ? 'presetRows' : 'groupRows', $e->getMessage());
            return;
        }

        if ($this->isPresetsOverridden()) {
            return;
        }

        foreach ($registry->invalidPresetChoices() as $message) {
            $this->addError('presetRows', $message);
        }
    }

    /**
     * The settings page posts `inputs` as one JSON string. Craft saves only the posted
     * keys, so it has to arrive under `inputs`; it is kept as text until validation.
     *
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_string($values['inputs'] ?? null)) {
            $this->inputsJson = $values['inputs'];
            $values['inputs'] = InputOverrides::clean(Json::decodeIfJson($values['inputs']));
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * Validator: reads the posted input styles and drops those that point at nothing.
     *
     * Never an error: a style left behind by a removed block must not block saving.
     *
     * @return void
     *
     * @author WMD
     * @since 1.0.0
     */
    public function validateInputs(): void
    {
        $this->inputs = InputOverrides::clean(Json::decodeIfJson($this->inputsJson));

        try {
            $registry = Registry::fromConfig($this->toConfig(), [Plugin::getInstance()->getGroups(), 'loadTokens']);
        } catch (InvalidArgumentException) {
            // validateTables reports a configuration that does not build.
            return;
        }

        $this->inputs = InputOverrides::prune($this->inputs, $registry);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Config-file keys that apply whichever way groups and profiles are defined.
     *
     * @return array<string,mixed>
     */
    private function _extras(): array
    {
        return [
            'fieldMap' => $this->fieldMap,
            'groupOverrides' => $this->groupOverrides,
            'profileOverrides' => $this->profileOverrides,
            'presets' => $this->presets ?? SettingsRows::presets($this->presetRows),
            'inputs' => $this->inputs,
            'sections' => $this->sections,
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        // Before the tables: their check builds the registry with these styles.
        $rules[] = [['inputsJson'], 'validateInputs'];
        // Not skipped when empty: with groups in the config file the groups table is
        // empty, but the presets table still has to be checked.
        $rules[] = [['groupRows'], 'validateTables', 'skipOnEmpty' => false];

        return $rules;
    }
}
