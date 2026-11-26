<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\fields;

use CraftCms\Cms\Element\Concerns\NestedElement;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Field\Contracts\InputSchemaFieldInterface;
use CraftCms\Cms\Field\Contracts\McpOutputFieldInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Mcp\McpOutputContext;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field as UiField;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Closure;
use Illuminate\Support\Facades\Event;
use GraphQL\Type\Definition\Type;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Override;
use stdClass;
use wmd\designfield\events\DefinePanelGroups;
use wmd\designfield\fields\conditions\DesignVariantConditionRule;
use wmd\designfield\helpers\OptionOrder;
use wmd\designfield\helpers\Registry;
use wmd\designfield\http\SettingsScreen;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;
use wmd\designfield\services\Usage;
use wmd\designfield\web\controls\PresetBlock;
use wmd\designfield\web\OptionControls;

/**
 * The Design field on Craft 6: one JSON value of option handle => choice key,
 * read through the registry as a DesignValue.
 *
 * Same storage as the Craft 5 plugin, so imported values keep working. The
 * input is one control per option, built on Craft's own UI kit.
 *
 * Craft's MCP tools read the value as a map of the element's options and take
 * the same map back, checked against {@see getInputSchema()}.
 *
 * @author WMD
 * @since 2.0.0
 */
class Design extends Field implements InputSchemaFieldInterface, McpOutputFieldInterface
{
    // Const Properties
    // =========================================================================

    /** Every option open. */
    public const VIEW_PANEL = 'panel';

    /** A line saying what is set, the options behind Edit. */
    public const VIEW_SUMMARY = 'summary';

    /** A closed section that opens on click. */
    public const VIEW_COLLAPSED = 'collapsed';

    /** Each section on its own row, with a heading. */
    public const LAYOUT_SECTIONS = 'sections';

    /** One option after another, no headings. */
    public const LAYOUT_INLINE = 'inline';

    /** Sections side by side. */
    public const LAYOUT_SIDE = 'side';

    // Public Properties
    // =========================================================================

    /**
     * Profile name; empty picks the element's own ({ownerField}:{type}, then {type}).
     */
    public string $profile = '';

    /**
     * How the options show on an element: panel, summary or collapsed.
     */
    public string $viewMode = self::VIEW_PANEL;

    /**
     * How the options are laid out: in sections, sections side by side, or inline.
     */
    public string $optionLayout = self::LAYOUT_SECTIONS;

    // Public Methods
    // =========================================================================

    #[Override]
    public static function displayName(): string
    {
        return 'Design';
    }

    #[Override]
    public static function icon(): string
    {
        return 'palette';
    }

    #[Override]
    public static function phpType(): string
    {
        return DesignValue::class;
    }

    #[Override]
    public static function dbType(): string
    {
        return Query::TYPE_JSON;
    }

    #[Override]
    public static function isRequirable(): bool
    {
        return false;
    }

    #[Override]
    public function normalizeValue(mixed $value, ?ElementInterface $element): DesignValue
    {
        if ($value instanceof DesignValue) {
            return $value;
        }

        // Read with the safe registry: a configuration that does not build must not stop
        // every page and every save that touches a Design value.
        return app(Groups::class)->safeRegistry()->valueFromInput($value, $this->profilesFor($element));
    }

