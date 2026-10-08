<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Assets;
use craft\fields\Date;
use craft\fields\Matrix;
use craft\fields\Table;
use craft\helpers\HtmlPurifier;
use craft\models\FieldLayout;
use craft\web\View;
use DateTimeInterface;
use Throwable;
use Twig\Markup;
use wmd\designfield\entryfields\Coords;
use wmd\designfield\entryfields\EntryFieldsValue;
use wmd\designfield\entryfields\FormatPicker;
use wmd\designfield\entryfields\LayoutRows;
use wmd\designfield\entryfields\RenderStack;
use wmd\designfield\entryfields\Row;
use wmd\designfield\entryfields\RowMarkup;
use wmd\designfield\entryfields\TableData;
use wmd\designfield\fields\EntryFields;
use wmd\designfield\Plugin;
use yii\base\Component;

/**
 * Renders the Entry Fields rows of one slot.
 *
 * @author WMD
 * @since 1.1.0
 */
class EntryFieldsRenderer extends Component
{
    // Constants
    // =========================================================================

    public const TEMPLATE_PATH = '_atoms/entry-field';

    /** Label and Text after: inline text and icons only. */
    public const PURIFIER_CONFIG = [
        'HTML.Allowed' => 'i[class],span[class],strong,em,b,br,small,a[href|class|target],img[src|alt|class|width|height]',
        'Attr.AllowedFrameTargets' => ['_blank'],
    ];

    // Private Properties
    // =========================================================================

    private ?RenderStack $_stack = null;

    /** The block being rendered, for format templates that read its Design options (cover ratio). */
    private mixed $_block = null;

    /** The product block's buy form id while its columns render. */
    private ?string $_form = null;

    // Public Methods
    // =========================================================================

    /**
     * @param mixed $block Block entry, or a mock map with an `entryFields` key, or an EntryFieldsValue
     * @param mixed $entry Element or mock map whose fields are shown
     * @param string $slot
     * @return Markup
     *
     * @author WMD
     * @since 1.1.0
     */
    public function render(mixed $block, mixed $entry, string $slot): Markup
    {
        return $this->_renderValue($block, $this->_value($block), $entry, $slot);
    }

    /**
     * The ordered Content variant: the block's rows in stored order (slots ignored), or,
     * with no rows, the shown entry's field layout in order (LayoutRows::defaults()).
     *
     * @param mixed $block
     * @param mixed $entry
     * @return Markup
     *
     * @author WMD
     * @since 1.1.0
     */
    public function renderOrdered(mixed $block, mixed $entry): Markup
    {
        $value = $this->_value($block);
        $rows = [...$this->_presetRows($value, $this->_presets()), ...$value->rows];

        if ($rows === [] && $entry instanceof ElementInterface) {
            $rows = LayoutRows::defaults($this->layoutElements($entry->getFieldLayout()), $this->ownerFieldHandles($block, $entry));
        }
        if ($rows === []) {
            return new Markup('', 'UTF-8');
        }

        // Every row goes into the one ordered slot (left side of + wins), then the normal path
        // renders it: render stack keyed by the real block, styles, per-row safety.
        $slotted = new EntryFieldsValue(null, array_map(
            static fn(Row $r) => Row::fromArray(['slot' => LayoutRows::SLOT] + $r->toArray()) ?? $r,
            $rows,
        ));

        return $this->_renderValue($block, $slotted, $entry, LayoutRows::SLOT);
    }

    /**
     * The product block: rows in two columns (Gallery / Details) plus a full-width area below,
     * in stored order, or the default product columns from the product type layout. Rows without a
     * column go to Details.
     *
     * @param mixed $block
     * @param mixed $entry The product
     * @param string|null $form Id of the block's buy form; the picker and cart controls join it
     * @return array{gallery: Markup, details: Markup, below: Markup}
     *
     * @author WMD
     * @since 1.1.0
     */
    public function renderColumns(mixed $block, mixed $entry, ?string $form = null): array
    {
        $previousForm = $this->_form;
        $this->_form = $form;

        try {
            return $this->_columns($block, $entry);
        } finally {
            $this->_form = $previousForm;
        }
    }

