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
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\ContentBlock;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field as UiField;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Closure;
use GraphQL\Type\Definition\Type;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Override;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;
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

        return app(Groups::class)->getRegistry()->valueFromInput($value, $this->profilesFor($element));
    }

    #[Override]
    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): DesignValue
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value) ?? $value;
        }

        // The editor posts a switch as true/false (a plain form: its on key, or '' when off).
        if (is_array($value)) {
            foreach (app(Groups::class)->getRegistry()->groupsFor($this->profilesFor($element)) as $handle => $group) {
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

        foreach ($this->schemaGroups() as $groups) {
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

        return array_filter([
            'type' => 'object',
            'description' => 'Design options as option handle => choice key. Send the whole map: an option left out goes back to its default.',
            'properties' => $properties,
            'additionalProperties' => false,
        ], static fn(mixed $part) => $part !== []);
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
     * returned as it is.
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
        $mode = app(Groups::class)->getRegistry()->viewFor($this->profilesFor($context->element)) ?? $this->viewMode;
        $element = $context->element;
        $isNew = $element === null || $element->id === null || $element->getIsUnpublishedDraft();

        return ContentBlock::make($context->path)
            ->viewMode('grouped')
            ->clearable(false)
            ->ui(OptionControls::panel($value, $mode, $isNew, $this->optionLayout))
            ->value([]);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $profiles = [['label' => "The element's own (its type)", 'value' => '']];

        foreach (app(Groups::class)->getRegistry()->profileNames() as $name) {
            $profiles[] = ['label' => $name, 'value' => $name];
        }

        // A profile that is no longer in the config stays selectable, so saving does not drop it.
        if ($this->profile !== '' && !in_array($this->profile, array_column($profiles, 'value'), true)) {
            $profiles[] = ['label' => "$this->profile (not in the config)", 'value' => $this->profile];
        }

        return Ui::make([
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
                    $fail(sprintf('“%s” is not a design value. Send a JSON object of option handle => choice key, e.g. {"tone": "dark"}.', mb_strimwidth($value->rejected(), 0, 60, '…')));

                    return;
                }

                foreach ($value->invalid() as $group => $key) {
                    $fail(sprintf('“%s” is not an option of %s.', $key, $value->groups()[$group]->label));
                }
            },
        ];
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

    // Private Methods
    // =========================================================================

    /**
     * The groups the input schema is built from: one set per profile the field can
     * resolve to without an element at hand.
     *
     * @return list<array<string,Group>>
     */
    private function schemaGroups(): array
    {
        $registry = app(Groups::class)->getRegistry();

        if ($this->profile !== '') {
            return [$registry->groupsFor($this->profile)];
        }

        $provider = $this->layoutElement?->getLayout()?->provider;

        if (!$provider instanceof EntryType || $provider->handle === null) {
            return [$registry->groupsFor(null)];
        }

        $sets = [];

        foreach ($registry->profileNames() as $name) {
            if (str_ends_with($name, ":$provider->handle")) {
                $sets[] = $registry->groupsFor($name);
            }
        }

        $sets[] = $registry->groupsFor($provider->handle);

        return $sets;
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
