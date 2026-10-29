<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\fields\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\fields\conditions\FieldConditionRuleInterface;
use craft\fields\conditions\FieldConditionRuleTrait;
use craft\models\FieldLayout;
use wmd\designfield\fields\Design;
use wmd\designfield\models\DesignValue;
use wmd\designfield\Plugin;

/**
 * "Design → Variant is one of …": the condition rule a Design field brings to element conditions.
 *
 * Its main use is a field layout element's "Show when": a campaign's Image field shows only for the variants
 * that render an image, so an editor never fills in a field the chosen variant ignores. Craft re-checks the
 * condition as the entry is edited, so the form follows the variant picked in the Design field.
 *
 * The options are the variants of the profile of the layout's entry type (the same ones the field offers
 * there); a condition built outside a layout lists every variant of every profile. An element whose value has
 * no variant matches only "is empty".
 *
 * @author WMD
 * @since 1.0.6
 */
class DesignVariantConditionRule extends BaseMultiSelectConditionRule implements FieldConditionRuleInterface
{
    use FieldConditionRuleTrait {
        getLabel as private fieldLabel;
    }

    // Constants
    // =========================================================================

    /**
     * Handle of the option group that holds a block's layout variant.
     */
    public const GROUP = 'variant';

    // Properties
    // =========================================================================

    protected bool $includeEmptyOperators = true;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return $this->fieldLabel() . ' → ' . Craft::t('design-field', 'Variant');
    }

    // Protected Methods
    // =========================================================================

    /**
     * Variants of the profiles of the condition's field layouts, in the order the field shows them.
     *
     * @return array<int,array{value:string,label:string}>
     *
     * @author WMD
     * @since 1.0.6
     */
    protected function options(): array
    {
        $registry = Plugin::getInstance()->getGroups()->getRegistry();
        $profiles = array_values(array_filter(array_map(
            static fn(FieldLayout $layout) => self::_providerHandle($layout),
            $this->getCondition()->getFieldLayouts(),
        )));
        $sets = $profiles !== []
            ? array_map(static fn(string $profile) => $registry->groupsFor($profile), $profiles)
            : array_map(static fn(string $profile) => $registry->groupsFor($profile), $registry->profileNames());

        $options = [];
        foreach ($sets as $groups) {
            if (!isset($groups[self::GROUP])) {
                continue;
            }
            foreach ($groups[self::GROUP]->options as $key => $option) {
                $options[(string)$key] ??= ['value' => (string)$key, 'label' => $option['label'] !== '' ? $option['label'] : (string)$key];
            }
        }

        return array_values($options);
    }

    /**
     * @inheritdoc
     */
    protected function elementQueryParam(): string|array|null
    {
        // The value is JSON; filtering element queries by it is not supported.
        return null;
    }

    /**
     * @inheritdoc
     */
    protected function matchFieldValue($value): bool
    {
        if (!$this->field() instanceof Design) {
            return true;
        }

        $key = $value instanceof DesignValue && $value->has(self::GROUP) ? $value->get(self::GROUP)->key : null;

        return $this->matchValue($key !== '' ? $key : null);
    }

    // Private Methods
    // =========================================================================

    /**
     * Handle of the entry type (or other provider) that owns a field layout, the name its Design profile goes by.
     *
     * @param FieldLayout $layout
     * @return string|null
     *
     * @author WMD
     * @since 1.0.6
     */
    private static function _providerHandle(FieldLayout $layout): ?string
    {
        $provider = $layout->provider;

        return $provider !== null && isset($provider->handle) ? (string)$provider->handle : null;
    }
}
