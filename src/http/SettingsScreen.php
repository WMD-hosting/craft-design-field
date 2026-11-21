<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\http;

use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Entry\EntryTypes;
use CraftCms\Cms\Field\Fields;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Control;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Table as ControlTable;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field as UiField;
use CraftCms\Cms\Ui\Nodes\Group as GroupNode;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use DateTimeImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\InputOverrides;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\SettingsRows;
use wmd\designfield\helpers\StyleChoices;
use wmd\designfield\helpers\UsageReport;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;
use wmd\designfield\services\Health;
use wmd\designfield\services\SettingsFile;
use wmd\designfield\services\Usage;
use wmd\designfield\web\controls\BlockPicker;
use wmd\designfield\web\controls\PresetBlock;

/**
 * The plugin's settings page (Settings → Plugins → Design Field): the configuration
 * check, looks, the preview of a block's panel, presets and the usage report, each on
 * its own tab. It
 * opens with admin changes off; what it writes, it writes only where they are allowed,
 * and only site files: the Looks and Presets tabs, and "Customize this block" on the
 * Preview tab, save to the settings file beside the config file (services/SettingsFile),
 * and "Make tile pictures" on the Preview tab saves picture files. Groups, options and profiles stay in the config file.
 *
 * A tab is a URL (`?tab=`), so only the open one is built: the usage report reads every entry.
 *
 * @author WMD
 * @since 2.0.0
 */
class SettingsScreen
{
    // Const Properties
    // =========================================================================

    /**
     * Control panel path of the page.
     */
    public const PATH = 'settings/plugins/design-field';

    /**
     * The tabs in nav order: `?tab=` value => label. The first is the default.
     */
    public const TABS = ['configuration' => 'Configuration', 'looks' => 'Looks', 'preview' => 'Preview', 'presets' => 'Presets', 'usage' => 'Usage'];

    /**
     * Path of the Preview tab's panel in its Ui.
     */
    public const PANEL = 'design';

    /**
     * Key of the panel's values that holds the looks of this block only ("Customize this
     * block"): option handle => a StyleChoices value, '' for the look it has on every block.
     * Inside the panel's own values, so each refresh of the panel carries the unsaved ones.
     */
    public const LOOKS = '_looks';

    /**
     * Path of the block picked under "Try these looks" on the Looks tab.
     */
    public const TRY = 'try';

    /**
     * What a row of the labels and icons table is keyed by, before the choice's key.
     */
    public const ROW = 'k_';

    /**
     * The periods the usage report offers, in months; 0 counts everything.
     */
    public const PERIODS = [0 => 'All time', 12 => 'Last 12 months', 6 => 'Last 6 months', 3 => 'Last 3 months'];

    /**
     * Below this many entries a block's findings are a hint, as in `design-field:usage`.
     */
    private const SAMPLE = 5;