    /**
     * @return array{gallery: Markup, details: Markup, below: Markup}
     */
    private function _columns(mixed $block, mixed $entry): array
    {
        $value = $this->_value($block);
        $rows = [...$this->_presetRows($value, $this->_presets()), ...$value->rows];
        if ($rows === [] && $entry instanceof ElementInterface) {
            $rows = LayoutRows::productDefaults($this->layoutElements($entry->getFieldLayout()), $this->ownerFieldHandles($block, $entry));
        }

        $columns = ['gallery' => [], 'details' => [], 'below' => []];
        foreach ($rows as $row) {
            $columns[$row->column ?? 'details'][] = Row::fromArray(['slot' => LayoutRows::SLOT] + $row->toArray()) ?? $row;
        }

        return array_map(
            fn(array $rows) => $rows === [] ? new Markup('', 'UTF-8') : $this->_renderValue($block, new EntryFieldsValue(null, $rows), $entry, LayoutRows::SLOT),
            $columns,
        );
    }

    /**
     * What `auto` resolves to for a field type, refined the way rendering refines it
     * (date vs time, Matrix shape). Assets stay `files`: images-only is decided by the value.
     *
     * @param FieldInterface $field
     * @return string|null
     *
     * @author WMD
     * @since 1.1.0
     */
    public function autoFormat(FieldInterface $field): ?string
    {
        $format = FormatPicker::auto($field::class, array_values(class_parents($field) ?: []));

        return match (true) {
            $format === null => null,
            $field instanceof Date => FormatPicker::refineDate((bool)$field->showDate, (bool)$field->showTime),
            $field instanceof Matrix => FormatPicker::forMatrix($this->_matrixTypes($field)),
            default => $format,
        };
    }

    /**
     * The Matrix field that holds the block, when the shown entry is the block's owner:
     * rendering it from inside the block would repeat every sibling block.
     *
     * @return list<string>
     *
     * @author WMD
     * @since 1.1.0
     */
    public function ownerFieldHandles(mixed $block, mixed $entry): array
    {
        if (!$block instanceof Entry || !$entry instanceof ElementInterface || !$block->fieldId || $block->getOwnerId() !== $entry->id) {
            return [];
        }

        $handle = Craft::$app->getFields()->getFieldById($block->fieldId)?->handle;

        return $handle ? [$handle] : [];
    }

    /**
     * Layout elements in order: the title element and every custom field, with what LayoutRows needs.
     *
     * @return list<array{kind:string, handle:string, class:string, parents:list<string>, matrix?:string}>
     */
    public function layoutElements(?FieldLayout $layout): array
    {
        $elements = [];
        foreach ($layout?->getTabs() ?? [] as $tab) {
            foreach ($tab->getElements() as $element) {
                if ($element instanceof EntryTitleField) {
                    $elements[] = ['kind' => 'title', 'handle' => 'title', 'class' => '', 'parents' => []];
                } elseif ($element instanceof CustomField) {
                    $field = $element->getField();
                    $item = ['kind' => 'field', 'handle' => $field->handle, 'class' => $field::class, 'parents' => array_values(class_parents($field) ?: [])];
                    if ($field instanceof Matrix) {
                        $item['matrix'] = FormatPicker::forMatrix($this->_matrixTypes($field));
                    }
                    $elements[] = $item;
                }
            }
        }

        return $elements;
    }

    // Private Methods
    // =========================================================================

    /**
     * Rows of one slot for a block's value: render stack, styles, per-row safety.
     */
    private function _renderValue(mixed $block, EntryFieldsValue $value, mixed $entry, string $slot): Markup
    {
        if ($value->isEmpty() || $entry === null) {
            return new Markup('', 'UTF-8');
        }

        // A row can show the page builder that holds this very block; never render a block inside itself.
        $key = $block instanceof ElementInterface ? "block:$block->id" : 'mock';
        $this->_stack ??= new RenderStack();
        if (!$this->_stack->enter($key)) {
            return new Markup('', 'UTF-8');
        }

        $previousBlock = $this->_block;
        $this->_block = $block;

        try {
            $styles = Plugin::getInstance()->getGroups()->loadTokens('entry-field-style');
            $html = '';

            foreach ($value->rowsFor($slot, $this->_presets()) as $row) {
                $html .= $this->_safeRow($row, $entry, $styles);
            }
        } finally {
            $this->_stack->leave($key);
            $this->_block = $previousBlock;
        }

        return new Markup($html, 'UTF-8');
    }

