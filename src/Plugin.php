<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\db\Query;
use craft\db\Table;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use InvalidArgumentException;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\Starter;
use wmd\designfield\helpers\UsageReport;
use wmd\designfield\models\Settings;
use wmd\designfield\services\Converter;
use wmd\designfield\services\Groups;
use wmd\designfield\services\Health;
use wmd\designfield\services\Importer;
use wmd\designfield\services\TemplateChecker;
use wmd\designfield\services\Tidy;
use wmd\designfield\services\Usage;
use wmd\designfield\variables\DesignFieldVariable;
use wmd\designfield\web\SettingsPreview;
use yii\base\Event;
use yii\db\Expression;
use yii\web\Response;

/**
 * Design Field: one field for every design option of a block.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Groups $groups
 * @property-read Converter $converter
 * @property-read Importer $importer
 * @property-read Usage $usage
 * @property-read TemplateChecker $templateChecker
 *
 * @author WMD
 * @since 1.0.0
 */
class Plugin extends BasePlugin
{
    // Const Properties
    // =========================================================================

    /**
     * Edition: free, with every feature.
     */
    public const EDITION_STANDARD = 'standard';

    /**
     * Edition: paid, for people who like it. Nothing is gated; it buys support and a say in the roadmap.
     */
    public const EDITION_PRO = 'pro';

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var array<string,array<string,mixed>> Settings page tabs, worked out while its HTML renders
     */
    private array $_settingsTabs = [];

