<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web;

use Craft;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\View;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\StyleChoices;
use wmd\designfield\helpers\UsageReport;
use wmd\designfield\models\Group;
use wmd\designfield\Plugin;
use wmd\designfield\services\Groups;
use wmd\designfield\web\assets\tiles\TilesAsset;

/**
 * The settings page's "How options look" (site-wide looks: a quick style switch and one
 * row per option) and "What editors see" (one block's panel, with per-block looks under
 * "Customize this block", and the block itself in an iframe when `blockPreviewUrl` is set).
 *
 * @author WMD
 * @since 1.0.0
 */
class SettingsPreview
{
    // Public Methods
    // =========================================================================

    /**
     * Everything the page script needs for one block.
     *
     * @param Registry $registry Built with the pending input styles
     * @param string $profile
     * @return array{html:string,keys:string,styles:array<string,array{label:string,base:string,baseLabel:string,choices:list<array{value:string,label:string}>}>,frame:?string,tiles:list<array{key:string,label:string,picture:?string}>,options:list<array{handle:string,preview:string,section:string,keys:list<array{key:string,label:string,icon:?string}>,label:string,blocks:int,baseLabel:string,choices:list<array{value:string,label:string}>}>}
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function payload(Registry $registry, string $profile): array
    {
        $groups = $registry->groupsFor($profile);
        $styles = [];

        foreach ($groups as $handle => $group) {
            $base = $registry->configInputs[$profile][$handle] ?? ['input' => $group->input, 'iconsOnly' => $group->iconsOnly];
            $styles[$handle] = [
                'label' => $group->label,
                'base' => StyleChoices::value($base),
                'baseLabel' => Craft::t('design-field', StyleChoices::LABELS[StyleChoices::value($base)]),
                'choices' => array_map(
                    static fn(string $value) => ['value' => $value, 'label' => Craft::t('design-field', StyleChoices::LABELS[$value])],
                    StyleChoices::values($group),
                ),
            ];
        }

        // The panel first: its script must register outside the previews' discarded buffer.
        $html = GroupInput::preview($registry, $profile);
        $looks = self::_looks($registry);
        $sections = [];
        foreach ($registry->profiles as $profileGroups) {
            foreach ($profileGroups as $handle => $group) {
                $sections[$handle] ??= $group->section;
            }
        }

        return [
            'html' => $html,
            'keys' => Craft::$app->getView()->renderTemplate('design-field/_preview-keys', ['groups' => $groups], View::TEMPLATE_MODE_CP),
            'styles' => $styles,
            'frame' => self::frameUrl($profile),
            'tiles' => self::tiles($registry, $profile),
            'options' => array_map(static fn(array $row) => [
                'handle' => $row['handle'],
                'preview' => $looks[$row['handle']] ?? '',
                'section' => $sections[$row['handle']] ?? '',
                // The option's choices with the labels and icons they had before this page changed them.
                'keys' => array_map(
                    static fn(string $key, array $base) => ['key' => $key, 'label' => $base['label'], 'icon' => $base['icon']],
                    array_map('strval', array_keys($registry->baseOptions[$row['handle']] ?? [])),
                    array_values($registry->baseOptions[$row['handle']] ?? []),
                ),
                'label' => $row['label'],
                'blocks' => $row['blocks'],
                'baseLabel' => $row['base'] !== '' ? Craft::t('design-field', StyleChoices::LABELS[$row['base']]) : Craft::t('design-field', 'varies by block'),
                'choices' => array_map(static fn(string $value) => ['value' => $value, 'label' => Craft::t('design-field', StyleChoices::LABELS[$value])], $row['values']),
            ], StyleChoices::rows($registry)),
        ];
    }

    /**
     * The styles the site uses, for the site-wide swaps: choice value, label, and the
     * options shown that way before any swap.
     *
     * @param Registry $registry
     * @return array{use:list<array{value:string,label:string,groups:list<string>}>,labels:list<array{value:string,label:string}>}
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function styles(Registry $registry): array
    {
        $labels = [];
        foreach ($registry->profiles as $groups) {
            foreach ($groups as $handle => $group) {
                $labels[$handle] ??= $group->label;
            }
        }

        $use = [];
        $all = [];
        foreach (StyleChoices::LABELS as $value => $label) {
            $all[] = ['value' => $value, 'label' => Craft::t('design-field', $label)];
            if (isset($registry->styleUse[$value])) {
                $use[] = [
                    'value' => $value,
                    'label' => Craft::t('design-field', $label),
                    'groups' => array_map(static fn(string $handle) => $labels[$handle] ?? $handle, $registry->styleUse[$value]),
                ];
            }
        }

        return ['use' => $use, 'labels' => $all];
    }

    /**
     * The usage report as one card per block: each finding with every choice of the option
     * shown the way editors see it (icon, swatch, picture or "Aa"), its count, and a plain
     * sentence. Blocks with few entries come last; they are hints, not evidence.
     *
     * @param array{types:array<string,array{entries:int,findings:array<int,array<string,mixed>>}>} $usage UsageReport::summarize()
     * @param array<string,array{name:string,url:string}> $typeInfo Entry type handle => name and edit URL
     * @param Registry $registry
     * @return list<array{type:string,name:string,url:?string,entries:int,few:bool,findings:list<array{handle:string,label:string,verdict:string,sentence:string,chips:string,profile:string,value:?string,valueLabel:string}>}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function usageCards(array $usage, array $typeInfo, Registry $registry): array
    {
        $cards = [];

        foreach ($usage['types'] as $type => $data) {
            $groups = $registry->groupsFor((string)$type);
            // Make it the default and Hide are per block: only for a block with its own profile.
            $own = isset($registry->profiles[(string)$type]) ? (string)$type : null;
            $findings = [];

            foreach ($data['findings'] as $finding) {
                $group = $groups[$finding['handle']] ?? null;
                $label = static fn(?string $key) => $key !== null && $group !== null && isset($group->options[$key]) ? $group->options[$key]['label'] : (string)$key;
                $findings[] = [
                    'handle' => $finding['handle'],
                    'label' => $finding['label'],
                    'verdict' => $finding['verdict'],
                    'sentence' => match (true) {
                        $finding['verdict'] === UsageReport::NEVER_CHANGED && $finding['handle'] === 'variant' => Craft::t('design-field', 'Only the default layout is used. The other layouts can go, or stay for later.'),
                        $finding['verdict'] === UsageReport::NEVER_CHANGED => Craft::t('design-field', 'Never changed from “{default}”. Remove it from this block, or fix that value in the template.', ['default' => $label($finding['default'])]),
                        $finding['verdict'] === UsageReport::ONE_VALUE => Craft::t('design-field', 'Always “{value}”. Make it the default?', ['value' => $label($finding['value'])]),
                        default => (string)$finding['text'],
                    },
                    'chips' => $group !== null ? self::_chips($group, $finding['counts'], $finding['default'], $own) : '',
                    // Tidy up: "Make it the default" for the one value everyone picks.
                    'profile' => (string)$type,
                    'value' => $own !== null && $finding['verdict'] === UsageReport::ONE_VALUE && $group !== null && $finding['value'] !== $group->default ? $finding['value'] : null,
                    'valueLabel' => $label($finding['value'] ?? null),
                ];
            }

            $cards[] = [
                'type' => (string)$type,
                'name' => $typeInfo[$type]['name'] ?? (string)$type,
                'url' => $typeInfo[$type]['url'] ?? null,
                'entries' => $data['entries'],
                'few' => $data['entries'] < 5,
                'findings' => $findings,
            ];
        }

        usort($cards, static fn(array $a, array $b) => [$a['few'], -$a['entries'], $a['name']] <=> [$b['few'], -$b['entries'], $b['name']]);

        return $cards;
    }

    /**
     * A block's layouts for "Make tile pictures": each with its picture, or null when it has
     * none yet. Empty when the block has no layout list of its own, no live preview, or
     * tile pictures are off (`tileImages`).
     *
     * @param Registry $registry
     * @param string $profile
     * @return list<array{key:string,label:string,picture:?string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function tiles(Registry $registry, string $profile): array
    {
        $variant = $registry->profiles[$profile]['variant'] ?? null;

        if ($variant === null || self::frameUrl($profile) === null || Groups::tilePath($profile, '_default') === null) {
            return [];
        }

        $tiles = [];
        foreach ($variant->options as $key => $option) {
            if ($key !== Group::AUTO) {
                $tiles[] = ['key' => (string)$key, 'label' => $option['label'], 'picture' => $option['image']];
            }
        }

        return $tiles;
    }

    /**
     * What the Tidy up tab changed and Save kept: a block's new default, and the choices its
     * picker leaves out, each with readable names, for the "Changed on this page" list.
     *
     * @param array<string,mixed> $inputs Saved settings-page inputs
     * @param Registry $registry
     * @return list<array{kind:string,profile:string,profileName:string,group:string,groupLabel:string,key:string,keyLabel:string}>
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function tidyChanges(array $inputs, Registry $registry): array
    {
        $rows = [];
        $name = static fn(string $profile) => Craft::$app->getEntries()->getEntryTypeByHandle($profile)->name ?? $profile;
        $add = static function(string $kind, string $profile, string $handle, string $key) use (&$rows, $registry, $name): void {
            $group = $registry->profiles[$profile][$handle] ?? null;
            $rows[] = [
                'kind' => $kind,
                'profile' => $profile,
                'profileName' => $name($profile),
                'group' => $handle,
                'groupLabel' => $group->label ?? $handle,
                'key' => $key,
                'keyLabel' => $group->options[$key]['label'] ?? $key,
            ];
        };

        foreach ((array)($inputs['defaults'] ?? []) as $profile => $choices) {
            foreach ((array)$choices as $handle => $key) {
                $add('default', (string)$profile, (string)$handle, (string)$key);
            }
        }
        foreach ((array)($inputs['hidden'] ?? []) as $profile => $choices) {
            foreach ((array)$choices as $handle => $keys) {
                foreach ((array)$keys as $key) {
                    $add('hidden', (string)$profile, (string)$handle, (string)$key);
                }
            }
        }

        return $rows;
    }

    /**
     * The live preview URL for a profile, `{design}` left for the script; null when
     * there is no preview URL or the profile is not an entry type.
     *
     * @param string $profile `type` or `field:type`
     * @return ?string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function frameUrl(string $profile): ?string
    {
        $template = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->blockPreviewUrl));
        $type = str_contains($profile, ':') ? substr($profile, strrpos($profile, ':') + 1) : $profile;

        if ($template === '' || Craft::$app->getEntries()->getEntryTypeByHandle($type) === null) {
            return null;
        }

        $url = str_replace('{type}', rawurlencode($type), $template);

        return UrlHelper::isAbsoluteUrl($url) ? $url : rtrim(UrlHelper::baseSiteUrl(), '/') . '/' . ltrim($url, '/');
    }

    /**
     * One chip per choice: how it looks in the panel, its label and how many entries picked
     * it; choices nobody picked are faded, the default is outlined.
     *
     * @param Group $group
     * @param array<string,int> $counts
     * @param string $default
     * @return string
     */
    private static function _chips(Group $group, array $counts, string $default, ?string $profile = null): string
    {
        $html = '';

        foreach ($group->options as $key => $option) {
            $key = (string)$key;
            $count = $counts[$key] ?? 0;
            $swatch = GroupInput::safeCss($option['swatch']);
            $sample = GroupInput::safeCss($option['sample']);
            $look = match (true) {
                $option['image'] !== null => Html::tag('img', '', ['src' => $option['image'], 'alt' => '', 'class' => 'df-chip-img', 'loading' => 'lazy']),
                $swatch !== null => Html::tag('span', '', ['class' => 'df-swatch', 'style' => ['background' => $swatch]]),
                $option['icon'] !== null => Html::tag('span', Cp::iconSvg($option['icon']) ?? '', ['class' => 'cp-icon small']),
                $sample !== null => Html::tag('span', 'Aa', ['class' => 'df-sample', 'style' => ['font-size' => $sample]]),
                default => '',
            };
            // Tidy up: a choice nobody uses can be hidden from every block (never the default or auto).
            $hide = $profile !== null && $count === 0 && $key !== $default && $key !== Group::AUTO && !in_array($key, $group->hidden, true)
                ? Html::button(Craft::t('design-field', 'Hide'), ['type' => 'button', 'class' => 'df-chip-hide', 'title' => Craft::t('design-field', 'Leave this choice out of this block\'s picker'), 'data' => ['df-hide' => true, 'profile' => $profile, 'group' => $group->handle, 'key' => $key]])
                : '';
            $html .= Html::tag('span',
                $look . Html::tag('span', Html::encode($option['label']), ['class' => 'df-chip-label']) . Html::tag('span', (string)$count, ['class' => 'df-chip-count']) . $hide,
                ['class' => array_filter(['df-chip', $count === 0 ? 'df-chip--unused' : null, $key === $default ? 'df-chip--default' : null, in_array($key, $group->hidden, true) ? 'df-chip--hidden' : null])],
            );
        }

        return Html::tag('div', $html, ['class' => 'df-chips']);
    }

