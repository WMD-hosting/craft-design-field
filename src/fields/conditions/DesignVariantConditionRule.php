<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\fields\conditions;

use CraftCms\Cms\Condition\BaseMultiSelectConditionRule;
use CraftCms\Cms\Element\Conditions\Contracts\ElementConditionRuleInterface;
use CraftCms\Cms\Field\Conditions\Contracts\FieldConditionRuleInterface;
use CraftCms\Cms\Field\Conditions\FieldConditionRuleTrait;
use Override;
use wmd\designfield\fields\Design;
use wmd\designfield\models\DesignValue;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;

/**
 * "Design → Variant is one of …": the condition rule a Design field brings to element conditions.
 *
 * Its main use is a field layout element's "Show when": a campaign's Image field shows only for the variants
 * that render an image, so an editor never fills in a field the chosen variant ignores. Craft checks the
 * condition again each time it refreshes the form, against the choices in the open editor, so the form follows
 * the variant picked in the Design field before anything is saved.
 *
 * The options are the variants of the profile of the layout's entry type (the same ones the field offers
 * there); an entry type that is not saved yet lists every variant of every profile. An element whose value
 * has no variant matches only "is empty".
 *
 * The rule tests an element at hand. It is not offered where a condition filters a query (element sources,
 * relation fields): the value is JSON, and filtering queries by it is not supported.
 *
 * @author WMD
 * @since 1.0.6
 */
class DesignVariantConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface, FieldConditionRuleInterface
{
    use FieldConditionRuleTrait {
        getLabel as private fieldLabel;
    }

    // Const Properties
    // =========================================================================

    /**
     * Handle of the option group that holds a block's layout variant.
     */
    public const GROUP = 'variant';

    // Protected Properties
    // =========================================================================

    #[Override]
    protected bool $includeEmptyOperators = true;

    // Public Static Methods
    // =========================================================================

    /**
     * The variant a field value holds: its chosen key, the default one when none was picked,
     * and null when the element has no variants (or the value is no Design value).
     *
     * @param mixed $value
     * @return string|null
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function variantKey(mixed $value): ?string
    {
        if (!$value instanceof DesignValue || !$value->has(self::GROUP)) {
            return null;
        }

        $key = $value->get(self::GROUP)->key;

        return $key !== '' ? $key : null;
    }

    /**
     * The variants of several option sets as one list: each key once, under the name
     * the first set gives it, in the order the sets list them.
     *
     * @param iterable<array<string,Group>> $sets
     * @return list<array{value:string,label:string}>
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function variantOptions(iterable $sets): array
    {
        $options = [];

        foreach ($sets as $groups) {
            foreach (($groups[self::GROUP] ?? null)?->options ?? [] as $key => $option) {
                $key = (string)$key;
                $options[$key] ??= ['value' => $key, 'label' => $option['label'] !== '' ? $option['label'] : $key];
            }
        }

        return array_values($options);
    }

    // Public Methods
    // =========================================================================

    public function getLabel(): string
    {
        return $this->fieldLabel() . ' → Variant';
    }

    // Protected Methods
    // =========================================================================

    /**
     * Variants of the profiles the rule's field resolves to in the condition's field layouts.
     * An entry type that is not saved yet has no profile to go by, so it is offered the
     * variants of every profile.
     *
     * @return list<array{value:string,label:string}>
     *
     * @author WMD
     * @since 1.0.6
     */
    #[Override]
    protected function options(): array
    {
        $registry = app(Groups::class)->getRegistry();
        $sets = [];

        foreach ($this->fieldInstances() as $field) {
            if (!$field instanceof Design) {
                continue;
            }

            array_push($sets, ...($field->knowsItsProfile()
                ? $field->optionSets()
                : array_map($registry->groupsFor(...), $registry->profileNames())));
        }

        return self::variantOptions($sets);
    }

    protected function elementQueryParam(): mixed
    {
        return null;
    }

    protected function matchFieldValue(mixed $value): bool
    {
        if (!$this->field() instanceof Design) {
            return true;
        }

        return $this->matchValue(self::variantKey($value));
    }
}
