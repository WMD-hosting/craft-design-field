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
use craft\elements\Entry;
use craft\models\EntryType;
use craft\base\FieldInterface;
use craft\base\FieldLayoutProviderInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\helpers\Html;
use wmd\designfield\web\EntryFieldsInput;
use craft\helpers\Json;
use wmd\designfield\entryfields\EntryFieldsValue;
use wmd\designfield\entryfields\FormatPicker;
use wmd\designfield\entryfields\Row;
use Throwable;
use craft\models\FieldLayout;
use wmd\designfield\variables\DesignFieldVariable;
use wmd\designfield\entryfields\LayoutRows;
use wmd\designfield\entryfields\SelectOptions;
use wmd\designfield\entryfields\SlotScanner;
use wmd\designfield\Plugin;
use yii\db\Schema;

/**
 * Entry Fields: per block, which fields of an entry to show, where and how.
 *
 * @author WMD
 * @since 1.1.0
 */
class EntryFields extends Field
{
    // Public Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('design-field', 'Entry Fields');
    }

    public static function icon(): string
    {
        return 'list-check';
    }

    public static function phpType(): string
    {
        return EntryFieldsValue::class;
    }

    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    public static function isRequirable(): bool
    {
        return false;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        return EntryFieldsValue::fromMixed($value);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        return EntryFieldsValue::fromMixed($value)->toArray();
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_array($value) && is_array($value['rows'] ?? null)) {
            // Only the ordered table posts rows without a slot (the Slot select always has a value).
            // Fill it even when the variant was just switched away from ordered, or the rows are dropped.
            foreach ($value['rows'] as $key => $row) {
                if (is_array($row) && trim((string)($row['slot'] ?? '')) === '') {
                    $value['rows'][$key]['slot'] = LayoutRows::SLOT;
                }
            }
        }

        if (is_array($value) && $this->isOrdered($element)) {

            // "Copy layout order into rows" only fills an empty table; edited rows always win.
            if (!empty($value['copyLayout']) && EntryFieldsValue::fromMixed($value)->rows === []) {
                $renderer = Plugin::getInstance()->getEntryFieldsRenderer();
                $value['rows'] = array_map(
                    static fn(Row $row) => $row->toArray(),
                    $this->_defaultRows($element),
                );
            }
            unset($value['copyLayout']);
        }

        return $this->normalizeValue($value, $element);
    }

    /**
     * Whether the element is a block using the ordered variant (its Design `variant` is `ordered`).
     *
     * @param ElementInterface|null $element
     * @return bool
     *
     * @author WMD
     * @since 1.1.0
     */
    public function isOrdered(?ElementInterface $element): bool
    {
        if (!$element instanceof Entry || !$element->getFieldLayout()?->getFieldByHandle('design')) {
            return false;
        }

        try {
            $key = (new DesignFieldVariable())->of($element)->get('variant')->key;

            return $key === 'ordered' || ($key === 'product' && $this->_isProductBlock($element));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Slots offered by the templates of this element's block type.
     *
     * @param ElementInterface|null $element
     * @return list<string>
     *
     * @author WMD
     * @since 1.1.0
     */
    public function slotsFor(?ElementInterface $element): array
    {
        $handle = $element instanceof Entry ? $element->getType()->handle : null;
        $dir = Craft::$app->getPath()->getSiteTemplatesPath() . "/_blocks/$handle";

        if ($handle === null || !is_dir($dir)) {
            return [];
        }

        $slots = [];
        foreach (glob("$dir/*.twig") ?: [] as $file) {
            $slots = [...$slots, ...SlotScanner::scan((string)file_get_contents($file))];
        }

        return array_values(array_unique($slots));
    }

    public function getElementValidationRules(): array
    {
        return [
            [
                function(ElementInterface $element) {
                    $value = $element->getFieldValue($this->handle);
                    if (!$value instanceof EntryFieldsValue) {
                        return;
                    }

                    $known = array_merge(FormatPicker::NATIVE, array_map(static fn($f) => $f->handle, Craft::$app->getFields()->getAllFields()));
                    $slots = $this->slotsFor($element);

                    $elementSection = Plugin::getInstance()->getSettings()->entryFieldElementSection;
                    $ordered = $this->isOrdered($element);
                    foreach ($value->rows as $row) {
                        if ($row->format === 'element') {
                            if (!Entry::find()->id($row->element)->section($elementSection)->status(null)->exists()) {
                                $element->addError("field:$this->handle", Craft::t('design-field', 'Pick a global element for this row.'));
                            }
                        } elseif (!in_array($row->field, $known, true)) {
                            $element->addError("field:$this->handle", Craft::t('design-field', '“{field}” is not a field.', ['field' => $row->field]));
                        }
                        if (!$ordered && $slots !== [] && !in_array($row->slot, $slots, true)) {
                            $element->addError("field:$this->handle", Craft::t('design-field', 'This block has no “{slot}” slot.', ['slot' => $row->slot]));
                        }
                    }
                },
            ],
        ];
    }

    // Protected Methods
    // =========================================================================

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $value = EntryFieldsValue::fromMixed($value);
        $presets = Plugin::getInstance()->getSettings()->entryFieldPresets;
        $slots = $this->slotsFor($element);
        $html = '';

        if ($presets !== []) {
            $options = [['label' => Craft::t('design-field', '— none —'), 'value' => '']];
            foreach ($presets as $name => $preset) {
                $options[] = ['label' => $preset['label'] ?? $name, 'value' => $name];
            }
            $html .= Cp::selectFieldHtml([
                'label' => Craft::t('design-field', 'Preset'),
                'id' => "$this->handle-preset",
                'name' => "$this->handle[preset]",
                'value' => $value->preset ?? '',
                'options' => $options,
            ]);
        }

        // Stored values the dropdowns no longer offer stay visible and saved as they are.
        $stored = static fn(string $key) => array_map(static fn(Row $row) => (string)($row->toArray()[$key] ?? ''), $value->rows);
        $missing = Craft::t('design-field', 'Not available here');

        // The layout note shows only for the ordered variant; the browser toggles it live
        // when the block's Layout changes (EntryFieldsInput), so it is rendered either way.
        $ordered = $this->isOrdered($element);
        if ($value->rows === []) {
            $labels = array_map(
                fn(Row $row) => match ($row->field) {
                    'title' => Craft::t('design-field', 'Title'),
                    'postDate' => Craft::t('design-field', 'Date'),
                    'gallery', 'context', 'rating', 'variants', 'cart', 'actions', 'price' => ucfirst($row->field),
                    default => Craft::$app->getFields()->getFieldByHandle($row->field)->name ?? $row->field,
                },
                $this->_defaultRows($element),
            );
            $note = Html::tag('p', Craft::t('design-field', 'Following the layout: {parts}. Add rows to customise.', ['parts' => implode(' · ', $labels)]), ['class' => 'light'])
                . Cp::checkboxFieldHtml([
                    'name' => "$this->handle[copyLayout]",
                    'value' => '1',
                    'checkboxLabel' => Craft::t('design-field', 'Copy layout order into rows'),
                ]);
        } else {
            $note = Html::tag('p', Craft::t('design-field', 'Rows render in this order. Remove all rows to follow the layout again.'), ['class' => 'light']);
        }
        $html .= Html::tag('div', $note, ['class' => 'ef-ordered-note', 'hidden' => !$ordered]);

        $cols = [
            'field' => ['width' => '20%', 'heading' => Craft::t('design-field', 'Field'), 'type' => 'select', 'options' => [['label' => '—', 'value' => ''], ...SelectOptions::withMissing($this->_fieldOptions($element), $stored('field'), $missing)]],
            'slot' => ['width' => '11%', 'heading' => Craft::t('design-field', 'Slot'), 'type' => 'select', 'options' => SelectOptions::withMissing($this->_plainOptions($slots ?: ['below-title']), $stored('slot'), $missing)],
            'format' => ['width' => '11%', 'heading' => Craft::t('design-field', 'Format'), 'type' => 'select', 'options' => array_map(static fn(string $f) => ['label' => FormatPicker::label($f), 'value' => $f], FormatPicker::all())],
            'label' => ['width' => '16%', 'heading' => Craft::t('design-field', 'Label'), 'type' => 'singleline'],
            'textAfter' => ['width' => '10%', 'heading' => Craft::t('design-field', 'Text after'), 'type' => 'singleline'],
            'tag' => ['width' => '7%', 'heading' => Craft::t('design-field', 'Tag'), 'type' => 'select', 'options' => $this->_plainOptions(Row::TAGS)],
            'style' => ['width' => '10%', 'heading' => Craft::t('design-field', 'Style'), 'type' => 'select', 'options' => SelectOptions::withMissing($this->_styleOptions(), $stored('style'), $missing)],
        ];
        if ($this->_isProductBlock($element)) {
            $cols = ['field' => $cols['field'], 'column' => ['width' => '10%', 'heading' => Craft::t('design-field', 'Column'), 'type' => 'select', 'options' => [
                ['label' => Craft::t('design-field', 'Details'), 'value' => 'details'],
                ['label' => Craft::t('design-field', 'Gallery'), 'value' => 'gallery'],
                ['label' => Craft::t('design-field', 'Below'), 'value' => 'below'],
            ]]] + $cols;
        }
        $cols += [
            'element' => ['width' => '15%', 'heading' => Craft::t('design-field', 'Global element'), 'type' => 'select', 'options' => SelectOptions::withMissing($this->_elementOptions(), $stored('element'), $missing)],
        ];
        $html .= Cp::editableTableFieldHtml([
            'id' => "$this->handle-rows",
            'name' => "$this->handle[rows]",
            'allowAdd' => true,
            'allowReorder' => true,
            'allowDelete' => true,
            'addRowLabel' => Craft::t('design-field', 'Add a field'),
            'cols' => $cols,
            'rows' => array_map(static fn(Row $row) => $row->toArray(), $value->rows),
        ]);

        $id = Html::id("$this->handle-entry-fields");
        EntryFieldsInput::register(Craft::$app->getView(), $id);

        $config = [];
        if ($sections = $this->_sectionFilter($element, $cols['field']['options'])) {
            $config['sections'] = $sections;
        }

        return Html::tag('div', $html, [
            'id' => $id,
            'class' => 'entry-fields',
            'data-ef' => Json::encode($config + [
                'ordered' => $ordered,
                // Layout keys that mean "rows in order, no slots" for this block.
                'orderedKeys' => $this->_isProductBlock($element) ? ['ordered', 'product'] : ['ordered'],
                'cols' => array_keys($cols),
                'allFormats' => FormatPicker::all(),
                // Custom formats show their config label; built-ins show their name.
                'formatLabels' => array_combine(FormatPicker::all(), array_map(FormatPicker::label(...), FormatPicker::all())),
                'meta' => $this->_formatMeta($cols['field']['options'], $this->_isProductBlock($element)),
                'element' => $this->_elementConfig($element),
                'handle' => $this->handle,
                'labels' => ['edit' => Craft::t('app', 'Edit'), 'new' => Craft::t('app', 'New'), 'untitled' => Craft::t('design-field', 'New global element'), 'notInSections' => Craft::t('design-field', 'not in the ticked sections')],
            ]),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Fields the editor can pick: native attributes, then fields grouped by entry type.
     *
     * Blocks named in `entryFieldOwnerBlocks` (the Content block) show their owner's entry:
     * only the owner's fields are offered, or, when the owner is a layout with a target
     * section, that section's entry types. Other blocks list every content entry type.
     *
     * @param ElementInterface|null $element
     * @return list<array<string,string>>
     */
    private function _fieldOptions(?ElementInterface $element): array
    {
        $options = [['optgroup' => Craft::t('design-field', 'Entry')]];
        foreach (array_diff(FormatPicker::NATIVE, FormatPicker::PRODUCT_PARTS) as $attribute) {
            $options[] = ['label' => $attribute, 'value' => $attribute];
        }

        // Product parts only where a product is shown: the product block.
        if ($this->_isProductBlock($element)) {
            $options[] = ['optgroup' => Craft::t('design-field', 'Product')];
            foreach (FormatPicker::PRODUCT_PARTS as $part) {
                $options[] = ['label' => $part, 'value' => $part];
            }
        }

        $types = $this->_ownerTypes($element) ?? array_filter(
            Craft::$app->getEntries()->getAllEntryTypes(),
            static fn(EntryType $type) => !str_starts_with($type->handle, 'block'),
        );

        $isProduct = $this->_isProductBlock($element);
        foreach ($types as $type) {
            $fields = $this->_offeredFields($type, $isProduct);
            if ($fields === []) {
                continue;
            }
            // `typeId` lets the Section filter find this group again (select templates ignore it).
            $options[] = ['optgroup' => (string)ArrayHelper::getValue($type, 'name'), 'typeId' => ArrayHelper::getValue($type, 'id')];
            foreach ($fields as $field) {
                $options[] = ['label' => "$field->name ($field->handle)", 'value' => $field->handle];
            }
        }

        return $options;
    }

    /**
     * The fields of one entry type (or product type) a row can show: those some format can print.
     *
     * @param EntryType|FieldLayoutProviderInterface $type
     * @param bool $isProduct On the product block a part (rating, gallery…) covers the field of the same name
     * @return list<FieldInterface>
     */
    private function _offeredFields(FieldLayoutProviderInterface $type, bool $isProduct): array
    {
        return array_values(array_filter($type->getFieldLayout()->getCustomFields(), static fn($field) => FormatPicker::auto($field::class, array_values(class_parents($field) ?: [])) !== null
            && !($isProduct && in_array($field->handle, FormatPicker::PRODUCT_PARTS, true))));
    }

    /**
     * For a block with its own section field (the Entry List's Section): that field's handle and,
     * per section id, the positions of its entry types' groups in the Field dropdown. The browser
     * keeps only the ticked sections' groups (and `keep`, the Entry group), live; nothing ticked
     * offers every field. Null for other blocks.
     *
     * @param ElementInterface|null $element
     * @param list<array<string,mixed>> $options The Field column's options, as rendered
     * @return array{field: string, groups: array<int, list<int>>, keep: list<int>}|null
     */
    private function _sectionFilter(?ElementInterface $element, array $options): ?array
    {
        $handle = Plugin::getInstance()->getSettings()->entryFieldSectionField;
        if ($handle === '' || !$element instanceof Entry || !$element->getFieldLayout()?->getFieldByHandle($handle) || $this->_ownerTypes($element) !== null) {
            return null;
        }

        // Group position => entry type id; groups without one (Entry, missing fields) always stay.
        $byType = [];
        $keep = [];
        $position = -1;
        foreach ($options as $option) {
            if (!isset($option['optgroup'])) {
                continue;
            }
            $position++;
            if (isset($option['typeId'])) {
                $byType[(int)$option['typeId']][] = $position;
            } else {
                $keep[] = $position;
            }
        }

        $groups = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $groups[$section->id] = array_merge([], ...array_map(static fn(EntryType $type) => $byType[$type->id] ?? [], $section->getEntryTypes()));
        }

        return ['field' => $handle, 'groups' => $groups, 'keep' => $keep];
    }

    /**
     * Entry types (or the product type) whose fields an owner-bound block can show, or null to offer all.
     *
     * @param ElementInterface|null $element
     * @return list<EntryType|FieldLayoutProviderInterface>|null
     */
    private function _ownerTypes(?ElementInterface $element): ?array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$element instanceof Entry || !in_array($element->getType()->handle, $settings->entryFieldOwnerBlocks, true)) {
            return null;
        }

        $owner = $element->getPrimaryOwner() ?? $element->getOwner();
        if (!$owner instanceof Entry) {
            return null;
        }

        // A product layout: the product type's fields.
        if ($owner->getFieldLayout()?->getFieldByHandle('targetProductType') && class_exists(\craft\commerce\Plugin::class)) {
            $value = $owner->getFieldValue('targetProductType');
            $id = is_iterable($value) ? (array_values(is_array($value) ? $value : iterator_to_array($value))[0] ?? null) : $value;
            $id = is_object($id) ? ($id->id ?? null) : $id;
            $productType = is_numeric($id) ? \craft\commerce\Plugin::getInstance()->getProductTypes()->getProductTypeById((int)$id) : null;
            if ($productType) {
                return [$productType];
            }
        }

        $sectionField = $settings->entryFieldTargetSectionField;
        if ($sectionField !== '' && $owner->getFieldLayout()?->getFieldByHandle($sectionField)) {
            $id = $owner->getFieldValue($sectionField);
            $section = is_numeric($id) ? Craft::$app->getEntries()->getSectionById((int)$id) : null;

            return $section ? array_values($section->getEntryTypes()) : null;
        }

        return [$owner->getType()];
    }

    /**
     * The rows an ordered block shows while it has none: the followed layout, as Content rows or
     * as the product block's two columns.
     *
     * @return list<Row>
     */
    private function _defaultRows(?ElementInterface $element): array
    {
        $elements = Plugin::getInstance()->getEntryFieldsRenderer()->layoutElements($this->_layoutFor($element));

        return $this->_isProductBlock($element)
            ? LayoutRows::productDefaults($elements, $this->_ownerFieldHandles($element))
            : LayoutRows::defaults($elements, $this->_ownerFieldHandles($element));
    }

    /**
     * The product detail block: shows a Commerce product in two columns.
     */
    private function _isProductBlock(?ElementInterface $element): bool
    {
        return $element instanceof Entry && $element->getType()->handle === 'blockProductDetail';
    }

    /**
     * The block's own Matrix, when the layout it follows is its owner's (no target section).
     *
     * @return list<string>
     */
    private function _ownerFieldHandles(?ElementInterface $element): array
    {
        if (!$element instanceof Entry) {
            return [];
        }
        $owner = $element->getPrimaryOwner() ?? $element->getOwner();
        $types = $this->_ownerTypes($element);
        // Only when the followed layout is the owner's own (not a target section's entry type).
        if ($types && (!$owner instanceof Entry || ArrayHelper::getValue($types[0], 'id') !== $owner->getTypeId())) {
            return [];
        }

        return Plugin::getInstance()->getEntryFieldsRenderer()->ownerFieldHandles($element, $owner);
    }

    /**
     * The field layout an ordered block follows: its owner layout's target section (or product
     * type), else the owner entry's own layout.
     */
    private function _layoutFor(?ElementInterface $element): ?FieldLayout
    {
        $types = $this->_ownerTypes($element);
        if ($types) {
            return $types[0]->getFieldLayout();
        }

        $owner = $element instanceof Entry ? ($element->getPrimaryOwner() ?? $element->getOwner()) : null;

        return $owner?->getFieldLayout();
    }

    /**
     * Which formats fit each field the dropdown offers, and what `auto` means for it, so the
     * browser can narrow the Format column per row. FormatPicker stays the only source.
     *
     * @param list<array<string,string>> $fieldOptions
     * @param bool $isProduct Product block: product parts are offered (and win over same-named fields)
     * @return array<string, array{formats: list<string>, auto: string|null}>
     */
    private function _formatMeta(array $fieldOptions, bool $isProduct = false): array
    {
        $renderer = Plugin::getInstance()->getEntryFieldsRenderer();
        $meta = ['' => ['formats' => ['element'], 'auto' => null]];

        foreach ($fieldOptions as $option) {
            $handle = $option['value'] ?? null;
            if ($handle === null || $handle === '' || isset($meta[$handle])) {
                continue;
            }

            if ($isProduct && in_array($handle, FormatPicker::PRODUCT_PARTS, true)) {
                $meta[$handle] = ['formats' => ['part'], 'auto' => null];
                continue;
            }
            if (in_array($handle, FormatPicker::NATIVE, true)) {
                $formats = array_values(array_filter(FormatPicker::FORMATS, static fn(string $f) => FormatPicker::nativeFits($f, $handle)));
                $meta[$handle] = ['formats' => $formats, 'auto' => FormatPicker::forNative($handle)];
                continue;
            }

            $field = Craft::$app->getFields()->getFieldByHandle($handle);
            if (!$field) {
                continue;
            }
            $parents = array_values(class_parents($field) ?: []);
            $meta[$handle] = [
                'formats' => array_values(array_filter(FormatPicker::all(), static fn(string $f) => FormatPicker::fits($f, $field::class, $parents))),
                'auto' => $renderer->autoFormat($field),
            ];
        }

        return $meta;
    }

    /**
     * Where "New" creates a Global Element: its section, first entry type and the site.
     *
     * @return array{sectionId: int|null, typeId: int|null, siteId: int|null, elementType: string}
     */
    private function _elementConfig(?ElementInterface $element): array
    {
        $section = Craft::$app->getEntries()->getSectionByHandle(Plugin::getInstance()->getSettings()->entryFieldElementSection);

        return [
            'sectionId' => $section?->id,
            'typeId' => $section?->getEntryTypes()[0]->id ?? null,
            'siteId' => $element->siteId ?? Craft::$app->getSites()->getPrimarySite()->id,
            'elementType' => Entry::class,
        ];
    }

    /**
     * Entries of the Global Elements section, for rows with format `element`.
     *
     * @return list<array{label:string,value:string}>
     */
    private function _elementOptions(): array
    {
        $options = [['label' => '—', 'value' => '']];
        $section = Plugin::getInstance()->getSettings()->entryFieldElementSection;
        foreach (Entry::find()->section($section)->status(null)->orderBy('title')->all() as $entry) {
            $options[] = ['label' => (string)$entry->title, 'value' => (string)$entry->id];
        }

        return $options;
    }

    /**
     * @param list<string> $values
     * @return list<array{label:string,value:string}>
     */
    private function _plainOptions(array $values): array
    {
        return array_map(static fn(string $v) => ['label' => $v, 'value' => $v], $values);
    }

    /**
     * @return list<array{label:string,value:string}>
     */
    private function _styleOptions(): array
    {
        $options = [];
        foreach (Plugin::getInstance()->getGroups()->loadTokens('entry-field-style') as $key => $token) {
            $options[] = ['label' => is_array($token) ? (string)($token['label'] ?? $key) : (string)$key, 'value' => (string)$key];
        }

        return $options;
    }
}
