<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Entry\Conditions\EntryCondition;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Models\User;
use Symfony\Component\Yaml\Yaml;
use wmd\designfield\fields\conditions\DesignVariantConditionRule;
use wmd\designfield\fields\Design;
use wmd\designfield\services\Groups;

/**
 * "Show this field when the block's Design field has variant X", on the field
 * layout the Craft 6 master stores for its Search header element: Suggested
 * searches shows for three of the four layouts, Search placeholder for one.
 */
final class VariantConditionTest extends PluginTestCase
{
    private const string ENTRY_TYPE_UID = '6983d1f3-fcfb-413b-9126-9d0ec928c02e';

    private const string DESIGN_UID = 'a40b6fea-c164-4493-8304-afd298e452e5';

    private const string DESIGN_ELEMENT_UID = 'bb23d7f2-442c-46bb-ae6c-fd5139234c25';

    private const string SUGGESTIONS_ELEMENT_UID = '99e91c5c-3206-4f61-9e51-b491731bfd6f';

    private const string PLACEHOLDER_ELEMENT_UID = '6a2e8d41-0c7f-4b39-a5d2-91e4f7c3b8a6';

    /** @var array<string,mixed> The layout as the project config YAML has it */
    private array $stored;

    private string $layoutUid;

    private Design $design;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return [
                    'groups' => [
                        'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
                    ],
                    'profiles' => [
                        'headerSearch' => ['variant' => ['label' => 'Layout', 'options' => [
                            '_default' => ['label' => 'Search popup'],
                            'panel' => ['label' => 'Side panel'],
                            'instant' => ['label' => 'Search field'],
                            'bar' => ['label' => 'Search bar'],
                        ]]],
                        'blockPricing' => ['tone', 'variant' => ['label' => 'Layout', 'options' => [
                            '_default' => ['label' => 'Tiers'],
                            'plan-cards' => ['label' => 'Plan cards'],
                            'domain-search' => ['label' => 'Domain search'],
                            'domain-prices' => ['label' => 'Domain prices'],
                        ]]],
                        'blockPlain' => ['tone'],
                    ],
                ] + parent::config();
            }
        });

        // Requests go through the session and cookie middleware.
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $this->actingAs(User::query()->where('admin', true)->firstOrFail(), Cms::config()->authGuard);

        $fieldLayouts = Yaml::parseFile(dirname(__DIR__) . '/fixtures/header-search-layout.yaml')['fieldLayouts'];
        $this->layoutUid = (string)array_key_first($fieldLayouts);
        $this->stored = $fieldLayouts[$this->layoutUid];

        $this->createSchema();
    }

    public function testTheStoredConditionsLoadAsTheVariantRule(): void
    {
        $rules = $this->element(self::SUGGESTIONS_ELEMENT_UID)->getElementCondition()?->getConditionRules()->getRules();

        self::assertNotNull($rules, 'The condition was dropped: its rule class is unknown.');
        self::assertCount(1, $rules);
        self::assertInstanceOf(DesignVariantConditionRule::class, $rules[0]);
        self::assertSame(['panel', 'instant', 'bar'], $rules[0]->getValues());
        self::assertSame('Design → Variant', $rules[0]->getLabel());
    }

    public function testAFieldShowsOnlyForTheVariantsItsConditionNames(): void
    {
        self::assertSame(['design'], $this->shownFor(['variant' => '_default']));
        self::assertSame(['design', 'searchSuggestions'], $this->shownFor(['variant' => 'panel']));
        self::assertSame(['design', 'searchSuggestions'], $this->shownFor(['variant' => 'instant']));
        self::assertSame(['design', 'searchSuggestions', 'searchPlaceholder'], $this->shownFor(['variant' => 'bar']));

        // An element that never picked a layout is on the default one.
        self::assertSame(['design'], $this->shownFor([]));
    }

    public function testTheChoiceInTheOpenEditorDecidesNotTheSavedOne(): void
    {
        $entry = $this->entry(['variant' => 'bar']);
        self::assertTrue(Elements::saveElement($entry), implode(' ', $entry->errors()->all()));

        $entry = Entry::find()->id($entry->id)->status(null)->one();
        self::assertSame(['design', 'searchSuggestions', 'searchPlaceholder'], $this->shown($entry));

        $entry->setFieldValue('design', ['variant' => 'panel']);
        self::assertSame(['design', 'searchSuggestions'], $this->shown($entry));

        $entry->setFieldValue('design', ['variant' => '_default']);
        self::assertSame(['design'], $this->shown($entry));
    }

    public function testTheFormRefreshFollowsTheVariantJustPicked(): void
    {
        $entry = $this->entry(['variant' => 'bar']);
        self::assertTrue(Elements::saveElement($entry), implode(' ', $entry->errors()->all()));

        // What the editor posts after a change in the Design panel, before any save.
        $refresh = fn(string $variant): string => (string)$this->postJson($this->cpUri(Cms::config()->actionTrigger . '/elements/update-field-layout'), [
            'elementType' => Entry::class,
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'fields' => ['design' => ['variant' => $variant]],
        ])->assertOk()->getContent();

        $bar = $refresh('bar');
        self::assertStringContainsString('searchSuggestions', $bar);
        self::assertStringContainsString('searchPlaceholder', $bar);

        $panel = $refresh('panel');
        self::assertStringContainsString('searchSuggestions', $panel);
        self::assertStringNotContainsString('searchPlaceholder', $panel);

        $default = $refresh('_default');
        self::assertStringContainsString('fields.design', $default);
        self::assertStringNotContainsString('searchSuggestions', $default);
        self::assertStringNotContainsString('searchPlaceholder', $default);

        // Nothing was saved on the way.
        self::assertSame('bar', Entry::find()->id($entry->id)->status(null)->one()->getFieldValue('design')->toArray()['variant']);
    }

    public function testNotOneOfHidesTheFieldForTheNamedVariants(): void
    {
        // The rule the master stores on the pricing block's tiers: not for the domain layouts.
        $type = $this->conditionedType('blockPricing', [
            'class' => DesignVariantConditionRule::class,
            'fieldUid' => self::DESIGN_UID,
            'operator' => 'ni',
            'uid' => '6a597141-6299-4189-bf4f-3b0fc596e181',
            'values' => ['domain-search', 'domain-prices', 'domain-split', 'domain-all', 'domain-years', 'domain-hr-name'],
        ]);

        self::assertSame(['design', 'note'], $this->shownFor(['variant' => '_default'], $type));
        self::assertSame(['design', 'note'], $this->shownFor(['variant' => 'plan-cards'], $type));
        self::assertSame(['design'], $this->shownFor(['variant' => 'domain-search'], $type));
        self::assertSame(['design'], $this->shownFor(['variant' => 'domain-prices'], $type));
    }

    public function testIsEmptyMatchesABlockWithoutVariants(): void
    {
        $rule = fn(string $operator): array => [
            'class' => DesignVariantConditionRule::class,
            'fieldUid' => self::DESIGN_UID,
            'operator' => $operator,
        ];

        // blockPlain has a tone and no layouts; blockOther has no profile, so the shared options only.
        self::assertSame(['design', 'note'], $this->shownFor(['tone' => 'dark'], $this->conditionedType('blockPlain', $rule('empty'))));
        self::assertSame(['design'], $this->shownFor(['tone' => 'dark'], $this->conditionedType('blockOther', $rule('notempty'))));

        // A block with layouts always has one, the default included.
        self::assertSame(['design'], $this->shownFor([], $this->conditionedType('blockPricing', $rule('empty'))));
    }

    public function testTheRuleOffersTheVariantsOfTheEntryTypesProfile(): void
    {
        $condition = $this->element(self::SUGGESTIONS_ELEMENT_UID)->getElementCondition();
        self::assertNotNull($condition);

        $offered = array_values(array_filter(
            $condition->getSelectableConditionRules(),
            static fn(object $rule) => $rule instanceof DesignVariantConditionRule,
        ));
        self::assertCount(1, $offered, 'A layout with a Design field offers the Variant rule once.');
        self::assertSame('Design → Variant', $offered[0]->getLabel());

        self::assertSame([
            ['value' => '_default', 'label' => 'Search popup'],
            ['value' => 'panel', 'label' => 'Side panel'],
            ['value' => 'instant', 'label' => 'Search field'],
            ['value' => 'bar', 'label' => 'Search bar'],
        ], $this->offeredOptions($condition->getConditionRules()->getRules()[0]));

        // Another entry type's layout offers that type's variants.
        $pricing = $this->conditionedType('blockPricing', [
            'class' => DesignVariantConditionRule::class,
            'fieldUid' => self::DESIGN_UID,
            'operator' => 'in',
            'values' => ['plan-cards'],
        ]);
        $rule = $pricing->getFieldLayout()->getTabs()[0]->getElements()[1]->getElementCondition()->getConditionRules()->getRules()[0];

        self::assertSame(['_default', 'plan-cards', 'domain-search', 'domain-prices'], array_column($this->offeredOptions($rule), 'value'));
    }

    public function testTheLayoutDesignerOffersTheVariantsOfTheEntryTypeItEdits(): void
    {
        $layout = EntryTypes::getEntryTypeByHandle('headerSearch')->getFieldLayout();

        // The designer works on a copy of the layout built from the form, and the
        // condition builder on a copy of that: neither names the entry type.
        $settings = (string)$this->postJson($this->cpUri(Cms::config()->actionTrigger . '/fields/render-layout-component-settings'), [
            'uid' => self::SUGGESTIONS_ELEMENT_UID,
            'elementType' => Entry::class,
            'layoutConfig' => ['id' => $layout->id, 'uid' => $layout->uid, ...$layout->getConfig()],
        ])->assertOk()->getContent();

        foreach (['Search popup', 'Side panel', 'Search field', 'Search bar'] as $label) {
            self::assertStringContainsString($label, $settings);
        }

        self::assertStringNotContainsString('Plan cards', $settings, 'Another block\'s variants are offered.');
    }

    public function testAnEntryTypeThatIsNotSavedYetIsOfferedEveryVariant(): void
    {
        $this->conditionedType('blockPricing', [
            'class' => DesignVariantConditionRule::class,
            'fieldUid' => self::DESIGN_UID,
            'operator' => 'in',
            'values' => ['plan-cards'],
        ]);

        // A new entry type: no saved layout has the Design element the form's layout
        // has, so its profile cannot be told yet.
        $config = json_decode(str_replace(
            self::DESIGN_ELEMENT_UID,
            'f0e1d2c3-b4a5-4697-8899-aabbccddeeff',
            json_encode(EntryTypes::getEntryTypeByHandle('headerSearch')->getFieldLayout()->getConfig(), JSON_THROW_ON_ERROR),
        ), true, flags: JSON_THROW_ON_ERROR);

        $settings = (string)$this->postJson($this->cpUri(Cms::config()->actionTrigger . '/fields/render-layout-component-settings'), [
            'uid' => self::SUGGESTIONS_ELEMENT_UID,
            'elementType' => Entry::class,
            'layoutConfig' => $config,
        ])->assertOk()->getContent();

        foreach (['Side panel', 'Search bar', 'Plan cards', 'Domain search'] as $label) {
            self::assertStringContainsString($label, $settings);
        }
    }

    public function testTheStoredConfigComesBackAsItWentIn(): void
    {
        $layout = EntryTypes::getEntryTypeByHandle('headerSearch')->getFieldLayout();

        self::assertEquals($this->storedConditions(), $this->conditionsOf($layout->getConfig()));

        // And as the project config holds it after the save.
        $saved = $this->app->make(ProjectConfig::class)->get('entryTypes.' . self::ENTRY_TYPE_UID);
        self::assertEquals($this->storedConditions(), $this->conditionsOf($saved['fieldLayouts'][$this->layoutUid]));
    }

    public function testSavingTheEntryTypeInTheControlPanelKeepsTheConditions(): void
    {
        $type = EntryTypes::getEntryTypeByHandle('headerSearch');
        $layout = $type->getFieldLayout();

        // The form posts the layout as the layout designer was given it.
        $this->postJson($this->cpUri('settings/entry-types'), [
            'entryTypeId' => $type->id,
            'name' => 'Search box',
            'handle' => 'headerSearch',
            'fieldLayout' => ['id' => $layout->id, 'uid' => $layout->uid, ...$layout->getConfig()],
        ])->assertSuccessful();

        $saved = $this->app->make(ProjectConfig::class)->get('entryTypes.' . self::ENTRY_TYPE_UID);

        self::assertSame('Search box', $saved['name']);
        self::assertEquals($this->storedConditions(), $this->conditionsOf($saved['fieldLayouts'][$this->layoutUid]));
        self::assertSame(['design', 'searchSuggestions'], $this->shownFor(['variant' => 'panel']));
    }

    /**
     * The element conditions of a layout config, by layout element UID.
     *
     * @param array<string,mixed> $layout
     * @return array<string,array<string,mixed>|null>
     */
    private function conditionsOf(array $layout): array
    {
        return array_column($layout['tabs'][0]['elements'], 'elementCondition', 'uid');
    }

    /**
     * @return array<string,array<string,mixed>|null>
     */
    private function storedConditions(): array
    {
        $conditions = $this->conditionsOf($this->stored);

        self::assertSame(DesignVariantConditionRule::class, $conditions[self::PLACEHOLDER_ELEMENT_UID]['conditionRules']['rules'][0]['class']);
        self::assertSame(['bar'], $conditions[self::PLACEHOLDER_ELEMENT_UID]['conditionRules']['rules'][0]['values']);

        return $conditions;
    }

    /**
     * The options the condition builder lists for a rule.
     *
     * @return list<array{value:string,label:string}>
     */
    private function offeredOptions(DesignVariantConditionRule $rule): array
    {
        foreach ($rule->getUi()->nodes() as $node) {
            $control = method_exists($node, 'getControl') ? $node->getControl() : null;

            if ($control?->path() === 'values') {
                return array_map(
                    static fn(array $option) => ['value' => $option['value'], 'label' => $option['label']],
                    $control->props()['options'],
                );
            }
        }

        self::fail('The rule has no values input.');
    }

    /**
     * Handles of the custom fields the edit form shows for an unsaved element with this Design value.
     *
     * @param array<string,string> $design
     * @return list<string>
     */
    private function shownFor(array $design, ?EntryType $type = null): array
    {
        return $this->shown($this->entry($design, $type));
    }

    /**
     * @return list<string>
     */
    private function shown(Entry $entry): array
    {
        $handles = [];

        foreach ($entry->getFieldLayout()->getTabs() as $tab) {
            foreach ($tab->getElements() as $layoutElement) {
                if ($layoutElement instanceof CustomField && $layoutElement->showInForm($entry)) {
                    $handles[] = $layoutElement->getField()->handle;
                }
            }
        }

        return $handles;
    }

    /**
     * @param array<string,string> $design
     */
    private function entry(array $design, ?EntryType $type = null): Entry
    {
        $type ??= EntryTypes::getEntryTypeByHandle('headerSearch');

        $entry = new Entry();
        $entry->sectionId = $this->section->id;
        $entry->siteId = Sites::getPrimarySite()->id;
        $entry->typeId = $type->id;
        $entry->title = 'Search';
        $entry->setFieldValue('design', $design);

        return $entry;
    }

    private function element(string $uid): CustomField
    {
        foreach (EntryTypes::getEntryTypeByHandle('headerSearch')->getFieldLayout()->getTabs()[0]->getElements() as $layoutElement) {
            if ($layoutElement->uid === $uid) {
                self::assertInstanceOf(CustomField::class, $layoutElement);

                return $layoutElement;
            }
        }

        self::fail("No layout element $uid.");
    }

    private function cpUri(string $path): string
    {
        return '/' . Cms::config()->cpTrigger . '/' . $path;
    }

    /**
     * An entry type with the Design field and a Note field that shows per the given rule.
     *
     * @param array<string,mixed> $rule
     */
    private function conditionedType(string $handle, array $rule): EntryType
    {
        $design = $this->design;

        $note = Fields::getFieldByHandle('note') ?? new PlainText(['name' => 'Note', 'handle' => 'note']);
        self::assertTrue($note->id !== null || Fields::saveField($note));

        $type = new EntryType(['name' => $handle, 'handle' => $handle]);
        $type->setFieldLayout(Fields::createLayout([
            'type' => Entry::class,
            'tabs' => [[
                'name' => 'Content',
                'elements' => [
                    ['type' => CustomField::class, 'fieldUid' => $design->uid],
                    ['type' => CustomField::class, 'fieldUid' => $note->uid, 'elementCondition' => [
                        'class' => EntryCondition::class,
                        'elementType' => Entry::class,
                        'fieldContext' => 'global',
                        'forQuery' => false,
                        'conditionRules' => ['operator' => 'and', 'rules' => [$rule]],
                    ]],
                ],
            ]],
        ]));
        self::assertTrue(EntryTypes::saveEntryType($type), implode(' ', $type->errors()->all()));

        return EntryTypes::getEntryTypeByHandle($handle);
    }

    /**
     * The three fields with the UIDs the stored layout names, the entry type with
     * that layout, and a section to hold its entries.
     */
    private function createSchema(): void
    {
        $this->design = new Design(['name' => 'Design', 'handle' => 'design', 'uid' => self::DESIGN_UID]);
        self::assertTrue(Fields::saveField($this->design));
        self::assertSame(self::DESIGN_UID, $this->design->uid);

        // Stand-ins for the master's Table and Plain Text fields: only their place in the layout matters.
        foreach ([
            'searchSuggestions' => '8e0423f5-5b3e-4690-bd51-24b1e681233d',
            'searchPlaceholder' => '5ab49a54-ad9d-4fb4-8053-6e61352af329',
        ] as $handle => $uid) {
            self::assertTrue(Fields::saveField(new PlainText(['name' => $handle, 'handle' => $handle, 'uid' => $uid])));
        }

        $type = new EntryType(['name' => 'Search', 'handle' => 'headerSearch', 'uid' => self::ENTRY_TYPE_UID]);
        $type->setFieldLayout(Fields::createLayout(['uid' => $this->layoutUid, 'type' => Entry::class] + $this->stored));
        self::assertTrue(EntryTypes::saveEntryType($type), implode(' ', $type->errors()->all()));

        $this->section = new Section([
            'name' => 'Header elements',
            'handle' => 'headerElements',
            'type' => SectionType::Channel,
            'siteSettings' => [new SectionSiteSettings(['siteId' => Sites::getPrimarySite()->id])],
            'entryTypes' => [$type],
        ]);
        self::assertTrue(Sections::saveSection($this->section));
    }
}