    /**
     * Each option's control as editors see it, for the Preview column: the real input from
     * GroupInput, working (try it), never posted. A block that shows the option stands in
     * for all (the first one, usually the `*` profile for shared options). The scripts
     * Craft's inputs start from are registered as usual, and come with the panel action.
     *
     * @param Registry $registry
     * @return array<string,string> Group handle => HTML
     */
    private static function _looks(Registry $registry): array
    {
        $looks = [];
        // The previews travel as text (a data attribute, or JSON), which the settings page's
        // `settings` namespace never rewrites; their ids and start-up scripts must agree.
        $view = Craft::$app->getView();
        $namespace = $view->getNamespace();
        $view->setNamespace(null);

        foreach ($registry->profiles as $groups) {
            foreach ($groups as $handle => $group) {
                if (isset($looks[$handle])) {
                    continue;
                }
                $html = GroupInput::render($group, $group->default, 'dfLook', false, true);
                // Tied to a form that does not exist, so the page never posts it; and shown
                // whatever the layout (the panel script hides options another choice rules out).
                $html = (string)preg_replace('/<(input|select|textarea|button)\b/', '<$1 form="df-preview"', $html);
                $html = (string)preg_replace('/\sdata-df-show-if="[^"]*"/', '', $html);
                $looks[$handle] = Html::tag('div', $html, ['class' => ['df-look', 'design-field']]);
            }
        }

        $view->setNamespace($namespace);

        return $looks;
    }