    /**
     * Preset rows by preset name, from config (shape not trusted).
     *
     * @return array<string,array<mixed>>
     */
    private function _presets(): array
    {
        return array_map(static fn(array $p) => is_array($p['rows'] ?? null) ? $p['rows'] : [], Plugin::getInstance()->getSettings()->entryFieldPresets);
    }

    /**
     * The value's preset rows as Rows, in order, any slot.
     *
     * @param array<string,array<mixed>> $presets
     * @return list<Row>
     */
    private function _presetRows(EntryFieldsValue $value, array $presets): array
    {
        $rows = [];
        foreach ($value->preset !== null ? ($presets[$value->preset] ?? []) : [] as $data) {
            $row = is_array($data) ? Row::fromArray($data) : null;
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function _value(mixed $block): EntryFieldsValue
    {
        if ($block instanceof EntryFieldsValue) {
            return $block;
        }

        if ($block instanceof ElementInterface) {
            foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if ($field instanceof EntryFields) {
                    return EntryFieldsValue::fromMixed($block->getFieldValue($field->handle));
                }
            }

            return new EntryFieldsValue();
        }

        return EntryFieldsValue::fromMixed(is_array($block) ? ($block['entryFields'] ?? null) : null);
    }

    /**
     * One row that fails (an odd value, a broken format template) is skipped and logged, never a 500.
     *
     * @param array<string,mixed> $styles
     */
    private function _safeRow(Row $row, mixed $entry, array $styles): string
    {
        try {
            return $this->_row($row, $entry, $styles);
        } catch (Throwable $e) {
            Craft::warning("Entry Fields: row \"$row->field\" ($row->format) skipped: {$e->getMessage()}", 'design-field');

            return '';
        }
    }

    /**
     * @param array<string,mixed> $styles
     */
    private function _row(Row $row, mixed $entry, array $styles): string
    {
        if ($row->format === 'element') {
            // A Global Element may (through its own blocks) contain this same row: render it once.
            $key = "element:$row->element";
            if (!$this->_stack?->enter($key)) {
                return '';
            }
            try {
                return $this->_emit($row, 'element', $this->_element($row), $entry, null, $styles);
            } finally {
                $this->_stack->leave($key);
            }
        }

        // A product part (format `part`) is the native, even when the product type has a field
        // with the same handle (products have a `rating` field and a `rating` part).
        [$raw, $field, $native] = $this->_resolve($row->field, $entry, $row->format === 'part');
        if ($raw === null || $raw === '' || $raw === false || $raw === []) {
            return '';
        }

        $format = $this->_format($row, $field, $native, $raw);
        if ($format === null) {
            Craft::info("Entry Fields: no format can show \"$row->field\"", 'design-field');

            return '';
        }

        $value = $this->_prepare($format, $raw, $field);
        // A Matrix whose item type has a `coords` field but whose items carry none is a list, not a map.
        if ($format === 'map' && $value === [] && $field instanceof Matrix) {
            $format = 'items';
            $value = $this->_prepare($format, $raw, $field);
        }

        return $this->_emit($row, $format, $value, $entry, $field, $styles);
    }

    /**
     * Renders the format template and wraps it; empty values render nothing.
     *
     * @param array<string,mixed> $styles
     */
    private function _emit(Row $row, string $format, mixed $value, mixed $entry, ?FieldInterface $field, array $styles): string
    {
        if ($value === null || $value === [] || (is_array($value) && array_key_exists('rows', $value) && $value['rows'] === [])) {
            return '';
        }

        // Product parts each have their own template under part/, the developer's formats under custom/.
        $template = match (true) {
            $format === 'part' => self::TEMPLATE_PATH . "/part/$row->field",
            FormatPicker::isCustom($format) => self::TEMPLATE_PATH . "/custom/$format",
            default => self::TEMPLATE_PATH . "/$format",
        };
        $valueHtml = Craft::$app->getView()->renderTemplate(
            $template,
            [
                'value' => $value,
                'entry' => $entry,
                'row' => $row,
                'field' => $field,
                'block' => $this->_block instanceof ElementInterface ? $this->_block : null,
                // The product block's buy form: the picker and cart controls join it by id.
                'form' => $this->_form ?? ($entry instanceof ElementInterface ? "buy-$entry->id" : null),
            ],
            View::TEMPLATE_MODE_SITE,
        );

        if (RowMarkup::isEmpty($valueHtml)) {
            return '';
        }

        return RowMarkup::wrap(
            $row,
            $valueHtml,
            $row->label !== '' ? HtmlPurifier::process($row->label, self::PURIFIER_CONFIG) : '',
            $row->textAfter !== '' ? HtmlPurifier::process($row->textAfter, self::PURIFIER_CONFIG) : '',
            $styles,
        );
    }

    /**
     * The format to render with: an explicit format when it fits, else auto, refined by the value.
     */
    private function _format(Row $row, ?FieldInterface $field, bool $native, mixed $raw): ?string
    {
        if ($field === null) {
            if ($native) {
                return FormatPicker::nativeFits($row->format, $row->field) && $row->format !== 'auto' ? $row->format : FormatPicker::forNative($row->field);
            }
            // Mock maps: the row names the format; `auto` is plain text and only for plain values.
            if ($row->format === 'auto') {
                return is_scalar($raw) ? 'text' : null;
            }

            return $row->format;
        }

        $parents = array_values(class_parents($field) ?: []);
        if ($row->format !== 'auto' && FormatPicker::fits($row->format, $field::class, $parents)) {
            return $row->format;
        }

        $format = FormatPicker::auto($field::class, $parents);

        return match (true) {
            $format === null => null,
            $field instanceof Date => FormatPicker::refineDate((bool)$field->showDate, (bool)$field->showTime),
            $field instanceof Assets => FormatPicker::refineAssets(array_values(array_map(
                static fn(Asset $a) => $a->kind,
                array_filter($raw instanceof AssetQuery ? $raw->all() : (is_iterable($raw) ? [...$raw] : []), static fn($a) => $a instanceof Asset),
            ))),
            $field instanceof Matrix => FormatPicker::forMatrix($this->_matrixTypes($field)),
            default => $format,
        };
    }

    /**
     * @return list<array{handle:string, fields:list<string>, block:bool}>
     */
    private function _matrixTypes(Matrix $field): array
    {
        $root = Craft::$app->getPath()->getSiteTemplatesPath() . '/_blocks/';
        $types = [];
        foreach ($field->getEntryTypes() as $type) {
            $types[] = [
                'handle' => $type->handle,
                'fields' => array_values(array_map(static fn($f) => $f->handle, $type->getFieldLayout()->getCustomFields())),
                'block' => is_dir($root . $type->handle) || is_file($root . $type->handle . '.twig'),
            ];
        }

        return $types;
    }

    /**
     * Turns structured values into the plain shapes the format templates read.
     */
    private function _prepare(string $format, mixed $raw, ?FieldInterface $field): mixed
    {
        $all = static fn(mixed $v) => is_object($v) && method_exists($v, 'all') ? $v->all() : (is_iterable($v) ? [...$v] : []);

        return match ($format) {
            'table' => match (true) {
                $field instanceof Table => TableData::fromColumns($field->columns, is_array($raw) ? $raw : []),
                is_object($raw) && method_exists($raw, 'getData') => TableData::fromCells(
                    array_map(fn($r) => is_array($r) ? array_map(fn($c) => HtmlPurifier::process((string)$c, self::PURIFIER_CONFIG), $r) : $r, $this->_legsCells($raw->getData())),
                    true,
                ),
                is_array($raw) && isset($raw['rows']) => TableData::fromCells([$raw['head'] ?? [], ...$raw['rows']]),
                default => TableData::fromPairs(array_map(fn(array $i) => [$i['heading'], strip_tags((string)$i['body'])], array_map($this->_itemMap(...), $all($raw)))),
            },
            'map' => $this->_markers($raw, $all),
            'dates', 'items' => array_map($this->_itemMap(...), $all($raw)),
            // Custom templates get relations as a plain list, the same on entries and mock maps.
            default => FormatPicker::isCustom($format) && is_object($raw) && method_exists($raw, 'all') ? $all($raw) : $raw,
        };
    }

    /**
     * Cells of a Legs table: its data is a model with a `cells` property (an array in mocks).
     *
     * @return array<int,mixed>
     */
    private function _legsCells(mixed $data): array
    {
        $cells = is_array($data) ? ($data['cells'] ?? []) : (is_object($data) ? ($data->cells ?? []) : []);

        return is_array($cells) ? $cells : [];
    }

    /**
     * @param callable(mixed): list<mixed> $all
     * @return list<array{coords:string, label:string}>
     */
    private function _markers(mixed $raw, callable $all): array
    {
        if (is_string($raw)) {
            $coords = Coords::parse($raw);

            return $coords ? [['coords' => $coords, 'label' => '']] : [];
        }

        $markers = [];
        foreach ($all($raw) as $item) {
            if (is_object($item) && isset($item->latitude, $item->longitude)) {
                $coords = Coords::parse("$item->latitude,$item->longitude");
                $label = (string)($item->title ?? $item->addressLine1 ?? '');
            } else {
                $map = $this->_itemMap($item);
                $coords = Coords::parse($map['coords']);
                $label = $map['heading'];
            }
            if ($coords) {
                $markers[] = ['coords' => $coords, 'label' => $label];
            }
        }

        return $markers;
    }

    /**
     * A Matrix item (or mock map) as a plain map; fields read only when the item's layout has them.
     *
     * @return array{heading:string, body:mixed, date:string, time:string, location:string, coords:string, image:?Asset, icon:mixed, url:?string}
     */
    private function _itemMap(mixed $item): array
    {
        $get = static function(string $handle) use ($item): mixed {
            if (is_array($item)) {
                return $item[$handle] ?? null;
            }
            if ($item instanceof ElementInterface && $item->getFieldLayout()?->getFieldByHandle($handle)) {
                return $item->getFieldValue($handle);
            }

            return null;
        };
        $text = static fn(mixed $v) => $v instanceof DateTimeInterface ? Craft::$app->getFormatter()->asDate($v) : (is_scalar($v) ? trim((string)$v) : '');
        $image = $get('image');
        $icon = $get('iconawesome') ?? $get('icon');

        return [
            'heading' => $text($get('heading')) ?: $text(is_array($item) ? ($item['title'] ?? '') : ($item->title ?? '')),
            'body' => $get('body') ?? $get('text') ?? '',
            'date' => $text($get('date')),
            'time' => $text($get('time')),
            'location' => $text($get('location')),
            'coords' => $text($get('coords')),
            'image' => $image instanceof AssetQuery ? $image->one() : ($image instanceof Asset ? $image : null),
            'icon' => is_object($icon) && method_exists($icon, 'render') ? $icon->render() : null,
            'url' => is_array($item) ? ($item['url'] ?? null) : ($item instanceof ElementInterface ? $item->getUrl() : null),
        ];
    }

    /**
     * The blocks of the row's Global Element.
     *
     * @return list<ElementInterface>|null
     */
    private function _element(Row $row): ?array
    {
        $section = Plugin::getInstance()->getSettings()->entryFieldElementSection;
        $element = Entry::find()->id($row->element)->section($section)->one();
        $builder = $element?->getFieldLayout()?->getFieldByHandle('pageBuilder');

        return $builder ? $element->getFieldValue('pageBuilder')->all() : null;
    }

    /**
     * Value and field of one handle, never touching Entry::__call.
     *
     * @return array{0: mixed, 1: FieldInterface|null, 2: bool} value, field, whether it is a native attribute
     */
    private function _resolve(string $handle, mixed $entry, bool $nativeFirst = false): array
    {
        if (is_array($entry)) {
            return [$entry[$handle] ?? null, null, false];
        }

        if (!$entry instanceof ElementInterface) {
            return [null, null, false];
        }

        $field = $nativeFirst ? null : $entry->getFieldLayout()?->getFieldByHandle($handle);
        if ($field !== null) {
            return [$entry->getFieldValue($handle), $field, false];
        }

        if (in_array($handle, FormatPicker::NATIVE, true)) {
            $variant = method_exists($entry, 'getDefaultVariant') ? $entry->getDefaultVariant() : null;
            $value = match ($handle) {
                'url' => $entry->getUrl(),
                'author' => $entry instanceof Entry ? $entry->getAuthor()?->getName() : null,
                'postDate' => $entry instanceof Entry ? $entry->postDate : ($entry->postDate ?? null),
                'dateUpdated' => $entry->dateUpdated,
                'title' => $entry->title,
                'slug' => $entry->slug,
                'price' => $variant,
                'sku' => $variant?->sku,
                // Product parts: the product itself, only on a Commerce product.
                default => $variant !== null ? $entry : null,
            };

            return [$value, null, true];
        }

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            Craft::info("Entry Fields: \"$handle\" is not on " . $entry::class . " #$entry->id", 'design-field');
        }

        return [null, null, false];
    }
}