    #[Override]
    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): DesignValue
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value) ?? $value;
        }

        if (app(Groups::class)->safeRegistry()->standIn) {
            return $this->requestValueWithoutOptions($value, $element);
        }

        // The editor posts a switch as true/false (a plain form: its on key, or '' when off).
        // Sliders come as places on their scale (Registry::SLIDER_KEY), mapped by the registry.
        if (is_array($value)) {
            foreach (app(Groups::class)->safeRegistry()->groupsFor($this->profilesFor($element)) as $handle => $group) {
                if ($group->input !== Group::INPUT_TOGGLE || count($group->options) !== 2 || !array_key_exists($handle, $value)) {
                    continue;
                }

                [$off, $on] = array_map(strval(...), array_keys($group->options));

                $value[$handle] = match ($value[$handle]) {
                    true => $on,
                    false, '' => $off,
                    default => $value[$handle],
                };
            }
        }

        return $this->normalizeValue($value, $element);
    }

    #[Override]
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return $value instanceof DesignValue ? $value->toArray() : $value;
    }

    /**
     * The value an agent may write: one string per option, limited to that option's keys.
     *
     * Core asks without the element. A field with a profile setting describes that
     * profile; otherwise the entry type whose layout the field sits in decides. A type
     * that also has profiles per owner field (`{ownerField}:{type}`) gets every option
     * and key any of them allows: an option only another owner's profile has is then
     * stored, but the block does not use it.
     *
     * @return array<string,mixed>
     */
    #[Override]
    public function getInputSchema(): array
    {
        $properties = [];

        foreach ($this->optionSets() as $groups) {
            foreach ($groups as $handle => $group) {
                $keys = array_map(strval(...), array_keys($group->options));

                if (!isset($properties[$handle])) {
                    $properties[$handle] = array_filter([
                        'type' => 'string',
                        'title' => $group->label,
                        'description' => self::optionNote($group),
                        'enum' => $keys,
                        'default' => $group->default,
                    ], static fn(mixed $part) => $part !== '');

                    continue;
                }

                $properties[$handle]['enum'] = array_values(array_unique([...$properties[$handle]['enum'], ...$keys]));

                // Blocks disagree on the default: leave it out instead of naming one of them.
                if (($properties[$handle]['default'] ?? $group->default) !== $group->default) {
                    unset($properties[$handle]['default']);
                }
            }
        }

        // Without a configuration there is no option to describe, and nothing sent could
        // be checked: an empty map or null would pass and reset the block. So nothing
        // matches (`not: {}`), and reads return the stored map meanwhile.
        // The reason stays out of the schema: whoever may read a schema is not always an
        // admin, and the reason can name a path on the server. It is logged and in the alert.
        if (app(Groups::class)->failure() !== null) {
            return [
                'type' => 'object',
                'description' => 'Design options cannot be written now: the Design Field configuration does not build. Leave this field out of the write, and tell an administrator of the site.',
                'not' => new stdClass(),
            ];
        }

        return array_filter([
            'type' => 'object',
            'description' => 'Design options as option handle => choice key. Send the whole map: an option left out goes back to its default.',
            'properties' => $properties,
            'additionalProperties' => false,
        ], static fn(mixed $part) => $part !== []);
    }

    /**
     * The GraphQL API reads the value as it takes it: a JSON string of option handle =>
     * choice key, for the options the element has. Without this, core's string type
     * prints the value, which is the class list a template gets from `{{ block.design }}`.
     * As with MCP, a stored value that is no option map is returned as it is: read as
     * a map of defaults, writing it back would replace it without a word.
     *
     * @return array{name:string,type:Type,description:string,resolve:Closure(mixed):?string}
     */
    #[Override]
    public function getContentGqlType(): array
    {
        return [
            'name' => $this->handle,
            'type' => Type::string(),
            'description' => 'The chosen key of every design option the element has, as a JSON object of option handle => choice key.',
            'resolve' => function(mixed $source): ?string {
                $value = match (true) {
                    is_object($source) && method_exists($source, 'getFieldValue') => $source->getFieldValue($this->handle),
                    is_array($source) => $source[$this->handle] ?? null,
                    default => null,
                };

                if (!$value instanceof DesignValue) {
                    return null;
                }

                // An object also when there is no option: `{}`, never the list `[]`.
                return $value->rejected() ?? Json::encode((object)$value->keys());
            },
        ];
    }

    /**
     * The GraphQL API keeps taking the value as a JSON string. Inside another field's
     * input (a Matrix block), MCP describes it by `inputSchema` and hands the map to
     * {@see normalizeValue()} as given.
     *
     * @return array<string,mixed>
     */
    #[Override]
    public function getContentGqlMutationArgumentType(): array
    {
        return [
            'name' => $this->handle,
            'type' => Type::string(),
            'description' => $this->instructions,
            'inputSchema' => $this->getInputSchema(),
        ];
    }

    /**
     * The value as MCP tools return it: the choice of every option the element has,
     * which is exactly what {@see getInputSchema()} takes back. Keys stored for options
     * the element no longer has are left out; a stored value that is no option map is
     * returned as it is. While the configuration does not build, it is the stored map.
     *
     * @return array<string,string>|string
     */
    #[Override]
    public function getMcpValue(mixed $value, ElementInterface $element, McpOutputContext $context): mixed
    {
        $value = $this->normalizeValue($value, $element);

        return $value->rejected() ?? $value->keys();
    }

    #[Override]
    public function uiControl(FieldContext $context): Control
    {
        $value = $context->value instanceof DesignValue
            ? $context->value
            : $this->normalizeValue($context->value, $context->element);

        // Nothing to pick from, or a stored value that is no option map: show it as it is.
        if ($value->groups() === [] || $value->rejected() !== null) {
            $raw = $value->rejected() ?? $value->toArray();

            return Textarea::make($context->path)
                ->rows(6)
                ->monospace()
                ->value(is_array($raw) ? ($raw ? Json::encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null) : $raw);
        }

        // A profile can show its panel in another mode, and a new element starts with a
        // closed panel open: that is when the editor picks how it looks.
        $registry = app(Groups::class)->safeRegistry();
        $profiles = $this->profilesFor($context->element);
        $mode = $registry->viewFor($profiles) ?? $this->viewMode;
        $element = $context->element;
        $isNew = $element === null || $element->id === null || $element->getIsUnpublishedDraft();
        $presets = OptionControls::presets($registry->presetsFor($profiles, $value->groups()), $value->groups());
        [$groups, $hidden] = $this->panelGroups($element, $value);

        // A preset sets several options at once; the panel then refreshes once, so options
        // that wait for another one (showIf) follow, as after a pick of a single option.
        return PresetBlock::make($context->path)
            ->viewMode('grouped')
            ->clearable(false)
            ->presets($presets)
            ->reactive($presets !== [])
            ->ui(OptionControls::panel($value, $mode, $isNew, $this->optionLayout, $groups, $hidden, $this->usageFor($groups, $element)))
            ->value([]);
    }

    /**
     * The panel's options after DefinePanelGroups: listeners may replace a group (to hide or
     * relabel choices for this element) or name options to leave out. Handles are fixed:
     * what a listener adds or removes is ignored.
     *
     * @param ?ElementInterface $element
     * @param DesignValue $value
     * @return array{0:array<string,Group>,1:list<string>} The options, and the handles left out of the panel
     *
     * @author WMD
     * @since 2.0.0
     */
    public function panelGroups(?ElementInterface $element, DesignValue $value): array
    {
        $groups = $value->groups();

        if (!Event::hasListeners(DefinePanelGroups::class)) {
            return [$groups, []];
        }

        $event = new DefinePanelGroups($element, $groups, $value->keys());
        Event::dispatch($event);

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
     * @return array<string,array<string,int>> Option handle => key => count
     *
     * @author WMD
     * @since 2.0.0
     */
    public function usageFor(array $groups, ?ElementInterface $element): array
    {
        foreach ($groups as $group) {
            if (count($group->options) >= OptionOrder::LONG && OptionControls::presentation($group) === ChoicePresentation::Select) {
                $type = $element !== null && method_exists($element, 'getType') ? rescue(static fn() => $element->getType()->handle, null, false) : null;

                return OptionOrder::countsFor(app(Usage::class)->counts(), $type);
            }
        }

        return [];
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $profiles = [['label' => "The element's own (its type)", 'value' => '']];
        $failure = app(Groups::class)->failure();

        foreach (app(Groups::class)->safeRegistry()->profileNames() as $name) {
            $profiles[] = ['label' => $name, 'value' => $name];
        }

        // A profile that is no longer in the config stays selectable, so saving does not drop it.
        if ($this->profile !== '' && !in_array($this->profile, array_column($profiles, 'value'), true)) {
            $profiles[] = ['label' => "$this->profile (not in the config)", 'value' => $this->profile];
        }

        return Ui::make([
            ...($failure === null ? [] : [Callout::make('design-field-config-error', 'The Design Field configuration does not build, so no block is listed below and no element shows its options: ' . $failure)->variant('danger')]),
            UiField::make('Show the options')
                ->instructions('A block can set its own in the config file (`_view`); a new element always starts open.')
                ->control(Choice::make('viewMode')->withoutPlaceholder()->options([
                    ['label' => 'As a panel (every option open)', 'value' => self::VIEW_PANEL],
                    ['label' => 'As a summary (what is set, with Edit)', 'value' => self::VIEW_SUMMARY],
                    ['label' => 'Collapsed (opens on click)', 'value' => self::VIEW_COLLAPSED],
                ])->value($this->viewMode)),
            UiField::make('Lay the options out')
                ->control(Choice::make('optionLayout')->withoutPlaceholder()->options([
                    ['label' => 'In sections (each on its own row, with a heading)', 'value' => self::LAYOUT_SECTIONS],
                    ['label' => 'Sections side by side', 'value' => self::LAYOUT_SIDE],
                    ['label' => 'Inline (one after another, no headings)', 'value' => self::LAYOUT_INLINE],
                ])->value($this->optionLayout)),
            UiField::make('Options of')
                ->instructions('Which block\'s options this field shows. Leave it on the element\'s own unless one field serves a fixed set.')
                ->control(Choice::make('profile')->withoutPlaceholder()->options($profiles)->value($this->profile)),
            MarkdownContent::make('design-field-settings', sprintf('The configuration check and the usage of every Design field: [Design Field settings](<%s>)', SettingsScreen::url())),
        ]);
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'viewMode' => [Rule::in([self::VIEW_PANEL, self::VIEW_SUMMARY, self::VIEW_COLLAPSED])],
            'optionLayout' => [Rule::in([self::LAYOUT_SECTIONS, self::LAYOUT_SIDE, self::LAYOUT_INLINE])],
        ]);
    }

    #[Override]
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $value = $value instanceof DesignValue ? $value->toArray() : $value;

        return Html::textarea($this->handle, $value ? Json::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null, [
            'id' => $this->getInputId(),
            'class' => 'text fullwidth code',
            'rows' => 6,
        ]);
    }

    #[Override]
    public function getElementRules(ElementInterface $element): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail) {
                if (!$value instanceof DesignValue) {
                    return;
                }

                if ($value->rejected() !== null) {
                    $fail(app(Groups::class)->failure() === null
                        ? sprintf('“%s” is not a design value. Send a JSON object of option handle => choice key, e.g. {"tone": "dark"}.', mb_strimwidth($value->rejected(), 0, 60, '…'))
                        : 'The design options cannot be saved from this form while the Design Field configuration does not build. What is stored is kept: reload the page.');

                    return;
                }

                foreach ($value->invalid() as $group => $key) {
                    $fail(sprintf('“%s” is not an option of %s.', $key, $value->groups()[$group]->label));
                }
            },
        ];
    }

    /**
     * Lets field layout conditions ("Show when") test the chosen variant.
     */
    #[Override]
    public function getElementConditionRuleType(): string
    {
        return DesignVariantConditionRule::class;
    }

    /**
     * Profile candidates, most specific first: the field setting, else `{ownerField}:{type}` then `{type}`.
     *
     * @return list<string>
     */
    public function profilesFor(?ElementInterface $element): array
    {
        if ($this->profile !== '') {
            return [$this->profile];
        }

        if ($element === null || !method_exists($element, 'getType')) {
            return [];
        }

        try {
            $type = $element->getType()->handle;
        } catch (InvalidArgumentException) {
            return [];
        }

        $candidates = [];

        if (in_array(NestedElement::class, class_uses_recursive($element), true) && ($owner = $element->getField()) !== null) {
            $candidates[] = "$owner->handle:$type";
        }

        $candidates[] = $type;

        return $candidates;
    }

    /**
     * The options the field has where no element is at hand (the input schema, a
     * condition builder): one set per profile it can resolve to. That is its profile
     * setting, else the entry type whose layout it sits in, with that type's profiles
     * per owner field (`{ownerField}:{type}`) first.
     *
     * @return list<array<string,Group>>
     *
     * @author WMD
     * @since 2.0.0
     */
    public function optionSets(): array
    {
        $registry = app(Groups::class)->safeRegistry();

        if ($this->profile !== '') {
            return [$registry->groupsFor($this->profile)];
        }

        $type = $this->layoutType();

        if ($type === null) {
            return [$registry->groupsFor(null)];
        }

        $sets = [];

        foreach ($registry->profileNames() as $name) {
            if (str_ends_with($name, ":$type")) {
                $sets[] = $registry->groupsFor($name);
            }
        }

        $sets[] = $registry->groupsFor($type);

        return $sets;
    }

    /**
     * Whether the field can tell which options it has without an element: it has a
     * profile setting, or sits in the layout of a saved entry type.
     *
     * @return bool
     *
     * @author WMD
     * @since 2.0.0
     */
    public function knowsItsProfile(): bool
    {
        return $this->profile !== '' || $this->layoutType() !== null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Handle of the entry type whose layout the field sits in, null when it is in none
     * or the type is not saved yet.
     *
     * A condition builder works on a copy of the layout that names no entry type and
     * has another UID, so the type is then found by the UID of the field's own layout
     * element, which the copy keeps.
     */
    private function layoutType(): ?string
    {
        $provider = $this->layoutElement?->getLayout()?->provider;

        if ($provider instanceof EntryType) {
            return $provider->handle;
        }

        $uid = $this->layoutElement?->uid;

        if ($uid === null) {
            return null;
        }

        return EntryTypes::getAllEntryTypes()
            ->first(static fn(EntryType $type) => $type->getFieldLayout()->getElementByUid($uid) !== null)
            ?->handle;
    }

    /**
     * What a form posts while the configuration does not build. The box that shows the
     * stored map posts a map back, which is stored as it is. Two things are refused:
     * what is no map, and the post of a panel that was open when the configuration
     * stopped building (a deploy, then an autosave): its switches come as true/false
     * and its sliders as places on their scale, neither can be turned into a key now,
     * and stored as they are they would replace the block's choices.
     *
     * The refused value holds what the element has. A draft is autosaved without
     * validation, so nothing else would stop an empty map from being written over it.
     */
    private function requestValueWithoutOptions(mixed $value, ?ElementInterface $element): DesignValue
    {
        $posted = is_array($value) && self::isPanelPost($value) ? null : $this->normalizeValue($value, $element);

        if ($posted !== null && $posted->rejected() === null) {
            return $posted;
        }

        // Still the element's value from before this request: it is set after this returns.
        $current = $element?->getFieldValue($this->handle);

        return new DesignValue(
            $current instanceof DesignValue ? $current->toArray() : [],
            [],
            [],
            rejected: $posted?->rejected() ?? Json::encode($value),
            unresolved: true,
        );
    }

    /**
     * Whether a posted array comes from the panel's controls and not from the box that
     * shows the stored map: only the panel posts a switch as a boolean or a slider's place.
     *
     * @param array<array-key,mixed> $posted
     */
    private static function isPanelPost(array $posted): bool
    {
        return array_key_exists(Registry::SLIDER_KEY, $posted) || array_any($posted, static fn(mixed $choice) => is_bool($choice));
    }

    /**
     * What an agent cannot read off the keys: the option's instructions, which key of
     * a switch is on, and the choices of other options it depends on.
     */
    private static function optionNote(Group $group): string
    {
        $notes = [$group->instructions];

        if ($group->input === Group::INPUT_TOGGLE && count($group->options) === 2) {
            [$off, $on] = array_map(strval(...), array_keys($group->options));
            $notes[] = "A switch: `$off` is off, `$on` is on.";
        }

        foreach ($group->showIf as $other => $allowed) {
            $notes[] = sprintf('Only applies when `%s` is %s.', $other, implode(' or ', array_map(static fn(mixed $key) => "`$key`", $allowed)));
        }

        return implode(' ', array_filter($notes));
    }
}