    /**
     * Registers the page's CSS and script.
     *
     * @return void
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function registerAssets(): void
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(TilesAsset::class);
        $view->registerCss(self::CSS, [], 'design-field-settings');
        $view->registerJs(self::JS, View::POS_END, 'design-field-settings');
    }

    // Assets
    // =========================================================================

    private const CSS = <<<'CSS'
.df-quick{border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px)}
.df-quick th,.df-quick td,.df-options-scroll th,.df-options-scroll td{vertical-align:middle}
.df-quick thead th,.df-options-scroll thead th{background:var(--gray-050,#f3f4f6)}
.df-quick .select,.df-options-scroll td .select{min-width:14rem}
.df-quick .select select,.df-options-scroll td .select select{width:100%}
.df-options-filter{margin-bottom:var(--s)}
.df-options-scroll{border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px)}
.df-options-scroll table{margin:0}
.df-options-scroll thead th{background:var(--gray-050,#f3f4f6)}
.df-usage-stats{display:flex;flex-wrap:wrap;gap:var(--m);margin:0 0 var(--m)}
.df-usage-stat{min-width:10rem;padding:var(--s) var(--m);border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px)}
.df-usage-stat strong{display:block;font-size:24px;line-height:1.2}
.df-usage-rescan{display:flex;flex-direction:column;justify-content:center;gap:4px;margin-left:auto}
.df-usage-card{margin:0 0 var(--m);border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px);overflow:hidden}
.df-usage-card>header{display:flex;flex-wrap:wrap;align-items:center;gap:6px var(--s);padding:var(--s) var(--m);background:var(--gray-050,#f3f4f6)}
.df-usage-card>header h3{margin:0;font-size:15px}
.df-usage-show{display:block;margin-top:6px;opacity:.7}
.df-usage-finding:hover .df-usage-show,.df-usage-show:focus-visible{opacity:1}
[data-df-panel] .field.df-highlight{outline:3px solid var(--link-color,#2563eb);outline-offset:6px;border-radius:6px}
.df-usage-finding{display:grid;grid-template-columns:minmax(10rem,14rem) 1fr;gap:4px var(--m);padding:var(--s) var(--m);border-top:1px solid var(--hairline-color,#e5e7eb)}
.df-usage-finding[hidden]{display:none}
.df-usage-option{font-weight:600}
.df-usage-option code{display:block;font-weight:400;font-size:11px}
.df-chips{display:flex;flex-wrap:wrap;gap:4px}
.df-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 8px;border-radius:6px;background:var(--gray-100,#e5e7eb);font-size:12px}
.df-chip--unused{opacity:.45}
.df-chip-hide{margin-left:2px;padding:0 4px;border:0;border-radius:4px;background:transparent;color:var(--link-color,#2563eb);font-size:11px;cursor:pointer}
.df-chip .df-chip-hide{opacity:0}
.df-chip:hover .df-chip-hide,.df-chip:focus-within .df-chip-hide{opacity:1}
.df-chip-hide:hover{background:var(--gray-200,#d1d5db)}
.df-chip--hidden{opacity:.35;text-decoration:line-through}
.df-tidy-php{max-width:40rem;max-height:24rem;overflow:auto;margin:0;font-size:12px}
.df-tidy-brief{width:40rem;max-width:80vw;font-family:var(--code-font,monospace);font-size:12px}
.df-tidy-type{margin:0 0 var(--l)}
.df-tidy-type h3{margin:0 0 var(--xs,4px)}
.df-tidy-actions{display:flex;flex-wrap:wrap;align-items:center;gap:var(--s);margin-top:var(--s)}
.df-chip--default{box-shadow:inset 0 0 0 1px var(--gray-500,#6b7280)}
.df-chip-count{font-weight:700}
.df-chip-img{width:40px;height:26px;object-fit:cover;object-position:top;border-radius:3px;background:#fff}
.df-chip .df-swatch{width:14px;height:14px}
.df-usage-sentence{grid-column:2}
.df-option-name{font-size:15px;font-weight:600}
.df-option-handle{display:block;margin-top:2px;font-size:11px;font-weight:400}
.df-quick tbody th{font-size:15px}
.df-look{display:block}
.df-look>.field>.heading{display:none}
.df-look .df-tiles{grid-template-columns:repeat(6,72px)}
.df-look .df-tile-label{display:none}
.df-look .df-slider{max-width:16rem}
.df-section-input{width:10rem}
.df-edit-texts{margin-left:8px;opacity:.55}
tr:hover .df-edit-texts,.df-edit-texts:focus-visible,.df-edit-texts[aria-expanded=true],.df-edit-texts.df-has-texts{opacity:1}
.df-edit-texts.df-has-texts{color:var(--link-color,#2563eb)}
.df-texts>td{background:var(--gray-050,#f3f4f6)}
.df-texts-panel{max-width:34rem}
.df-texts-panel h3{margin:0 0 4px}
.df-texts-panel .df-texts-table{width:100%}
.df-texts-panel .df-texts-table input.text{width:100%;min-width:8rem}
.df-texts-panel .df-texts-table td:last-child{white-space:nowrap;width:1%}
.df-texts-table{width:auto;margin:0 0 6px;background:transparent}
.df-texts-table td,.df-texts-table th{padding:4px 12px 4px 0;vertical-align:middle}
.df-texts-table input.text{width:14rem}
.df-changed::before{content:'';display:inline-block;width:6px;height:6px;margin-right:6px;border-radius:999px;background:var(--link-color,#2563eb);vertical-align:middle}
.df-sp-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:var(--l);align-items:start}
.df-sp-body:not(:has([data-df-frame-wrap]:not([hidden]))){grid-template-columns:1fr}
@media (max-width:1100px){.df-sp-body{grid-template-columns:1fr}}
.df-sp-panel{padding:var(--l);border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px)}
.df-sp-panel.df-customizing{box-shadow:0 0 0 2px var(--link-color,#2563eb)}
.df-customize.btn{font-weight:600;color:var(--blue-700,#1d4ed8);background:var(--blue-050,#eff6ff);box-shadow:inset 0 0 0 1px var(--blue-300,#93c5fd)}
.df-customize.btn:hover{background:var(--blue-100,#dbeafe)}
.df-customize.btn::before{color:inherit}
.df-customize.df-customize--on.btn{color:#fff;background:var(--blue-600,#2563eb);box-shadow:none}
.df-customize.df-customize--on.btn:hover{background:var(--blue-700,#1d4ed8)}
.df-sp-frame{position:sticky;top:var(--l)}
.df-sp-bar{display:flex;align-items:center;gap:var(--s);margin-bottom:var(--s)}
.df-sp-viewport{overflow:hidden;border:1px solid var(--hairline-color,#e5e7eb);border-radius:var(--large-border-radius,8px);background:var(--gray-050,#f3f4f6)}
.df-sp-viewport iframe{display:block;width:1280px;height:520px;border:0;margin-inline:auto;background:#fff;transform-origin:0 0}
.df-showas{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;margin:0 0 6px;font-size:12px;color:var(--light-text-color,#6b7280)}
.df-showas select{font-size:12px;padding:1px 4px}
CSS;

    private const JS = <<<'JS'
(function() {
    var root = document.querySelector('[data-df-settings]');
    if (!root) { return; }
    var data = JSON.parse(root.getAttribute('data-df-settings'));
    // The data sits on the Looks tab; the controls span the Looks and Preview tabs.
    root = document;
    var hidden = document.querySelector('[data-df-inputs]');
    // PHP encodes empty maps as [] — keys set on an array are lost when it is encoded again.
    function map(value) { return value && !Array.isArray(value) && typeof value === 'object' ? value : {}; }
    function normalise(inputs) {
        inputs = map(inputs);
        inputs.styles = map(inputs.styles);
        inputs.groups = map(inputs.groups);
        inputs.profiles = map(inputs.profiles);
        inputs.meta = map(inputs.meta);
        inputs.sections = map(inputs.sections);
        inputs.defaults = map(inputs.defaults);
        inputs.hidden = map(inputs.hidden);
        Object.keys(inputs.defaults).forEach(function(profile) { inputs.defaults[profile] = map(inputs.defaults[profile]); });
        Object.keys(inputs.hidden).forEach(function(profile) { inputs.hidden[profile] = map(inputs.hidden[profile]); });
        Object.keys(inputs.meta).forEach(function(handle) { inputs.meta[handle] = map(inputs.meta[handle]); });
        return inputs;
    }
    var state = normalise(data.inputs);
    var saved = JSON.parse(JSON.stringify(state));
    var profile = data.profile, styles = data.styles, options = data.options, frame = data.frame, tiles = data.tiles || [];
    var timer = null, customizing = false, latest = 0, editing = null, editingInPanel = null, hud = null;
    var panel = root.querySelector('[data-df-panel]');
    var keysBox = document.querySelector('[data-df-keys]');
    var frameWrap = root.querySelector('[data-df-frame-wrap]');
    var iframe = root.querySelector('[data-df-frame]');
    var customize = root.querySelector('[data-df-customize]');
    var filter = root.querySelector('[data-df-options-filter]');

    function style(value) {
        if (value === 'icons') { return {input: 'buttons', iconsOnly: true}; }
        if (value === 'labels') { return {input: 'buttons', iconsOnly: false, textOnly: true}; }
        return {input: value, iconsOnly: false};
    }
    function valueOf(s) {
        if (!s) { return ''; }
        if (s.input !== 'buttons') { return s.input; }
        return s.iconsOnly ? 'icons' : (s.textOnly ? 'labels' : 'buttons');
    }
    function labelOf(value) {
        var match = (data.siteStyles.labels || []).filter(function(c) { return c.value === value; })[0];
        return match ? match.label : value;
    }
    function mine() { return state.profiles[profile] || {}; }
    function savedMine() { return saved.profiles[profile] || {}; }

    // Craft's select box around a plain select.
    function boxed(select, small) {
        var box = document.createElement('div');
        box.className = small ? 'select small' : 'select';
        box.append(select);
        return box;
    }

    // A select: "Default (…)" first, then the styles the option can take.
    function picker(choices, current, defaultLabel, ariaLabel, onChange, small) {
        var select = document.createElement('select');
        select.setAttribute('form', 'df-preview');
        select.setAttribute('aria-label', ariaLabel);
        select.add(new Option(Craft.t('design-field', 'Default ({style})', {style: defaultLabel}), ''));
        choices.forEach(function(c) { select.add(new Option(c.label, c.value)); });
        select.value = current;
        select.addEventListener('change', function() { onChange(select.value); });
        return boxed(select, small);
    }
    function mark(element, changed) {
        element.classList.toggle('df-changed', changed);
        element.title = changed ? Craft.t('design-field', 'Changed, not saved yet') : '';
    }

    function commit() {
        hidden.value = JSON.stringify(state);
        siteWide();
        load(profile);
    }

    // Make tile pictures: each layout of this block loads in a hidden frame (the live preview
    // URL), is drawn to a 480 px JPEG in the browser (modern-screenshot) and saved next to the
    // other tiles. Missing pictures only; with none missing, all again after asking.
    var tilesButton = document.querySelector('[data-df-tiles]');
    var tileSkip = tilesButton ? tilesButton.getAttribute('data-df-tile-skip') : '';
    var tilesStatus = document.querySelector('[data-df-tiles-status]');
    function showTilesButton() {
        if (!tilesButton) { return; }
        tilesButton.hidden = !tiles.length || !frame || !window.modernScreenshot;
    }
    function capture(box) {
        var doc = box.contentDocument, win = box.contentWindow;
        var images = Array.prototype.map.call(doc.images, function(img) {
            return img.complete ? null : new Promise(function(done) { img.onload = img.onerror = done; });
        });
        return Promise.race([Promise.all(images), new Promise(function(done) { setTimeout(done, 4000); })]).then(function() {
            var paint = function(el) { var c = win.getComputedStyle(el).backgroundColor; return c && c !== 'transparent' && c !== 'rgba(0, 0, 0, 0)' ? c : null; };
            return window.modernScreenshot.domToJpeg(doc.body, {
                width: 1280,
                // A popup or drawer is a fixed overlay over an empty page: the window's height then.
                height: Math.min(Math.max(Math.ceil(doc.body.getBoundingClientRect().height), 0) || 900, 900),
                scale: 480 / 1280,
                quality: 0.85,
                // A transparent body shows the page's own background, not black.
                backgroundColor: paint(doc.body) || paint(doc.documentElement) || '#ffffff',
                // Dev toolbars and badges the site names in `tileSkip` are not part of the block.
                filter: function(node) { return !(tileSkip && node.nodeType === 1 && node.matches(tileSkip)); },
                // The copy becomes an SVG image (XML): attribute names XML does not allow, such as
                // Alpine's @click and :class, would make it fail and the picture come out blank.
                onCloneNode: function(root) {
                    var strip = function(el) {
                        Array.prototype.slice.call(el.attributes).forEach(function(a) {
                            if (!/^[A-Za-z_][\w.-]*$/.test(a.name)) { el.removeAttribute(a.name); }
                        });
                        Array.prototype.forEach.call(el.children, strip);
                    };
                    if (root.nodeType === 1) { strip(root); }
                },
            });
        });
    }
    if (tilesButton) {
        showTilesButton();
        tilesButton.addEventListener('click', function() {
            var list = tiles.filter(function(t) { return !t.picture; });
            if (!list.length) {
                if (!confirm(Craft.t('design-field', 'Every layout has a picture. Make them all again from the preview?'))) { return; }
                list = tiles.slice();
            }
            var type = profile, made = 0, failed = 0, i = 0;
            var box = document.createElement('iframe');
            box.setAttribute('aria-hidden', 'true');
            box.tabIndex = -1;
            box.style.cssText = 'position:fixed;left:-10000px;top:0;width:1280px;height:900px;border:0';
            document.body.append(box);
            tilesButton.disabled = true;
            tilesStatus.textContent = '';
            var next = function() {
                if (i >= list.length) {
                    box.remove();
                    tilesButton.disabled = false;
                    tilesStatus.textContent = Craft.t('design-field', '{made} made', {made: made}) + (failed ? ', ' + Craft.t('design-field', '{failed} failed', {failed: failed}) : '');
                    load(type);
                    return;
                }
                var tile = list[i++];
                tilesStatus.textContent = Craft.t('design-field', 'Picture {n} of {total}: {layout}', {n: i, total: list.length, layout: tile.label});
                box.onload = function() {
                    setTimeout(function() {
                        capture(box)
                            .then(function(image) { return Craft.sendActionRequest('POST', 'design-field/tiles/save', {data: {type: type, key: tile.key, image: image}}); })
                            .then(function() { made++; }, function() { failed++; })
                            .then(next);
                    }, 1500);
                };
                box.src = frame.replace('{design}', encodeURIComponent(JSON.stringify({variant: tile.key})));
            };
            next();
        });
    }

    // Tidy up: usage actions become settings-page state, saved with Save like the looks.
    document.addEventListener('click', function(event) {
        var make = event.target.closest && event.target.closest('[data-df-make-default]');
        if (make) {
            state.defaults[make.dataset.profile] = state.defaults[make.dataset.profile] || {};
            state.defaults[make.dataset.profile][make.dataset.group] = make.dataset.key;
            make.disabled = true;
            make.textContent = Craft.t('design-field', 'Default after Save');
            commit();
            return;
        }
        var hide = event.target.closest && event.target.closest('[data-df-hide]');
        if (hide) {
            var hidden = state.hidden[hide.dataset.profile] = state.hidden[hide.dataset.profile] || {};
            var list = hidden[hide.dataset.group] = hidden[hide.dataset.group] || [];
            if (list.indexOf(hide.dataset.key) === -1) { list.push(hide.dataset.key); }
            hide.closest('.df-chip').classList.add('df-chip--hidden');
            hide.remove();
            commit();
        }
    });

    // Tidy up: undo a saved default or hidden choice (saved with Save, like the rest).
    document.addEventListener('click', function(event) {
        var undo = event.target.closest && event.target.closest('[data-df-undo]');
        if (!undo) { return; }
        var profile = undo.dataset.profile, group = undo.dataset.group, key = undo.dataset.key;
        if (undo.dataset.kind === 'default') {
            if (state.defaults[profile]) { delete state.defaults[profile][group]; }
        } else if (state.hidden[profile] && state.hidden[profile][group]) {
            state.hidden[profile][group] = state.hidden[profile][group].filter(function(k) { return k !== key; });
        }
        undo.disabled = true;
        undo.textContent = Craft.t('design-field', 'Undone after Save');
        commit();
    });

    // Quick switch: every option shown in one style is shown in another.
    function siteWide() {
        var body = root.querySelector('[data-df-styles] tbody');
        if (!body || !data.siteStyles || !data.siteStyles.use.length) { return; }
        body.innerHTML = '';
        data.siteStyles.use.forEach(function(use) {
            var tr = document.createElement('tr');
            var name = document.createElement('th');
            name.scope = 'row';
            name.textContent = use.label;
            mark(name, (state.styles[use.value] || '') !== (saved.styles[use.value] || ''));
            var used = document.createElement('td');
            used.textContent = use.groups.length === 1 ? Craft.t('design-field', '1 option') : Craft.t('design-field', '{n} options', {n: use.groups.length});
            used.title = use.groups.join(', ');
            var cell = document.createElement('td');
            var select = document.createElement('select');
            select.setAttribute('form', 'df-preview');
            select.setAttribute('aria-label', Craft.t('design-field', 'Show every {style} option as', {style: use.label}));
            select.add(new Option(Craft.t('design-field', 'Keep ({style})', {style: use.label}), ''));
            data.siteStyles.labels.forEach(function(c) { if (c.value !== use.value) { select.add(new Option(c.label, c.value)); } });
            select.value = state.styles[use.value] || '';
            select.addEventListener('change', function() {
                if (select.value) { state.styles[use.value] = select.value; } else { delete state.styles[use.value]; }
                commit();
            });
            cell.append(boxed(select));
            tr.append(name, used, cell);
            body.append(tr);
        });
    }

    // One row per option: its look on every block.
    function optionsTable() {
        var body = root.querySelector('[data-df-options] tbody');
        if (!body || !options) { return; }
        // Suggestions for the Section column: every heading in use.
        var list = document.getElementById('df-sections') || document.body.appendChild(Object.assign(document.createElement('datalist'), {id: 'df-sections'}));
        list.innerHTML = '';
        var names = {};
        options.forEach(function(o) { if (o.section) { names[o.section] = true; } });
        Object.keys(state.sections).forEach(function(h) { names[state.sections[h]] = true; });
        Object.keys(names).sort().forEach(function(n) { list.append(new Option(n)); });
        var query = filter ? filter.value.trim().toLowerCase() : '';
        body.innerHTML = '';
        options.forEach(function(option) {
            if (option.choices.length < 2) { return; }
            if (query && option.label.toLowerCase().indexOf(query) === -1 && option.handle.toLowerCase().indexOf(query) === -1) { return; }
            var tr = document.createElement('tr');
            tr.setAttribute('data-df-option', option.handle);
            var name = document.createElement('th');
            name.scope = 'row';
            var title = document.createElement('span');
            title.className = 'df-option-name';
            title.textContent = option.label;
            name.append(title);
            var code = document.createElement('code');
            code.className = 'light df-option-handle';
            code.textContent = option.handle;
            mark(name, valueOf(state.groups[option.handle]) !== valueOf(saved.groups[option.handle]));
            var texts = Object.keys(state.meta[option.handle] || {}).length;
            var edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn small df-edit-texts' + (texts ? ' df-has-texts' : '');
            edit.setAttribute('data-icon', 'edit');
            edit.setAttribute('aria-expanded', editing === option.handle ? 'true' : 'false');
            edit.setAttribute('aria-label', Craft.t('design-field', 'Labels & icons of {option}', {option: option.label}));
            edit.title = Craft.t('design-field', 'Labels & icons');
            edit.textContent = texts ? String(texts) : '';
            mark(edit, JSON.stringify(state.meta[option.handle] || {}) !== JSON.stringify(saved.meta[option.handle] || {}));
            edit.addEventListener('click', function() { editing = editing === option.handle ? null : option.handle; optionsTable(); });
            name.append(' ', edit, code);
            var look = document.createElement('td');
            // Server-rendered (SettingsPreview::_looks): the control as editors see it.
            look.innerHTML = option.preview;
            var used = document.createElement('td');
            used.className = 'light';
            used.textContent = option.blocks === 1 ? Craft.t('design-field', '1 block') : Craft.t('design-field', '{n} blocks', {n: option.blocks});
            var cell = document.createElement('td');
            cell.className = 'df-pick';
            cell.append(picker(option.choices, valueOf(state.groups[option.handle]), option.baseLabel,
                Craft.t('design-field', 'Show {group} as', {group: option.label}),
                function(value) {
                    if (value) { state.groups[option.handle] = style(value); } else { delete state.groups[option.handle]; }
                    commit();
                }));
            // Section: the heading this option is listed under in the panel (empty = from the config).
            var sectionCell = document.createElement('td');
            var section = document.createElement('input');
            section.type = 'text';
            section.className = 'text df-section-input';
            section.setAttribute('form', 'df-preview');
            section.setAttribute('list', 'df-sections');
            section.maxLength = 40;
            section.value = state.sections[option.handle] || '';
            section.placeholder = option.section || Craft.t('design-field', 'No section');
            section.setAttribute('aria-label', Craft.t('design-field', 'Section of {option}', {option: option.label}));
            section.addEventListener('change', function() {
                var value = section.value.trim();
                if (value) { state.sections[option.handle] = value; } else { delete state.sections[option.handle]; }
                commit();
            });
            sectionCell.append(section);
            if ((state.sections[option.handle] || '') !== (saved.sections[option.handle] || '')) { mark(sectionCell, true); }
            tr.append(name, look, cell, sectionCell, used);
            body.append(tr);
            if (editing === option.handle) { body.append(textsEditor(option)); }
        });
    }

    // Labels & icons in a popover (Craft's HUD) next to the panel's ✎: the panel stays put.
    function openTexts(option, trigger, label) {
        if (hud) { hud.hide(); }
        editingInPanel = option.handle;
        var box = textsBox(option);
        box.className = 'df-texts-panel';
        var title = document.createElement('h3');
        title.textContent = Craft.t('design-field', 'Labels & icons: {option}', {option: label});
        var note = document.createElement('p');
        note.className = 'light smalltext';
        note.textContent = Craft.t('design-field', 'Labels and icons apply to every block that shows this option.');
        box.prepend(title, note);
        hud = new Garnish.HUD(window.jQuery(trigger), window.jQuery(box), {
            orientations: ['bottom', 'top', 'left', 'right'],
            onHide: function() {
                var closing = hud;
                hud = null;
                editingInPanel = null;
                if (closing) { closing.destroy(); }
            },
        });
    }

    // Labels & icons: what editors read on each choice of one option, on every block.
    function textsEditor(option) {
        var tr = document.createElement('tr');
        tr.className = 'df-texts';
        var td = document.createElement('td');
        td.colSpan = 5;
        td.append(textsBox(option));
        tr.append(td);
        return tr;
    }

    function textsBox(option) {
        var box = document.createElement('div');
        var table = document.createElement('table');
        table.className = 'data df-texts-table';
        var head = document.createElement('tr');
        [Craft.t('design-field', 'Choice'), Craft.t('design-field', 'Label'), Craft.t('design-field', 'Icon')].forEach(function(text) {
            var th = document.createElement('th');
            th.scope = 'col';
            th.textContent = text;
            head.append(th);
        });
        table.append(head);
        var own = state.meta[option.handle] || {};

        // Only a real change is stored: the configured label or icon is no override, and
        // an icon picker announcing the icon it opened with (it does) changes nothing.
        function set(key, name, value) {
            var configured = option.keys.filter(function(choice) { return choice.key === key; })[0] || {};
            if (value === (configured[name] || '')) { value = ''; }
            var list = state.meta[option.handle] || {}, entry = list[key] || {};
            if ((entry[name] || '') === value) { return; }
            if (value) { entry[name] = value; } else { delete entry[name]; }
            if (Object.keys(entry).length) { list[key] = entry; } else { delete list[key]; }
            if (Object.keys(list).length) { state.meta[option.handle] = list; } else { delete state.meta[option.handle]; }
            commit();
        }

        option.keys.forEach(function(choice) {
            var row = document.createElement('tr');
            var keyCell = document.createElement('td');
            var code = document.createElement('code');
            code.textContent = choice.key;
            keyCell.append(code, ' ', choice.label);
            var labelCell = document.createElement('td');
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'text';
            input.setAttribute('form', 'df-preview');
            input.maxLength = 60;
            input.placeholder = choice.label;
            input.value = (own[choice.key] || {}).label || '';
            input.setAttribute('aria-label', Craft.t('design-field', 'Label for {choice}', {choice: choice.label}));
            input.addEventListener('change', function() { set(choice.key, 'label', input.value.trim()); });
            labelCell.append(input);
            var iconCell = document.createElement('td');
            var picker = Craft.ui.createIconPicker({small: true, value: (own[choice.key] || {}).icon || choice.icon || ''});
            var instance = picker.data('iconpicker');
            if (instance) {
                // Removing the icon goes back to the option's own icon (if it has one).
                instance.on('change', function(event) { set(choice.key, 'icon', event.iconName || ''); });
            }
            iconCell.append(picker[0]);
            row.append(keyCell, labelCell, iconCell);
            table.append(row);
        });

        var hint = document.createElement('p');
        hint.className = 'light smalltext';
        hint.textContent = Craft.t('design-field', 'Empty keeps the label or icon from the configuration. Saved values and templates use the keys, so nothing on the site changes but what editors read.');
        box.append(table, hint);
        return box;
    }

    // Customize this block: a look for this block only, under each option of the panel.
    function decorate() {
        var own = mine(), count = Object.keys(own).length;
        customize.textContent = customizing
            ? Craft.t('design-field', 'Done')
            : (count ? Craft.t('design-field', 'Customize this block ({n})', {n: count}) : Craft.t('design-field', 'Customize this block'));
        customize.setAttribute('aria-pressed', customizing ? 'true' : 'false');
        // Customizing: a solid blue "Done", so the mode cannot be missed (red stays Craft's Save).
        customize.classList.toggle('df-customize--on', customizing);
        customize.setAttribute('data-icon', customizing ? 'check' : 'edit');
        var hint = root.querySelector('[data-df-customize-hint]');
        if (hint) {
            hint.textContent = customizing
                ? Craft.t('design-field', 'Pick a look under each option; it applies to this block only.')
                : Craft.t('design-field', 'Different looks for this block only');
        }
        panel.classList.toggle('df-customizing', customizing);
        panel.querySelectorAll('.df-showas').forEach(function(element) { element.remove(); });
        if (!customizing) {
            if (hud) { hud.hide(); }
            return;
        }
        panel.querySelectorAll('[data-df-group]').forEach(function(field) {
            var handle = field.getAttribute('data-df-group'), info = styles[handle];
            if (!info || info.choices.length < 2) { return; }
            var everywhere = state.groups[handle] ? valueOf(state.groups[handle]) : info.base;
            var bar = document.createElement('div');
            bar.className = 'df-showas';
            mark(bar, valueOf(own[handle]) !== valueOf(savedMine()[handle]));
            var option = (options || []).filter(function(o) { return o.handle === handle; })[0];
            var texts = option ? Object.keys(state.meta[handle] || {}).length : 0;
            var pencil = null;
            if (option) {
                pencil = document.createElement('button');
                pencil.type = 'button';
                pencil.className = 'btn small df-edit-texts' + (texts ? ' df-has-texts' : '');
                pencil.setAttribute('data-icon', 'edit');
                pencil.setAttribute('aria-expanded', editingInPanel === handle ? 'true' : 'false');
                pencil.setAttribute('aria-label', Craft.t('design-field', 'Labels & icons of {option}', {option: info.label}));
                pencil.title = Craft.t('design-field', 'Labels & icons (every block)');
                pencil.textContent = texts ? String(texts) : '';
                pencil.addEventListener('click', function() { openTexts(option, pencil, info.label); });
                // The popover stays open while the panel reloads: it moves to the new ✎.
                if (hud && editingInPanel === handle) {
                    hud.$trigger = window.jQuery(pencil);
                    hud.updateSizeAndPosition(true);
                }
            }
            bar.append(Craft.t('design-field', 'This block:'), picker(info.choices, valueOf(own[handle]), labelOf(everywhere),
                Craft.t('design-field', 'Show {group} as, on this block', {group: info.label}),
                function(value) {
                    var list = mine();
                    if (value) { list[handle] = style(value); } else { delete list[handle]; }
                    if (Object.keys(list).length) { state.profiles[profile] = list; } else { delete state.profiles[profile]; }
                    commit();
                }, true));
            if (pencil) { bar.append(pencil); }
            var heading = field.querySelector(':scope > .heading');
            // A chip sits inline in its row: its menu follows it there.
            if (field.classList.contains('df-chip-item')) { field.after(bar); }
            else if (heading) { heading.insertAdjacentElement('afterend', bar); } else { field.prepend(bar); }

        });
    }

    function reload(delay) {
        clearTimeout(timer);
        timer = setTimeout(function() {
            frameWrap.hidden = !frame;
            if (!frame) { return; }
            var design = window.DesignField.keys(panel.querySelector('.design-field'));
            var url = frame.replace('{design}', encodeURIComponent(JSON.stringify(design)));
            iframe.dataset.dfSrc = url;
            // Setting src adds a browser history entry per preview, so Back would step through
            // old previews (each reloading the block) instead of leaving the page.
            if (iframe.getAttribute('src') && iframe.contentWindow) {
                try { iframe.contentWindow.location.replace(url); return; } catch (e) { /* fall through */ }
            }
            iframe.src = url;
        }, delay);
    }

    // Only the latest request may change the page: answers can arrive out of order.
    function load(name) {
        var ticket = ++latest;
        Craft.sendActionRequest('POST', 'design-field/preview/panel', {data: {profile: name, inputs: JSON.stringify(state)}})
            .then(function(response) {
                if (ticket !== latest) { return; }
                // Another block: its options differ, so a labels popover closes.
                if (name !== profile && hud) { hud.hide(); }
                profile = name;
                styles = response.data.styles;
                options = response.data.options;
                frame = response.data.frame;
                tiles = response.data.tiles || [];
                showTilesButton();
                panel.innerHTML = response.data.html;
                if (keysBox) { keysBox.innerHTML = response.data.keys; }
                // The previews' inputs start from the scripts below, so their rows come first.
                optionsTable();
                // Craft starts its button groups and lightswitches from the scripts that come with the HTML.
                return Craft.appendHeadHtml(response.data.headHtml)
                    .then(function() { return Craft.appendBodyHtml(response.data.bodyHtml); })
                    .then(function() {
                        window.DesignField.refreshAll();
                        optionsTable();
                        decorate();
                        reload(0);
                        // Others (the Usage tab's "Show in preview") wait for this block's panel.
                        document.dispatchEvent(new CustomEvent('df:panel', {detail: {profile: name}}));
                        var url = new URL(location.href);
                        url.searchParams.set('preview', name);
                        history.replaceState(null, '', url.toString());
                    });
            })
            .catch(function(error) {
                if (ticket !== latest) { return; }
                // Keep the Block menu on the block the panel still shows.
                root.querySelector('[data-df-profile]').value = profile;
                Craft.cp.displayError((error && error.response && error.response.data && error.response.data.message) || Craft.t('design-field', 'Could not load the preview.'));
            });
    }

    ['click', 'change', 'input', 'keyup'].forEach(function(type) {
        panel.addEventListener(type, function(event) {
            if (!event.target.closest('.df-showas')) { reload(300); }
        });
    });
    root.querySelector('[data-df-profile]').addEventListener('change', function(event) { load(event.target.value); });
    customize.addEventListener('click', function() { customizing = !customizing; decorate(); });
    if (filter) { filter.addEventListener('input', optionsTable); }
    // The block is drawn at a real device width and scaled down to fit the column, so its
    // breakpoints are the device's, not the column's: Desktop never shows the tablet layout.
    // A bigger screen shows it bigger, up to 100%.
    var viewport = iframe.parentElement;
    var scaleLabel = root.querySelector('[data-df-scale]');
    var device = 1280;
    var screen = 900;
    var blockHeight = 520;
    function fit() {
        var room = viewport.clientWidth;
        if (!room) { return; }
        var scale = Math.min(1, room / device);
        iframe.style.width = device + 'px';
        iframe.style.height = blockHeight + 'px';
        iframe.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
        viewport.style.height = Math.ceil(blockHeight * scale) + 'px';
        scaleLabel.textContent = device + ' px' + (scale < 1 ? ' · ' + Math.round(scale * 100) + '%' : '');
    }
    // The body's own height, which also shrinks (the document's scrollHeight never drops below
    // the frame). A screen-high (vh) section grows with the frame: when the body grows by just
    // what the frame did, the frame takes the device's screen height and the block scrolls
    // inside it, as on a real screen.
    var grew = 0;
    var lastRaw = 0;
    var screenHigh = false;
    function measure() {
        try {
            var body = iframe.contentDocument && iframe.contentDocument.body;
            if (!body || screenHigh) { return; }
            var raw = Math.ceil(body.getBoundingClientRect().height);
            if (grew && Math.abs(raw - lastRaw - grew) <= 2) { screenHigh = true; blockHeight = screen; fit(); return; }
            lastRaw = raw;
            var height = Math.min(Math.max(raw, 200), 4000);
            if (Math.abs(height - blockHeight) > 1) { grew = Math.max(height - blockHeight, 0); blockHeight = height; fit(); }
        } catch (e) { /* another origin: keep the height */ }
    }
    root.querySelectorAll('[data-df-width]').forEach(function(button) {
        button.addEventListener('click', function() {
            device = parseInt(button.getAttribute('data-df-width'), 10) || 1280;
            screen = parseInt(button.getAttribute('data-df-screen'), 10) || 900;
            grew = 0;
            lastRaw = 0;
            screenHigh = false;
            root.querySelectorAll('[data-df-width]').forEach(function(other) {
                other.classList.toggle('active', other === button);
                other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            });
            fit();
            measure();
        });
    });
    // Images and lazy parts change the height after load: follow them, not just the first measure.
    iframe.addEventListener('load', function() {
        grew = 0;
        lastRaw = 0;
        screenHigh = false;
        measure();
        try { new iframe.contentWindow.ResizeObserver(measure).observe(iframe.contentDocument.body); } catch (e) { /* no observer: the load measure stands */ }
    });
    new ResizeObserver(fit).observe(viewport);

    hidden.value = JSON.stringify(state);
    siteWide();
    optionsTable();
    decorate();
    reload(0);
})();
JS;
}
