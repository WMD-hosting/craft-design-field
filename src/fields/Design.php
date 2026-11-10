<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\fields;

use CraftCms\Cms\Element\Concerns\NestedElement;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Controls\Textarea;
use Closure;
use InvalidArgumentException;
use Override;
use wmd\designfield\models\DesignValue;
use wmd\designfield\services\Groups;

/**
 * The Design field on Craft 6: one JSON value of option handle => choice key,
 * read through the registry as a DesignValue.
 *
 * Same storage as the Craft 5 plugin, so imported values keep working. The
 * input is a JSON textarea for now; the option UI (swatches, sliders, tiles)
 * is rebuilt on Craft's CP kit later.
 *
 * @author WMD
 * @since 2.0.0
 */
class Design extends Field
{
    // Public Properties
    // =========================================================================

    /**
     * Profile name; empty picks the element's own ({ownerField}:{type}, then {type}).
     */
    public string $profile = '';

    /**
     * Craft 5 input settings, kept so their project config still loads.
     */
    public string $viewMode = 'panel';
    public string $optionLayout = 'sections';

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
        return $this->normalizeValue(is_string($value) ? (Json::decodeIfJson($value) ?? $value) : $value, $element);
    }

    #[Override]
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return $value instanceof DesignValue ? $value->toArray() : $value;
    }

    #[Override]
    public function uiControl(FieldContext $context): Control
    {
        $value = $context->value instanceof DesignValue ? $context->value->toArray() : $context->value;

        return Textarea::make($context->path)
            ->rows(6)
            ->monospace()
            ->value($value ? Json::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null);
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
}
