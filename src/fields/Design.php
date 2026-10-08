<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use GraphQL\Type\Definition\Type;
use wmd\designfield\events\DefinePanelGroupsEvent;
use wmd\designfield\helpers\OptionOrder;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\Plugin;
use wmd\designfield\web\GroupInput;
use yii\base\InvalidConfigException;
use yii\db\Schema;

/**
 * Design field: every design option of a block in one field.
 *
 * Stores named keys as JSON (`{"tone":"surface","spacing":"tight"}`); the
 * class strings live in config files, so Tailwind sees them and editors
 * can only pick, never type, a class.
 *
 * @author WMD
 * @since 1.0.0
 */
class Design extends Field implements PreviewableFieldInterface
{
    // Public Properties
    // =========================================================================

    /**
     * @event DefinePanelGroupsEvent Before a panel is drawn: hide or relabel choices for the element being edited.
     */
    public const EVENT_DEFINE_PANEL_GROUPS = 'definePanelGroups';

    /**
     * View mode: every option open in a grid.
     */
    public const VIEW_PANEL = 'panel';

    /**
     * View mode: one line of the choices that differ from the defaults, with Edit to open the panel.
     */
    public const VIEW_SUMMARY = 'summary';

    /**
     * View mode: a closed "Design" section that opens on click.
     */
    public const VIEW_COLLAPSED = 'collapsed';

    /**
     * Option layout: each section on its own row, under its heading.
     */
    public const LAYOUT_SECTIONS = 'sections';

    /**
     * Option layout: every option one after another, without headings.
     */
    public const LAYOUT_INLINE = 'inline';

    /**
     * Option layout: sections with their headings, small ones sharing a row.
     */
    public const LAYOUT_SIDE = 'side';

    /**
     * @var string Profile from `config/design-field.php`; empty picks one by entry type handle
     */
    public string $profile = '';

    /**
     * @var string How the options show on an entry: panel, summary or collapsed (like Matrix's view mode)
     */
    public string $viewMode = self::VIEW_PANEL;