    public function __construct(
        private readonly Groups $groups,
        private readonly Health $health,
        private readonly Usage $usage,
        private readonly EntryTypes $entryTypes,
        private readonly Fields $fields,
        private readonly GeneralConfig $generalConfig,
        private readonly SettingsFile $settingsFile,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * @param Request $request
     * @return CpScreenResponse
     *
     * @author WMD
     * @since 2.0.0
     */
    public function response(Request $request): CpScreenResponse
    {
        $tab = $request->query('tab');
        $tab = is_string($tab) && isset(self::TABS[$tab]) ? $tab : array_key_first(self::TABS);

        $props = ['contentMaxWidth' => false];
        $context = new UiContext();

        if ($tab === 'preview') {
            $block = $this->previewBlock($request->query('block'));
            $ui = $this->previewUi($block, []);
            // The panel asks for itself again after a pick, as it does in an element's form.
            $context = new UiContext(refreshable: true);
            $props['refreshUrl'] = Url::cpUrl('design-field/preview/ui', $block === null ? [] : ['block' => $block]);

            // What Save saves is the looks of this block only: a site file, so where admin changes are allowed.
            if ($ui !== null && $block !== null && $this->generalConfig->allowAdminChanges) {
                $props['submit'] = ['method' => 'post', 'url' => Url::cpUrl('design-field/settings/block-looks', ['block' => $block])];
            }
        }

        // Looks and Presets are the tabs that save, to the settings file beside the config
        // file: a site file, so only where admin changes are allowed.
        if ($tab === 'looks' || $tab === 'presets') {
            $option = $tab === 'looks' ? $this->labelsOption($request->query('option')) : null;
            $form = match (true) {
                $tab === 'presets' => $this->presetsForm(),
                $option !== null => $this->labelsForm($option),
                default => $this->looksForm(),
            };

            if ($form !== null) {
                $editable = $this->generalConfig->allowAdminChanges && $form['editable'];
                $ui = Ui::make(array_values($form['nodes']));
                // The Looks tab asks for itself again for "Try these looks".
                $context = new UiContext(values: $form['values'], mode: $editable ? ControlMode::Editable : ControlMode::ReadOnly, refreshable: $tab === 'looks' && $editable);
                $props += match (true) {
                    $editable => ['submit' => ['method' => 'post', 'url' => Url::cpUrl('design-field/settings/' . ($option !== null ? 'labels' : $tab), $option !== null ? ['option' => $option] : [])]],
                    !$this->generalConfig->allowAdminChanges => ['readOnly' => true],
                    default => [],
                };

                if ($tab === 'looks' && $editable) {
                    $props['refreshUrl'] = Url::cpUrl('design-field/looks/ui', $option !== null ? ['option' => $option] : []);
                }
            }
        }

        $ui ??= Ui::make(match ($tab) {
            'usage' => $this->usageNodes($request),
            'preview' => [Callout::make('preview-error', 'The preview needs a configuration that builds: see the Configuration tab.')->variant('danger')],
            'looks', 'presets' => [Callout::make("$tab-error", 'This tab needs a configuration that builds: see the Configuration tab.')->variant('danger')],
            default => $this->configurationNodes(),
        });

        $subnav = [];
        foreach (self::TABS as $key => $label) {
            $subnav[] = new NavItem()->label($label)->href(self::url($key))->selected($key === $tab);
        }

        return new CpScreenResponse()
            ->title('Design Field')
            ->addCrumb('Settings', 'settings')
            ->addCrumb('Plugins', 'settings/plugins')
            ->subnav($subnav)
            ->ui($ui, $context, $props);
    }

    /**
     * The Preview tab: the real Design panel of one block with its defaults and presets,
     * and with `blockPreviewUrl` set the block itself beside it. The panel ends with the
     * looks of this block only ("Customize this block"), which is all the tab's Save saves.
     *
     * @param mixed $block A profile name; anything else shows the block with the most options
     * @param array<string,mixed> $design The panel's values as its form holds them: the choices, and under LOOKS the looks picked for this block, saved or not; empty for the defaults and the saved looks
     * @return ?Ui Null when the configuration does not build
     *
     * @author WMD
     * @since 2.0.0
     */
    public function previewUi(mixed $block, array $design): ?Ui
    {
        try {
            $registry = $this->groups->getRegistry();
        } catch (InvalidArgumentException) {
            return null;
        }

        $block = $this->previewBlock($block);

        if ($block === null) {
            return Ui::make([Callout::make('preview-none', 'No block has options yet: add `profiles` to `config/craft/design-field.php`.')]);
        }

        $labels = $this->blockLabels($registry);
        $frame = $this->frameUrl($block);
        $looks = $this->blockLooks($registry, $block);
        $picked = is_array($design[self::LOOKS] ?? null) ? $design[self::LOOKS] : null;
        unset($design[self::LOOKS]);

        $build = fn(): array => $this->panel($block, $design);

        // With looks picked for this block the panel is built with them, saved or not.
        if ($picked !== null) {
            foreach ($looks as $handle => $look) {
                $value = $picked[$handle] ?? '';
                $looks[$handle]['value'] = is_string($value) && in_array($value, $look['values'], true) ? $value : '';
            }

            try {
                [$value, $panel] = $this->groups->withInputs($this->inputsWithBlockLooks($block, array_filter(array_column($looks, 'value', 'handle'), 'strlen')), $build);
            } catch (InvalidArgumentException) {
                return null;
            }
        } else {
            [$value, $panel] = $build();
        }

        if ($panel instanceof PresetBlock) {
            $toggles = [];
            foreach ($value->groups() as $handle => $group) {
                if ($group->input === Group::INPUT_TOGGLE && count($group->options) === 2) {
                    $toggles[$handle] = array_map(strval(...), array_keys($group->options));
                }
            }

            $panel->frame($frame, $toggles)->tiles($frame === null ? null : $this->tiles($registry, $block));

            if ($looks !== []) {
                $panel->append($this->blockLooksGroup($looks, $labels[$block]));
            }
        }

        $keys = [];
        foreach ($value->groups() as $handle => $group) {
            $keys[] = [
                'id' => $handle,
                'group' => ['html' => Html::encode($group->label) . ' ' . Html::tag('code', Html::encode((string)$handle))],
                'keys' => ['html' => implode(', ', array_map(
                    static fn(string $key) => Html::tag('code', Html::encode($key)) . ($key === $group->default ? ' ★' : ''),
                    array_map('strval', array_keys($group->options)),
                ))],
            ];
        }

        return Ui::make([
            MarkdownContent::make('preview-intro', 'The real Design panel of one block, with its defaults and presets'
                . ($frame === null ? '.' : ', next to the block itself, which follows each change.')
                . ' Try layouts and presets: the choices you make here are not saved. **Customize this block**, at the end of the panel, sets how an option looks on this block only'
                . ($this->generalConfig->allowAdminChanges ? ': it shows at once, and Save keeps it.' : '.')
                . ($frame === null ? ' To see the block itself beside the panel, set `blockPreviewUrl` in the config file: `examples/block-preview.twig` in the plugin has the page for it.' : '')),
            UiField::make('Block')
                ->control(BlockPicker::make('block')->value($block)->options(array_map(
                    static fn(string $name) => ['value' => $name, 'label' => $labels[$name], 'url' => self::url('preview', ['block' => $name])],
                    array_keys($labels),
                ))),
            UiField::make('What editors see')->control($panel),
            Heading::make('preview-keys-heading', 'Group handles and keys of this block')
                ->description('For writing `presets` in the config file. ★ marks the default.'),
            Table::make('preview-keys')
                ->columns([
                    ['key' => 'group', 'label' => 'Group', 'width' => '1fr'],
                    ['key' => 'keys', 'label' => 'Keys', 'wrap' => true, 'width' => '3fr'],
                ])
                ->rows($keys),
        ]);
    }

    /**
     * The looks each option of a block can take there, the one saved for this block only
     * and the one it has without it: what "Customize this block" offers.
     *
     * @param Registry $registry The request's own, with the saved looks
     * @param string $block A profile name
     * @return array<string,array{handle:string,label:string,value:string,every:string,values:list<string>}> By option handle; `value` is '' without a look of its own, `every` the look it then has
     *
     * @author WMD
     * @since 2.0.0
     */
    public function blockLooks(Registry $registry, string $block): array
    {
        $inputs = InputOverrides::clean($this->groups->config()['inputs']);
        $saved = InputOverrides::clean($this->settingsFile->read()['inputs'])['profiles'][$block] ?? [];
        $looks = [];

        foreach ($registry->profiles[$block] ?? [] as $handle => $group) {
            $values = StyleChoices::values($group);

            // A list with one look to take has nothing to pick.
            if (count($values) < 2) {
                continue;
            }

            // Without a look of its own: the option table's look where the option can take it, or the config file's.
            $everywhere = $inputs['groups'][$handle] ?? null;
            $every = $everywhere !== null && in_array($everywhere['input'], $group->supportedInputs(), true)
                ? $everywhere
                : ($registry->configInputs[$block][$handle] ?? ['input' => $group->input, 'iconsOnly' => $group->iconsOnly]);
            $value = isset($saved[$handle]) ? StyleChoices::value($saved[$handle]) : '';

            $looks[(string)$handle] = [
                'handle' => (string)$handle,
                'label' => $group->label,
                'value' => in_array($value, $values, true) ? $value : '',
                'every' => StyleChoices::value($every),
                'values' => $values,
            ];
        }

        return $looks;
    }

    /**
     * The configuration's `inputs` with the looks of one block replaced: what the site
     * would read after a save of them. Looks the config file itself names for the block stay under them.
     *
     * @param string $block A profile name
     * @param array<string,string> $looks Option handle => a StyleChoices value
     * @return array<string,mixed>
     *
     * @author WMD
     * @since 2.0.0
     */
    public function inputsWithBlockLooks(string $block, array $looks): array
    {
        $inputs = InputOverrides::clean($this->groups->config()['inputs']);
        $inFile = InputOverrides::clean($this->groups->fileConfig()['inputs'] ?? [])['profiles'][$block] ?? [];
        $inputs['profiles'][$block] = array_replace($inFile, array_map(StyleChoices::style(...), $looks));

        return $inputs;
    }

    /**
     * The page's URL for a tab.
     *
     * @param string $tab One of the TABS keys
     * @param array<string,scalar> $params More query parameters
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function url(string $tab = 'configuration', array $params = []): string
    {
        return Url::cpUrl(self::PATH, ($tab === array_key_first(self::TABS) ? [] : ['tab' => $tab]) + $params);
    }

    /**
     * The Looks tab: one input style swapped for another on every option that has it,
     * and one row per option with its look on every block and the heading it is listed
     * under. Saved to the settings file as `inputs` (`styles`, `groups`, `sections`).
     * "Try these looks" shows one block's panel built with the looks as the form holds them.
     *
     * @param ?array<string,mixed> $typed The form's values when it asks for itself again (`styles`, `looks`, `try`); null shows the saved looks
     * @return ?array{nodes:list<Node>,values:array<string,mixed>,editable:bool} Null when the configuration does not build, with the typed looks or without
     *
     * @author WMD
     * @since 2.0.0
     */
    public function looksForm(?array $typed = null): ?array
    {
        try {
            $registry = $this->groups->getRegistry();
        } catch (InvalidArgumentException) {
            return null;
        }

        $saved = InputOverrides::clean($this->settingsFile->read()['inputs']);
        $shown = $typed === null ? $saved : InputOverrides::clean(self::looksInputs($typed, $saved));
        $rows = StyleChoices::rows($registry);
        $asConfigured = static fn(string $base): array => ['label' => $base === '' ? 'As configured' : sprintf('As configured (%s)', StyleChoices::LABELS[$base] ?? $base), 'value' => ''];

        // Quick switch: the styles options have before any pick here, most used first.
        $used = [];
        foreach ($rows as $row) {
            if ($row['base'] !== '') {
                $used[$row['base']] = ($used[$row['base']] ?? 0) + 1;
            }
        }
        arsort($used);

        // A style swapped away is no option's style any more: it stays listed, to swap it back.
        $used += array_fill_keys(array_keys($saved['styles']), 0);

        $nodes = [
            MarkdownContent::make('looks-intro', 'How options look to editors: icons, labelled buttons, a dropdown, a slider. What you save here is written to `config/craft/design-field.settings.json`, beside the config file, and wins over the looks set in that file: commit it with the site. **Try these looks**, at the end, shows a block with them before you save. A look for one block only is set on the Preview tab.'),
            Heading::make('looks-switch-heading', 'Quick switch')
                ->description('Show every option of one style another way, on every block: for example Icons as Labels only, for editors who prefer words. An option that cannot take the new style keeps its own, and an option\'s own look below still wins.'),
        ];

        $trying = is_string($typed[self::TRY] ?? null) && $typed[self::TRY] !== '';
        $values = ['styles' => [], 'looks' => []];
        foreach ($used as $from => $count) {
            $values['styles'][$from] = $shown['styles'][$from] ?? '';
            $options = [['label' => 'Leave as it is', 'value' => '']];
            foreach (StyleChoices::LABELS as $value => $label) {
                if ($value !== $from) {
                    $options[] = ['label' => $label, 'value' => $value];
                }
            }

            $nodes[] = UiField::make($count === 0
                ? sprintf('%s (swapped)', StyleChoices::LABELS[$from] ?? $from)
                : sprintf('%s (%d %s)', StyleChoices::LABELS[$from] ?? $from, $count, $count === 1 ? 'option' : 'options'))
                ->width(25)
                // The block under "Try these looks" follows a swap at once; with none picked a swap asks for nothing.
                ->control(Choice::make(['styles', $from])->withoutPlaceholder()->options($options)->reactive($trying));
        }

        $cells = [];
        foreach ($rows as $row) {
            $handle = $row['handle'];
            $look = isset($shown['groups'][$handle]) ? StyleChoices::value($shown['groups'][$handle]) : '';
            $values['looks'][$handle] = [
                'option' => sprintf('%s (%s)', $row['label'], $handle),
                'blocks' => (string)$row['blocks'],
                'look' => $look,
                'section' => $shown['sections'][$handle] ?? '',
            ];
            $cells[$handle] = ['look' => ['options' => [
                $asConfigured($row['base']),
                ...array_map(static fn(string $value) => ['label' => StyleChoices::LABELS[$value], 'value' => $value], array_values(array_unique([...$row['values'], ...($look === '' ? [] : [$look])]))),
            ]]];
        }

        $nodes[] = UiField::make('Option looks')
            ->instructions('One row per option, most used first. **Looks like** sets the option\'s look on every block that has it, where it can take it (a toggle needs exactly two choices). **Section** is the heading the option is listed under in the panel; empty keeps the one from the config file.')
            ->control(ControlTable::make('looks')
                ->keyed()
                ->columns([
                    'option' => ['heading' => 'Option', 'type' => 'heading', 'width' => '36%'],
                    'blocks' => ['heading' => 'Blocks', 'type' => 'heading', 'width' => '8%'],
                    'look' => ['heading' => 'Looks like', 'type' => 'select', 'options' => [$asConfigured('')], 'width' => '32%'],
                    'section' => ['heading' => 'Section', 'type' => 'singleline', 'width' => '24%'],
                ])
                ->cellOptions($cells));

        // Other labels and icons are edited one option at a time, on a screen of their own.
        $labelled = array_keys($saved['meta']);
        $nodes[] = Heading::make('looks-labels-heading', 'Labels and icons')
            ->description('Other names and icons for an option\'s choices, on every block: Tab size as S · M · L instead of samples, with the Labels only look. Opens the option\'s choices; save the looks above first.');
        $nodes[] = UiField::make('Option')
            ->control(BlockPicker::make('labelsOption')->value('')->options([
                ['value' => '', 'label' => 'Pick an option…', 'url' => self::url('looks')],
                ...array_map(static fn(array $row) => [
                    'value' => $row['handle'],
                    'label' => sprintf('%s (%s)%s', $row['label'], $row['handle'], in_array($row['handle'], $labelled, true) ? ' ✎' : ''),
                    'url' => self::url('looks', ['option' => $row['handle']]),
                ], $rows),
            ]));

        try {
            [$tryNodes, $tryValues] = $this->tryNodes($registry, $typed, array_keys($registry->profiles), fn(): array => InputOverrides::merge($this->groups->fileConfig()['inputs'] ?? [], $shown));
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['nodes' => [...$nodes, ...$tryNodes], 'values' => $values + $tryValues, 'editable' => true];
    }

    /**
     * The three parts of `inputs` the Looks tab edits, read from its form and laid over
     * the saved looks: the tab's save and "Try these looks" read the form the same way.
     *
     * @param array<string,mixed> $form `styles`: style => style; `looks`: option handle => `look` and `section`
     * @param array<string,mixed> $saved The saved `inputs`; its other parts are kept
     * @return array<string,mixed> Not cleaned
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function looksInputs(array $form, array $saved): array
    {
        $inputs = ['styles' => [], 'groups' => [], 'sections' => []] + $saved;

        foreach (is_array($form['styles'] ?? null) ? $form['styles'] : [] as $from => $to) {
            if (is_string($to) && $to !== '') {
                $inputs['styles'][(string)$from] = $to;
            }
        }

        foreach (is_array($form['looks'] ?? null) ? $form['looks'] : [] as $handle => $row) {
            $look = is_string($row['look'] ?? null) ? $row['look'] : '';
            $section = is_string($row['section'] ?? null) ? trim($row['section']) : '';

            if (isset(StyleChoices::LABELS[$look])) {
                $inputs['groups'][(string)$handle] = StyleChoices::style($look);
            }
            if ($section !== '') {
                $inputs['sections'][(string)$handle] = $section;
            }
        }

        return $inputs;
    }

    /**
     * The option whose labels and icons the Looks tab shows (`?option=`).
     *
     * @param mixed $requested A group handle
     * @return ?string Null for the Looks tab itself: no option asked for, or none a block has
     *
     * @author WMD
     * @since 2.0.0
     */
    public function labelsOption(mixed $requested): ?string
    {
        try {
            return is_string($requested) && isset($this->groups->getRegistry()->baseOptions[$requested]) ? $requested : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Labels and icons of one option's choices (`?tab=looks&option=`): one row per choice,
     * saved to the settings file as `inputs.meta[<option>]`. An empty cell keeps what the
     * config file says.
     *
     * @param string $option A group handle a block has
     * @param ?array<string,mixed> $typed The form's values when it asks for itself again (`labels`, `try`); null shows the saved ones
     * @return ?array{nodes:list<Node>,values:array<string,mixed>,editable:bool} Null when the configuration does not build
     *
     * @author WMD
     * @since 2.0.0
     */
    public function labelsForm(string $option, ?array $typed = null): ?array
    {
        try {
            $registry = $this->groups->getRegistry();
        } catch (InvalidArgumentException) {
            return null;
        }

        $saved = InputOverrides::clean($this->settingsFile->read()['inputs']);
        // A save that was refused comes back with its rows as typed.
        $typed ??= is_array(session()->getOldInput('labels')) ? ['labels' => session()->getOldInput('labels')] : null;
        $meta = $typed === null ? ($saved['meta'][$option] ?? []) : self::labelsMeta($typed['labels'] ?? [])['meta'];
        $label = $option;
        $blocks = [];

        foreach ($registry->profiles as $name => $groups) {
            if (isset($groups[$option])) {
                $label = $groups[$option]->label;
                $blocks[] = (string)$name;
            }
        }

        $rows = [];
        foreach ($registry->baseOptions[$option] ?? [] as $key => $base) {
            // Prefixed: a list of numeric keys (1, 2, 3) would lose them on its way through JSON.
            $rows[self::ROW . $key] = [
                'key' => (string)$key,
                'configured' => $base['label'] . ($base['icon'] !== null && preg_match('/^[a-z0-9-]+$/', $base['icon']) ? sprintf(' (%s)', $base['icon']) : ''),
                'label' => $meta[(string)$key]['label'] ?? '',
                'icon' => $meta[(string)$key]['icon'] ?? null,
            ];
        }

        $names = [];
        foreach (StyleChoices::rows($registry) as $row) {
            $names[$row['handle']] = sprintf('%s (%s)', $row['label'], $row['handle']);
        }

        $nodes = [
            MarkdownContent::make('labels-intro', sprintf('Other names and icons for the choices of **%s**, on every block that has it. Only what editors read changes: saved values and templates use the keys. An empty cell keeps what the config file says. [Back to the looks](<%s>).', $label, self::url('looks'))),
            UiField::make('Option')
                ->control(BlockPicker::make('labelsOption')->value($option)->options(array_map(
                    static fn(string $handle, string $name) => ['value' => $handle, 'label' => $name, 'url' => self::url('looks', ['option' => $handle])],
                    array_keys($names),
                    $names,
                ))),
            UiField::make('Labels and icons')
                ->instructions('**Label** is the choice\'s name, up to 60 characters. **Icon** shows where the option looks like Icons or Buttons.')
                ->control(ControlTable::make('labels')
                    ->keyed()
                    ->columns([
                        'key' => ['heading' => 'Key', 'type' => 'heading', 'width' => '18%'],
                        'configured' => ['heading' => 'In the config file', 'type' => 'heading', 'width' => '26%'],
                        'label' => ['heading' => 'Label', 'type' => 'singleline', 'width' => '32%'],
                        // Craft's own icons only: their names are what a choice's `icon` takes.
                        'icon' => ['heading' => 'Icon', 'control' => IconPicker::make(['icon']), 'width' => '24%'],
                    ])),
        ];

        try {
            [$tryNodes, $tryValues] = $this->tryNodes($registry, $typed, $blocks, function() use ($saved, $option, $meta): array {
                $inputs = $saved;
                $inputs['meta'][$option] = $meta;

                return InputOverrides::merge($this->groups->fileConfig()['inputs'] ?? [], array_filter($inputs));
            });
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['nodes' => [...$nodes, ...$tryNodes], 'values' => ['labels' => $rows] + $tryValues, 'editable' => true];
    }

    /**
     * The rows of the labels and icons table as `inputs.meta` takes them, and what is wrong with them.
     *
     * @param mixed $rows Row key (ROW and the choice's key) => `label` and `icon`
     * @return array{meta:array<string,array{label?:string,icon?:string}>,problems:list<string>}
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function labelsMeta(mixed $rows): array
    {
        $meta = [];
        $problems = [];

        foreach (is_array($rows) ? $rows : [] as $rowKey => $row) {
            $key = str_starts_with((string)$rowKey, self::ROW) ? substr((string)$rowKey, strlen(self::ROW)) : (string)$rowKey;
            $label = is_scalar($row['label'] ?? null) ? trim((string)$row['label']) : '';
            $icon = is_scalar($row['icon'] ?? null) ? trim((string)$row['icon']) : '';

            // Said, not cut or dropped when the file is read after a save that reported success.
            if (mb_strlen($label) > 60) {
                $problems[] = sprintf('%s: a label has up to 60 characters.', $key);
            }
            if ($icon !== '' && !preg_match('/^[a-z0-9-]+$/', $icon)) {
                $problems[] = sprintf('%s: "%s" is not the name of one of Craft\'s icons.', $key, $icon);
                $icon = '';
            }

            $meta[$key] = array_filter(['label' => mb_substr($label, 0, 60), 'icon' => $icon], 'strlen');
        }

        return ['meta' => array_filter($meta), 'problems' => $problems];
    }

    /**
     * The Presets tab: one row per preset, saved to the settings file as `presets`.
     * Presets named in the config file itself show read only: that file wins.
     *
     * @return ?array{nodes:list<Node>,values:array<string,mixed>,editable:bool} Null when the configuration does not build
     *
     * @author WMD
     * @since 2.0.0
     */
    public function presetsForm(): ?array
    {
        try {
            $registry = $this->groups->getRegistry();
        } catch (InvalidArgumentException) {
            return null;
        }

        $inFile = array_key_exists('presets', $this->groups->fileConfig());
        // Every block first: it is what a new row starts on.
        $labels = [Registry::FALLBACK_PROFILE => 'Every block (*)'] + $this->blockLabels($registry);
        $labels[Registry::FALLBACK_PROFILE] = 'Every block (*)';
        $rows = SettingsRows::presetRows($registry->presets);

        // A save that was refused comes back with its rows as typed, not with the saved ones.
        $typed = $inFile ? null : session()->getOldInput('presets');
        if (is_array($typed)) {
            $rows = array_values(array_map(static fn(mixed $row): array => [
                'profile' => is_string($row['profile'] ?? null) ? $row['profile'] : Registry::FALLBACK_PROFILE,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'choices' => is_string($row['choices'] ?? null) ? $row['choices'] : '',
            ], array_filter($typed, is_array(...))));
        }

        // A preset for a block that is gone stays selectable, so a save does not drop it unseen.
        foreach ($rows as $row) {
            $labels[$row['profile']] ??= sprintf('%s (not in the config)', $row['profile']);
        }

        return [
            'nodes' => [
                MarkdownContent::make('presets-intro', 'One-click sets of choices shown above a block\'s options. What you save here is written to `config/craft/design-field.settings.json`, beside the config file: commit it with the site.'),
                ...($inFile ? [Callout::make('presets-in-file', 'Presets are set in `config/craft/design-field.php`. Remove `presets` from that file to edit them here instead.')->variant('warning')] : []),
                UiField::make('Presets')
                    ->instructions('One row per preset. **Choices** is a comma list of `group=key`, for example `tone=inverted, spacing=loose`; the Preview tab lists each block\'s groups and keys under the panel. A block shows its own presets first, then those of every block; a choice for a group the block does not have is left out there.')
                    ->control(ControlTable::make('presets')
                        ->columns([
                            'profile' => ['heading' => 'Block', 'type' => 'select', 'options' => array_map(static fn(string $name, string $label) => ['label' => $label, 'value' => $name], array_keys($labels), $labels)],
                            'name' => ['heading' => 'Preset', 'type' => 'singleline'],
                            'choices' => ['heading' => 'Choices', 'type' => 'singleline', 'code' => true, 'placeholder' => 'group=key, group=key'],
                        ])
                        // A new row's dropdown shows its first choice but holds nothing until it is changed.
                        ->defaultValues(['profile' => Registry::FALLBACK_PROFILE])
                        ->allowAdd()
                        ->allowDelete()
                        ->allowReorder()
                        ->addRowLabel('Add a preset')),
            ],
            'values' => ['presets' => $rows],
            'editable' => !$inFile,
        ];
    }

    /**
     * The entry type a profile is for: `pageBuilder:blockTeam` and `blockTeam` are both `blockTeam`.
     *
     * @param string $block A profile name
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function blockType(string $block): string
    {
        return str_contains($block, ':') ? substr($block, strrpos($block, ':') + 1) : $block;
    }

    // Private Methods
    // =========================================================================

    /**
     * A block's panel as its Design field builds it, from the request's registry.
     *
     * @param string $block A profile name
     * @param array<string,mixed> $design The panel's choices as its form holds them; empty for the defaults
     * @return array{0:DesignValue,1:Control}
     */
    private function panel(string $block, array $design): array
    {
        $field = new Design(['name' => 'Design', 'handle' => self::PANEL, 'profile' => $block]);
        $value = $design === [] ? $field->normalizeValue([], null) : $field->normalizeValueFromRequest($design, null);

        return [$value, $field->uiControl(new FieldContext(self::PANEL, $value))];
    }

    /**
     * "Try these looks": a block picker, and once a block is picked its panel built with
     * the looks as the form holds them. Nothing is built until then.
     *
     * An editable table's cells cannot ask for a refresh, so the panel follows the
     * picker (and the quick switch): pick the block again after changing a row.
     *
     * @param Registry $registry The request's own
     * @param ?array<string,mixed> $typed The form's values; `try` is the block picked
     * @param list<string> $blocks The profiles to offer
     * @param callable():array<string,mixed> $inputs The whole `inputs` to build the panel with
     * @return array{0:list<Node>,1:array<string,mixed>} The nodes and their values
     * @throws InvalidArgumentException if the configuration does not build with those inputs
     */
    private function tryNodes(Registry $registry, ?array $typed, array $blocks, callable $inputs): array
    {
        // Nothing to try where nothing can be changed.
        if (!$this->generalConfig->allowAdminChanges) {
            return [[], []];
        }

        $labels = array_intersect_key($this->blockLabels($registry), array_flip($blocks));
        $try = is_string($typed[self::TRY] ?? null) && isset($labels[$typed[self::TRY]]) ? $typed[self::TRY] : '';
        $nodes = [
            Heading::make('looks-try-heading', 'Try these looks')
                ->description('One block\'s panel with the looks as they stand above, before you save them. After changing a row, pick the block again to see it.'),
            UiField::make('Block')
                ->width(25)
                ->control(Choice::make(self::TRY)->withoutPlaceholder()->reactive()->options([
                    ['label' => 'No preview', 'value' => ''],
                    ...array_map(static fn(string $name, string $label) => ['label' => $label, 'value' => $name], array_keys($labels), $labels),
                ])),
        ];

        if ($try !== '') {
            [, $panel] = $this->groups->withInputs($inputs(), fn(): array => $this->panel($try, []));
            $nodes[] = UiField::make('What editors would see')->control($panel instanceof PresetBlock ? $panel->still() : $panel);
        }

        return [$nodes, [self::TRY => $try]];
    }

    /**
     * "Customize this block": one dropdown per option, inside the panel.
     *
     * @param array<string,array{handle:string,label:string,value:string,every:string,values:list<string>}> $looks From blockLooks()
     * @param string $name The block's name
     * @return GroupNode
     */
    private function blockLooksGroup(array $looks, string $name): GroupNode
    {
        $editable = $this->generalConfig->allowAdminChanges;
        $nodes = [
            MarkdownContent::make('design-looks-intro', sprintf('How an option looks on **%s** only. It wins over the Looks tab, and the panel above shows it at once.', $name)
                . ($editable ? ' Save writes it to `config/craft/design-field.settings.json`.' : ' Changing it needs admin changes to be allowed.'))->displayInPane(false),
        ];

        foreach ($looks as $handle => $look) {
            $nodes[] = UiField::make($look['label'])
                ->width(50)
                ->control(Choice::make([self::LOOKS, $handle])
                    ->withoutPlaceholder()
                    ->value($look['value'])
                    ->options([
                        ['label' => sprintf('As on every block (%s)', StyleChoices::LABELS[$look['every']] ?? $look['every']), 'value' => ''],
                        ...array_map(static fn(string $value) => ['label' => StyleChoices::LABELS[$value], 'value' => $value], $look['values']),
                    ])
                    ->reactive($editable)
                    ->mode($editable ? ControlMode::Editable : ControlMode::ReadOnly));
        }

        $set = array_filter(array_column($looks, 'value'), 'strlen') !== [];

        return GroupNode::make('design-looks', $nodes)->label('Customize this block')->collapsible()->expanded($set);
    }

    /**
     * The block the Preview tab shows: the one asked for, or the one with the most options.
     *
     * @param mixed $requested
     * @return ?string Null when no block has options, or the configuration does not build
     */
    private function previewBlock(mixed $requested): ?string
    {
        try {
            $registry = $this->groups->getRegistry();
        } catch (InvalidArgumentException) {
            return null;
        }

        if (is_string($requested) && isset($registry->profiles[$requested])) {
            return $requested;
        }

        // `*` names no one block, so it has no block to show beside the panel: a named one first.
        $busiest = null;
        foreach ($registry->profiles as $name => $groups) {
            if ((string)$name !== Registry::FALLBACK_PROFILE && ($busiest === null || count($groups) > count($registry->profiles[$busiest]))) {
                $busiest = (string)$name;
            }
        }

        return $busiest ?? (isset($registry->profiles[Registry::FALLBACK_PROFILE]) ? Registry::FALLBACK_PROFILE : null);
    }

    /**
     * Every profile with the name of its block, by name: `blockTeam` is "Team",
     * `pageBuilder:blockTeam` is "Team (in Page builder)", `*` is every other block.
     *
     * @param Registry $registry
     * @return array<string,string> Profile name => label
     */
    private function blockLabels(Registry $registry): array
    {
        $labels = [];

        foreach ($registry->profileNames() as $name) {
            [$owner, $type] = str_contains($name, ':') ? explode(':', $name, 2) : [null, $name];
            $label = $name === Registry::FALLBACK_PROFILE
                ? 'Every other block (*)'
                : ($this->entryTypes->getEntryTypeByHandle($type)?->name ?? $type);

            $labels[$name] = $owner === null ? $label : sprintf('%s (in %s)', $label, $this->fields->getFieldByHandle($owner)?->name ?? $owner);
        }

        uasort($labels, strnatcasecmp(...));

        return $labels;
    }

    /**
     * Where the block itself renders (`blockPreviewUrl`), with its type filled in and
     * `{design}` left for the page script.
     *
     * @param string $block A profile name
     * @return ?string Null when the config has no `blockPreviewUrl`, or the profile names no one block type
     */
    private function frameUrl(string $block): ?string
    {
        $url = trim((string)$this->groups->config()['blockPreviewUrl']);

        if ($url === '' || $block === Registry::FALLBACK_PROFILE) {
            return null;
        }

        return str_replace('{type}', rawurlencode(self::blockType($block)), $url);
    }

    /**
     * What "Make tile pictures" needs for a block: its layouts, each with its picture or
     * null when it has none yet, where a picture is saved, and what to leave out of one.
     *
     * @param Registry $registry
     * @param string $block A profile name
     * @return ?array{saveUrl:string,block:string,skip:string,layouts:list<array{key:string,label:string,picture:?string}>} Null when the block has no layout list, tile pictures are off (`tileImages`), or admin changes are not allowed: the pictures are site files
     */
    private function tiles(Registry $registry, string $block): ?array
    {
        $layouts = $registry->profiles[$block]['variant'] ?? null;

        if ($layouts === null || !$this->generalConfig->allowAdminChanges || $this->groups->tilePath(self::blockType($block), '_default') === null) {
            return null;
        }

        $list = [];
        foreach ($layouts->options as $key => $option) {
            if ($key !== Group::AUTO) {
                $list[] = ['key' => (string)$key, 'label' => $option['label'], 'picture' => $option['image']];
            }
        }

        return [
            'saveUrl' => Url::cpUrl('design-field/tiles/save'),
            'block' => $block,
            'skip' => trim((string)$this->groups->config()['tileSkip']),
            'layouts' => $list,
        ];
    }

    /**
     * What `design-field:check` prints: the configuration against this install, and
     * the options against the templates that render them.
     *
     * @return list<Node>
     */
    private function configurationNodes(): array
    {
        $config = $this->health->check();
        $templates = $this->health->templates();
        $failing = count(array_filter([...$config, ...$templates], static fn(array $finding) => $finding['level'] !== Health::INFO));

        return [
            MarkdownContent::make('configuration-intro', 'Options, blocks and presets are set in `config/craft/design-field.php`. This page checks that file against this install and its templates; `php artisan design-field:check` prints the same, and fails a deploy when something needs fixing.'),
            $failing === 0
                ? Callout::make('configuration-verdict', 'No problems found.')->variant('success')
                : Callout::make('configuration-verdict', $failing === 1 ? 'One finding to fix.' : "$failing findings to fix.")->variant('warning'),
            Heading::make('configuration-heading', 'Configuration check')
                ->description('Blocks named in the file that this install does not have, fields set to a missing block, preset choices no block offers, and option pictures missing under the web root.'),
            self::findingsTable('configuration-findings', $config, 'The configuration matches this install.'),
            Heading::make('templates-heading', 'Template check')
                ->description('Options no template reads (editors change them and nothing happens), template reads of options a block does not offer, and layouts without a template file.'),
            self::findingsTable('template-findings', $templates, 'Templates and options match.'),
        ];
    }

    /**
     * @param string $uid
     * @param list<array{level:string,message:string}> $findings
     * @param string $empty What the table says with no finding
     * @return Table
     */
    private static function findingsTable(string $uid, array $findings, string $empty): Table
    {
        $rows = [];

        foreach ($findings as $i => $finding) {
            $rows[] = [
                'id' => $i + 1,
                'level' => match ($finding['level']) {
                    Health::ERROR => 'Error',
                    Health::WARNING => 'Warning',
                    default => 'Note',
                },
                'finding' => $finding['message'],
                '_status' => match ($finding['level']) {
                    Health::ERROR => 'expired',
                    Health::WARNING => 'warning',
                    default => 'grey',
                },
            ];
        }

        return Table::make($uid)
            ->columns([
                ['key' => 'level', 'label' => 'Level', 'thin' => true],
                ['key' => 'finding', 'label' => 'Finding', 'wrap' => true],
            ])
            ->rows($rows)
            ->emptyMessage($empty);
    }

    /**
     * What `design-field:usage` prints, one table row per block. Counting reads every
     * entry, so it runs only when asked (`run=1`).
     *
     * @param Request $request
     * @return list<Node>
     */
    private function usageNodes(Request $request): array
    {
        $nodes = [
            MarkdownContent::make('usage-intro', 'Which choices editors actually make, counted over every entry (drafts and revisions left out). An option nobody changes can be dropped from its block or fixed in the template; a choice everyone picks can become the default. On the command line: `php artisan design-field:usage`.'),
        ];

        try {
            $registry = $this->groups->getRegistry();
        } catch (InvalidArgumentException $e) {
            return [...$nodes, Callout::make('usage-error', 'The report needs a configuration that builds: ' . $e->getMessage())->variant('danger')];
        }

        if (!$request->boolean('run')) {
            return [...$nodes, MarkdownContent::make('usage-run', sprintf('[Run the usage report](<%s>)', self::url('usage', ['run' => 1])))];
        }

        $months = $request->integer('months');
        $months = isset(self::PERIODS[$months]) ? $months : 0;
        $report = $this->usage->report([], $months > 0 ? new DateTimeImmutable("-$months months") : null);

        // Counted everything anyway: refresh "Most used" in long dropdowns too. A report
        // limited to a period would shrink those counts to its window.
        if ($months === 0) {
            $this->usage->storeCounts($report);
        }

        $summary = UsageReport::summarize($report);
        $periods = [];
        foreach (self::PERIODS as $period => $label) {
            $periods[] = $period === $months ? "**$label**" : sprintf('[%s](<%s>)', $label, self::url('usage', ['run' => 1] + ($period > 0 ? ['months' => $period] : [])));
        }

        $nodes[] = MarkdownContent::make('usage-period', 'Count blocks saved in: ' . implode(' · ', $periods));
        $nodes[] = MarkdownContent::make('usage-totals', sprintf(
            '**%d** %s with findings · **%d** %s never changed · **%d** always the same choice · %d %s counted%s',
            count($summary['types']),
            count($summary['types']) === 1 ? 'block' : 'blocks',
            $summary['neverChanged'],
            $summary['neverChanged'] === 1 ? 'option' : 'options',
            $summary['oneValue'],
            count($report),
            count($report) === 1 ? 'block' : 'blocks',
            $months === 0 ? '. “Most used” in long dropdowns now follows these counts.' : '.',
        ));

        if ($summary['nowhere'] !== []) {
            $nodes[] = Callout::make('usage-nowhere', 'Never changed in any block: ' . implode(', ', array_map(
                static fn(array $group) => sprintf('%s (%d blocks, %d entries)', $group['label'], $group['types'], $group['entries']),
                $summary['nowhere'],
            )))->variant('warning');
        }

        if ($summary['types'] === []) {
            $nodes[] = Callout::make('usage-none', $report === [] ? 'No block with a Design field has entries yet.' : 'Nothing to tidy: every option is in use.')->variant($report === [] ? 'info' : 'success');
        }

        if ($summary['types'] !== []) {
            $nodes[] = $this->usageTable($summary['types'], $registry);
        }

        return $nodes;
    }

    /**
     * One row per block: the options nobody changed and the ones where every entry
     * picked the same choice. Blocks with few entries come last: hints, not evidence.
     *
     * @param array<string,array{entries:int,findings:list<array<string,mixed>>}> $types UsageReport::summarize()['types']
     * @param Registry $registry
     * @return Table
     */
    private function usageTable(array $types, Registry $registry): Table
    {
        uasort($types, static fn(array $a, array $b) => ($a['entries'] < self::SAMPLE) <=> ($b['entries'] < self::SAMPLE));
        $rows = [];

        foreach ($types as $type => $data) {
            $groups = $registry->groupsFor($type);
            $label = static fn(string $handle, ?string $key): string => (string)(($groups[$handle] ?? null)?->options[(string)$key]['label'] ?? $key);
            $never = [];
            $same = [];

            foreach ($data['findings'] as $finding) {
                if ($finding['verdict'] === UsageReport::ONE_VALUE) {
                    $same[] = sprintf('%s: %s', $finding['label'], $label($finding['handle'], $finding['value']))
                        . ($finding['unused'] === [] ? '' : sprintf(' (never picked: %s)', implode(', ', array_map(static fn(string $key) => $label($finding['handle'], $key), $finding['unused']))));
                } elseif ($finding['unused'] !== []) {
                    // A layout list: which layouts nobody picked matters more than the default.
                    $never[] = sprintf('%s: %s (never picked: %s)', $finding['label'], $label($finding['handle'], $finding['default']), implode(', ', array_map(static fn(string $key) => $label($finding['handle'], $key), $finding['unused'])));
                } else {
                    $never[] = sprintf('%s: %s', $finding['label'], $label($finding['handle'], $finding['default']));
                }
            }

            $entryType = $this->entryTypes->getEntryTypeByHandle($type);
            $rows[] = [
                'id' => $type,
                'block' => $entryType === null ? $type : ['label' => $entryType->name, 'url' => Url::cpUrl("settings/entry-types/$entryType->id")],
                'entries' => $data['entries'] < self::SAMPLE ? sprintf('%d (few: a hint)', $data['entries']) : $data['entries'],
                'never' => implode(' · ', $never),
                'same' => implode(' · ', $same),
                '_sort' => ['entries' => $data['entries']],
            ];
        }

        return Table::make('usage-blocks')
            ->columns([
                ['key' => 'block', 'label' => 'Block', 'sortable' => true, 'wrap' => true, 'width' => '1.2fr'],
                ['key' => 'entries', 'label' => 'Entries', 'sortable' => true, 'thin' => true],
                ['key' => 'never', 'label' => 'Never changed (drop or hardcode)', 'wrap' => true, 'width' => '3fr'],
                ['key' => 'same', 'label' => 'Always the same (make it the default)', 'wrap' => true, 'width' => '2fr'],
            ])
            ->rows($rows)
            ->searchable('Find a block or an option');
    }
}