    /**
     * @var string Namespaced id of the settings page tab that opens first
     */
    private string $_selectedTab = '';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [self::EDITION_STANDARD, self::EDITION_PRO];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'groups' => Groups::class,
                'converter' => Converter::class,
                'importer' => Importer::class,
                'usage' => Usage::class,
                'health' => Health::class,
                'templateChecker' => TemplateChecker::class,
                'tidy' => Tidy::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = Design::class;
        });

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('designField', DesignFieldVariable::class);
        });
    }

    /**
     * Returns the groups service.
     *
     * @return Groups
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getGroups(): Groups
    {
        /** @var Groups */
        return $this->get('groups');
    }

    /**
     * Returns the converter service, which moves old option fields into a Design field.
     *
     * @return Converter
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getConverter(): Converter
    {
        /** @var Converter */
        return $this->get('converter');
    }

    /**
     * Returns the importer service, which builds config from existing option fields.
     *
     * @return Importer
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getImporter(): Importer
    {
        /** @var Importer */
        return $this->get('importer');
    }

    /**
     * Returns the usage service, which reports the design choices editors make.
     *
     * @return Usage
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getUsage(): Usage
    {
        /** @var Usage */
        return $this->get('usage');
    }

    /**
     * Returns the tidy-up service: option fields on entry types, their config, the agent brief.
     *
     * @return Tidy
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getTidy(): Tidy
    {
        /** @var Tidy */
        return $this->get('tidy');
    }

    /**
     * Returns the health check service.
     *
     * @return Health
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getHealth(): Health
    {
        /** @var Health */
        return $this->get('health');
    }

    /**
     * Returns the template check service.
     *
     * @return TemplateChecker
     *
     * @author WMD
     * @since 1.0.0
     */
    public function getTemplateChecker(): TemplateChecker
    {
        /** @var TemplateChecker */
        return $this->get('templateChecker');
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return $this->_settingsResponse(false);
    }

    /**
     * @inheritdoc
     */
    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->_settingsResponse(true);
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        $registry = null;
        $error = null;

        try {
            $registry = $this->getGroups()->getRegistry();
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        $groups = $this->getGroups();
        $column = static fn(array $rows, string $key) => array_map(static fn(array $row) => (string)($row[$key] ?? ''), $rows);

        // Preview: one profile's real panel, picked with ?preview=<profile>.
        $labels = [];
        foreach ($groups->profileOptions([], false) as $option) {
            if (isset($option['value'])) {
                $labels[$option['value']] = $option['label'];
            }
        }
        $previewProfile = null;
        $previewOptions = [];
        if ($registry !== null && $registry->profileNames() !== []) {
            foreach ($registry->profileNames() as $name) {
                $previewOptions[] = ['label' => $labels[$name] ?? $name, 'value' => $name];
            }
            usort($previewOptions, static fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
            $requested = (string)Craft::$app->getRequest()->getQueryParam('preview', '');
            $previewProfile = isset($registry->profiles[$requested]) ? $requested : ($this->_busiestBlock($registry) ?? $previewOptions[0]['value']);
        }

        // Usage report: counts every entry, so it runs only when asked (?usage=1).
        $usage = null;
        if ($error === null && Craft::$app->getRequest()->getQueryParam('usage')) {
            // ?months=12: only blocks saved in the last year, for the yearly "what do editors still touch" review.
            $months = max(0, (int)Craft::$app->getRequest()->getQueryParam('months', 0));
            $report = $this->getUsage()->report([], $months > 0 ? new \DateTimeImmutable("-$months months") : null);
            if ($months === 0) {
                // Counted everything anyway: refresh "Most used" in long dropdowns too.
                $this->getUsage()->storeCounts($report);
            }
            $usage = UsageReport::summarize($report);
            $usage['months'] = $months;
            $usage['typeInfo'] = [];
            foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
                $usage['typeInfo'][$type->handle] = ['name' => $type->name, 'url' => UrlHelper::cpUrl("settings/entry-types/$type->id")];
            }
        }

        if ($previewProfile !== null) {
            SettingsPreview::registerAssets();
        }

        $health = $error === null ? $this->getHealth()->check() : [];
        $templateFindings = $error === null ? $this->getHealth()->templates() : [];
        // Tidy up: option fields on entry types, scanned only when asked (?tidy=1).
        $tidyRows = $error === null && Craft::$app->getRequest()->getQueryParam('tidy') ? $this->getTidy()->rows() : null;
        $selected = $usage !== null || $tidyRows !== null ? 'df-tab-usage' : ($previewProfile !== null ? 'df-tab-looks' : 'df-tab-config');
        $this->_prepareTabs($selected, array_merge($health, $templateFindings), $error);

        return Craft::$app->getView()->renderTemplate('design-field/_settings', [
            'selectedTab' => $selected,
            'usage' => $usage,
            'tidyRows' => $tidyRows,
            'starterFrameworks' => Starter::FRAMEWORKS,
            'tidyChanges' => $registry !== null ? SettingsPreview::tidyChanges($settings->inputs, $registry) : [],
            'settings' => $settings,
            'registry' => $registry,
            'error' => $error,
            'health' => $health,
            'templateFindings' => $templateFindings,
            'previewProfile' => $previewProfile,
            'previewOptions' => $previewOptions,
            'usageCards' => $usage !== null ? SettingsPreview::usageCards($usage, $usage['typeInfo'], $registry) : [],
            'previewPayload' => $previewProfile !== null ? SettingsPreview::payload($registry, $previewProfile) : null,
            'siteStyles' => $registry !== null ? SettingsPreview::styles($registry) : ['use' => [], 'labels' => []],
            'groupProfileOptions' => $groups->profileOptions($column($settings->groupRows, 'profile'), true),
            'profileOptions' => $groups->profileOptions($column($settings->profileRows, 'profile'), false),
            'tokenFileOptions' => $groups->tokenFileOptions($column($settings->groupRows, 'tokens')),
            'groupRefOptions' => $groups->groupRefOptions($settings->groupRows, $column($settings->optionRows, 'group')),
            // For presets `*` is not a fallback: its presets show on every block.
            'presetProfileOptions' => array_map(
                static fn(array $option) => ($option['value'] ?? null) === Registry::FALLBACK_PROFILE
                    ? ['label' => Craft::t('design-field', '* (every block)')] + $option
                    : $option,
                $groups->profileOptions($column($settings->presetTableRows(), 'profile'), false),
            ),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * The settings page with its tabs in the pane header, where Craft draws them for
     * every CP page (Craft's own settings page has no tabs). One form and one Save.
     *
     * @param bool $readOnly
     * @return Response
     */
    private function _settingsResponse(bool $readOnly): Response
    {
        $view = Craft::$app->getView();
        $settingsHtml = $view->namespaceInputs(
            fn() => $readOnly ? (string)Html::disableInputs(fn() => $this->settingsHtml()) : (string)$this->settingsHtml(),
            'settings',
        );

        /** @var \craft\web\Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('design-field/_settings-page', [
            'plugin' => $this,
            'settingsHtml' => $settingsHtml,
            'readOnly' => $readOnly,
            'tabs' => $this->_settingsTabs,
            'selectedTab' => $this->_selectedTab,
        ]);
    }

    /**
     * The profile of the block type used most on the site, so the Preview tab opens on a
     * block it can show (`*` is no block type, and an unused type has nothing to show).
     *
     * @param Registry $registry
     * @return ?string
     */
    private function _busiestBlock(Registry $registry): ?string
    {
        $profiles = [];
        foreach ($registry->profileNames() as $name) {
            $type = Craft::$app->getEntries()->getEntryTypeByHandle(str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name);
            if ($type !== null) {
                $profiles[$type->id] ??= $name;
            }
        }

        if ($profiles === []) {
            return null;
        }

        $busiest = (new Query())
            ->select(['typeId'])
            ->from(Table::ENTRIES)
            ->where(['typeId' => array_keys($profiles)])
            ->groupBy(['typeId'])
            ->orderBy(new Expression('COUNT(*) DESC'))
            ->scalar();

        return $busiest !== false && $busiest !== null ? $profiles[(int)$busiest] : null;
    }

    /**
     * Works out the tabs (Looks, Preview, Presets, Usage, Configuration) for the page around the
     * settings HTML; pane ids carry the `settings` namespace the HTML is rendered in.
     *
     * @param string $selected Pane id that opens first
     * @param array<int,array{level:string,message:string,url:?string}> $health
     * @param ?string $error
     * @return void
     */
    private function _prepareTabs(string $selected, array $health, ?string $error): void
    {
        $view = Craft::$app->getView();
        $problems = count(array_filter($health, static fn(array $finding) => $finding['level'] !== Health::INFO));
        $broken = $error !== null || array_filter($health, static fn(array $finding) => $finding['level'] === Health::ERROR) !== [];
        $tabs = [
            'df-tab-looks' => Craft::t('design-field', 'Looks'),
            'df-tab-preview' => Craft::t('design-field', 'Preview'),
            'df-tab-presets' => Craft::t('design-field', 'Presets'),
            'df-tab-usage' => Craft::t('design-field', 'Tidy up'),
            'df-tab-config' => $problems > 0
                ? Craft::t('design-field', 'Configuration ({n})', ['n' => $problems])
                : Craft::t('design-field', 'Configuration'),
        ];

        $this->_settingsTabs = [];
        foreach ($tabs as $pane => $label) {
            $id = $view->namespaceInputId($pane);
            $this->_settingsTabs[$id] = array_filter([
                'label' => $label,
                'url' => "#$id",
                'class' => $pane === 'df-tab-config' && $broken ? 'error' : null,
            ]);
        }
        $this->_selectedTab = $view->namespaceInputId($selected);
    }
}
