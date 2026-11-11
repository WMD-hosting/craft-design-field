<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\fields\conditions\DesignVariantConditionRule;
use wmd\designfield\helpers\Registry;

/**
 * What the Variant rule compares (the variant a value holds) and what it offers
 * (the variants of the profiles it is asked about), without Craft.
 */
final class DesignVariantConditionRuleTest extends TestCase
{
    private function registry(): Registry
    {
        return Registry::fromConfig([
            'groups' => [
                'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black']],
            ],
            'profiles' => [
                'headerSearch' => ['variant' => ['options' => [
                    '_default' => ['label' => 'Search popup'],
                    'panel' => ['label' => 'Side panel'],
                    'instant' => ['label' => 'Search field'],
                    'bar' => ['label' => 'Search bar'],
                ]]],
                'blockPricing' => ['tone', 'variant' => ['options' => [
                    '_default' => ['label' => 'Tiers'],
                    'plan-cards' => ['label' => 'Plan cards'],
                    'bar' => ['label' => 'Price bar'],
                ]]],
                'blockPlain' => ['tone'],
            ],
        ], static fn(string $name) => []);
    }

    public function testTheVariantOfAValueIsTheKeyItHolds(): void
    {
        $registry = $this->registry();

        self::assertSame('bar', DesignVariantConditionRule::variantKey($registry->value(['variant' => 'bar'], 'headerSearch')));
        self::assertSame('plan-cards', DesignVariantConditionRule::variantKey($registry->value(['tone' => 'dark', 'variant' => 'plan-cards'], 'blockPricing')));
    }

    public function testABlockThatNeverPickedALayoutIsOnTheDefaultOne(): void
    {
        self::assertSame('_default', DesignVariantConditionRule::variantKey($this->registry()->value([], 'headerSearch')));
    }

    public function testABlockWithoutVariantsHasNone(): void
    {
        $registry = $this->registry();

        self::assertNull(DesignVariantConditionRule::variantKey($registry->value(['tone' => 'dark'], 'blockPlain')));

        // A variant stored while the block still had some is not one any more.
        self::assertNull(DesignVariantConditionRule::variantKey($registry->value(['variant' => 'bar'], 'blockPlain')));
    }

    public function testAValueThatIsNoDesignValueHasNone(): void
    {
        self::assertNull(DesignVariantConditionRule::variantKey(null));
        self::assertNull(DesignVariantConditionRule::variantKey('bar'));
        self::assertNull(DesignVariantConditionRule::variantKey(['variant' => 'bar']));
    }

    public function testTheOptionsAreTheVariantsOfTheProfileInPanelOrder(): void
    {
        self::assertSame([
            ['value' => '_default', 'label' => 'Search popup'],
            ['value' => 'panel', 'label' => 'Side panel'],
            ['value' => 'instant', 'label' => 'Search field'],
            ['value' => 'bar', 'label' => 'Search bar'],
        ], DesignVariantConditionRule::variantOptions([$this->registry()->groupsFor('headerSearch')]));
    }

    public function testSeveralProfilesListEachVariantOnceUnderItsFirstName(): void
    {
        $registry = $this->registry();

        self::assertSame([
            ['value' => '_default', 'label' => 'Search popup'],
            ['value' => 'panel', 'label' => 'Side panel'],
            ['value' => 'instant', 'label' => 'Search field'],
            ['value' => 'bar', 'label' => 'Search bar'],
            ['value' => 'plan-cards', 'label' => 'Plan cards'],
        ], DesignVariantConditionRule::variantOptions([$registry->groupsFor('headerSearch'), $registry->groupsFor('blockPricing')]));
    }

    public function testAProfileWithoutVariantsOffersNothing(): void
    {
        self::assertSame([], DesignVariantConditionRule::variantOptions([$this->registry()->groupsFor('blockPlain')]));
        self::assertSame([], DesignVariantConditionRule::variantOptions([]));
    }
}