    /**
     * @var string Options in sections (each on its own row), sections side by side, or inline (one after another, no headings)
     */
    public string $optionLayout = self::LAYOUT_SECTIONS;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('design-field', 'Design');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): string
    {
        return 'palette';
    }

    /**
     * @inheritdoc
     */
    public static function phpType(): string
    {
        return DesignValue::class;
    }

    /**
     * @inheritdoc
     */
    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    /**
     * @inheritdoc
     */
    public static function isRequirable(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        $options = [['label' => Craft::t('design-field', 'Automatic (by entry type)'), 'value' => '']];

        foreach (Plugin::getInstance()->getGroups()->getRegistry()->profileNames() as $name) {
            $options[] = ['label' => $name, 'value' => $name];
        }

        return Cp::selectFieldHtml([
            'label' => Craft::t('design-field', 'View Mode'),
            'instructions' => Craft::t('design-field', 'How the options show on an entry.'),
            'id' => 'viewMode',
            'name' => 'viewMode',
            'value' => $this->viewMode,
            'options' => [
                ['label' => Craft::t('design-field', 'As a panel (every option open)'), 'value' => self::VIEW_PANEL],
                ['label' => Craft::t('design-field', 'As a summary (what is set, with Edit)'), 'value' => self::VIEW_SUMMARY],
                ['label' => Craft::t('design-field', 'Collapsed (opens on click)'), 'value' => self::VIEW_COLLAPSED],
            ],
        ]) . Cp::selectFieldHtml([
            'label' => Craft::t('design-field', 'Option Layout'),
            'instructions' => Craft::t('design-field', 'Sections come from `sections` in `config/design-field.php` and the settings page.'),
            'id' => 'optionLayout',
            'name' => 'optionLayout',
            'value' => $this->optionLayout,
            'options' => [
                ['label' => Craft::t('design-field', 'In sections (each on its own row, with a heading)'), 'value' => self::LAYOUT_SECTIONS],
                ['label' => Craft::t('design-field', 'Sections side by side (small sections share a row)'), 'value' => self::LAYOUT_SIDE],
                ['label' => Craft::t('design-field', 'Inline (one after another, no headings)'), 'value' => self::LAYOUT_INLINE],
            ],
        ]) . Cp::selectFieldHtml([
            'label' => Craft::t('design-field', 'Profile'),
            'instructions' => Craft::t('design-field', 'Which option groups to show. Profiles are defined in `config/design-field.php`. Automatic tries `field:entryType` (for nested entries), then the entry type, product type or category group handle, then `*`.'),
            'id' => 'profile',
            'name' => 'profile',
            'value' => $this->profile,
            'options' => $options,
        ]) . Html::tag('p', Html::a(
            Craft::t('design-field', 'Looks, presets and usage of every Design field: Design Field settings'),
            UrlHelper::cpUrl('settings/plugins/design-field'),
            ['class' => 'go'],
        ));
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof DesignValue) {
            return $value;
        }

        $keys = is_string($value) ? Json::decodeIfJson($value) : $value;

        return Plugin::getInstance()->getGroups()->getRegistry()->value(
            is_array($keys) ? $keys : [],
            $this->_profilesFor($element),
        );
    }

    /**
     * @inheritdoc
     */
    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element = null): mixed
    {
        // A switched-off toggle posts an empty value; it means the first option.
        if (is_array($value)) {
            $groups = Plugin::getInstance()->getGroups()->getRegistry()->groupsFor($this->_profilesFor($element));
            foreach ($groups as $handle => $group) {
                if ($group->input === Group::INPUT_TOGGLE && array_key_exists($handle, $value) && (string)$value[$handle] === '') {
                    $value[$handle] = (string)array_key_first($group->options);
                }
            }
        }

        return $this->normalizeValue($value, $element);
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof DesignValue) {
            return $value->toArray();
        }

        return parent::serializeValue($value, $element);
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        return [
            [
                function(ElementInterface $element) {
                    $value = $element->getFieldValue($this->handle);

                    if (!$value instanceof DesignValue) {
                        return;
                    }

                    foreach ($value->invalid() as $group => $key) {
                        $element->addError("field:$this->handle", Craft::t('design-field', '“{key}” is not an option of {group}.', [
                            'key' => $key,
                            'group' => $value->groups()[$group]->label,
                        ]));
                    }
                },
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof DesignValue) {
            return '';
        }

        $labels = [];

        foreach (array_keys($value->groups()) as $group) {
            $token = $value->get($group);

            if (!$token->isDefault) {
                $labels[] = $token->label;
            }
        }

        return Html::encode(implode(', ', $labels));
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => Type::string(),
            'description' => 'Selected option key per design group, as JSON.',
            'resolve' => function($source) {
                $value = $source->getFieldValue($this->handle);

                return $value instanceof DesignValue ? Json::encode($value->keys()) : null;
            },
        ];
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlMutationArgumentType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => Type::string(),
            'description' => 'JSON object of group handle => option key.',
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['viewMode'], 'in', 'range' => [self::VIEW_PANEL, self::VIEW_SUMMARY, self::VIEW_COLLAPSED]];
        $rules[] = [['optionLayout'], 'in', 'range' => [self::LAYOUT_SECTIONS, self::LAYOUT_SIDE, self::LAYOUT_INLINE]];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        if (!$value instanceof DesignValue) {
            $value = $this->normalizeValue($value, $element);
        }

        $keys = $value->keys();
        [$groups, $hidden] = $this->_panelGroups($value->groups(), $keys, $element);
        $presets = Plugin::getInstance()->getGroups()->getRegistry()->presetsFor($this->_profilesFor($element), $value->groups());
        $html = GroupInput::presets($presets, $groups, $this->static);

        $html .= GroupInput::fields($groups, $keys, $this->handle, $this->static, $this->optionLayout, $this->_usageFor($groups, $element), $hidden);

        return GroupInput::view($this->viewMode, $value, Html::tag('div', $html, [
            'class' => ['design-field', $this->optionLayout === self::LAYOUT_SIDE ? 'df-side' : null],
        ]));
    }

    // Private Methods
    // =========================================================================

    /**
     * The panel's options after EVENT_DEFINE_PANEL_GROUPS: handlers may replace a group or
     * hide one, never add or drop one (every option is posted and stored).
     *
     * @param array<string,Group> $groups
     * @param array<string,string> $keys
     * @param ?ElementInterface $element
     * @return array{0:array<string,Group>,1:string[]} The groups, and the handles hidden in this panel
     */
    private function _panelGroups(array $groups, array $keys, ?ElementInterface $element): array
    {
        if (!$this->hasEventHandlers(self::EVENT_DEFINE_PANEL_GROUPS)) {
            return [$groups, []];
        }

        $event = new DefinePanelGroupsEvent(['element' => $element, 'groups' => $groups, 'keys' => $keys]);
        $this->trigger(self::EVENT_DEFINE_PANEL_GROUPS, $event);

        foreach ($groups as $handle => $group) {
            if (($event->groups[$handle] ?? null) instanceof Group) {
                $groups[$handle] = $event->groups[$handle];
            }
        }

        return [$groups, array_values(array_intersect(array_map('strval', array_keys($groups)), $event->hiddenGroups))];
    }

    /**
     * Pick counts for "Most used" in long dropdowns; only read when the panel has one.
     *
     * @param array<string,Group> $groups
     * @param ?ElementInterface $element
     * @return array<string,array<string,int>> Option => key => count
     */
    private function _usageFor(array $groups, ?ElementInterface $element): array
    {
        foreach ($groups as $group) {
            if ($group->input === Group::INPUT_SELECT && count($group->options) >= OptionOrder::LONG) {
                return OptionOrder::countsFor(Plugin::getInstance()->getUsage()->counts(), self::typeHandle($element));
            }
        }

        return [];
    }

    /**
     * Profile candidates for an element, most specific first.
     *
     * The field setting wins; otherwise the element's own candidates.
     *
     * @param ?ElementInterface $element
     * @return string[]
     */
    private function _profilesFor(?ElementInterface $element): array
    {
        if ($this->profile !== '') {
            return [$this->profile];
        }

        return self::profilesFor($element);
    }

    // Public Static Helpers
    // =========================================================================

    /**
     * Profile candidates for any element: `{field}:{type}` for a nested entry
     * (the same block can differ per builder), then the type handle: entry
     * type, Commerce product type, or category group.
     *
     * @param ?ElementInterface $element
     * @return string[]
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function profilesFor(?ElementInterface $element): array
    {
        $type = self::typeHandle($element);

        if ($type === null) {
            return [];
        }

        $candidates = [];

        if ($element instanceof Entry && $element->fieldId) {
            $owner = Craft::$app->getFields()->getFieldById($element->fieldId);

            if ($owner !== null) {
                $candidates[] = "$owner->handle:$type";
            }
        }

        $candidates[] = $type;

        return $candidates;
    }

    /**
     * The element's type handle: entry type, product type or category group.
     *
     * @param ?ElementInterface $element
     * @return ?string
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function typeHandle(?ElementInterface $element): ?string
    {
        try {
            if ($element instanceof Category) {
                return $element->getGroup()->handle;
            }

            if ($element !== null && method_exists($element, 'getType')) {
                return $element->getType()->handle ?? null;
            }
        } catch (InvalidConfigException) {
            return null;
        }

        return null;
    }
}
