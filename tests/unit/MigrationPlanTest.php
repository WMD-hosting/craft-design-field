<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use wmd\designfield\helpers\MigrationPlan;
use wmd\designfield\helpers\Registry;

final class MigrationPlanTest extends TestCase
{
    private function profileGroups(): array
    {
        return Registry::fromConfig([
            'groups' => [
                'tone' => ['options' => ['none' => 'bg-bg', 'surface' => 'bg-surface'], 'auto' => 'Block default'],
                'columns' => ['options' => ['3' => ['label' => '3'], '4' => ['label' => '4']], 'default' => '3'],
                'cardStyle' => ['options' => ['flat' => ['label' => 'Flat'], 'elevated' => ['label' => 'Elevated']], 'aliases' => ['shadow' => 'elevated']],
            ],
            'profiles' => [
                'blockTeam' => ['variant' => ['options' => ['_default' => ['label' => 'Grid'], 'cards' => ['label' => 'Cards']]], 'tone', 'columns', 'cardStyle'],
            ],
        ], static fn() => [])->groupsFor('blockTeam');
    }

    private static function obj(array $props): stdClass
    {
        return (object)$props;
    }

    public function testBuildMapsSameHandlesVariantAndExplicitPairs(): void
    {
        $plan = MigrationPlan::build(['accentToken', 'columns', 'cardStyle', 'blockTeamVariant', 'team'], $this->profileGroups(), 'blockTeam', ['accentToken' => 'tone']);

        self::assertSame(['accentToken' => 'tone', 'columns' => 'columns', 'cardStyle' => 'cardStyle', 'blockTeamVariant' => 'variant'], $plan->fields);
    }

    public function testBuildRejectsUnknownExplicitPairs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MigrationPlan::build(['columns'], $this->profileGroups(), 'blockTeam', ['accentToken' => 'tone']);
    }

    public function testParseMap(): void
    {
        self::assertSame(['accentToken' => 'tone', 'paddingToken' => 'spacing'], MigrationPlan::parseMap(' accentToken:tone , paddingToken:spacing '));
        self::assertSame([], MigrationPlan::parseMap(null));
        $this->expectException(InvalidArgumentException::class);
        MigrationPlan::parseMap('accentToken');
    }

    public function testKeyOfReadsTokenOptionAndScalarValues(): void
    {
        self::assertSame('surface', MigrationPlan::keyOf(self::obj(['config' => 'accent.json', 'key' => 'surface'])));
        self::assertSame('cards', MigrationPlan::keyOf(self::obj(['value' => 'cards', 'label' => 'Cards'])));
        self::assertNull(MigrationPlan::keyOf(self::obj(['value' => null])));
        self::assertSame('4', MigrationPlan::keyOf(4));
        self::assertSame('1', MigrationPlan::keyOf(true));
        self::assertNull(MigrationPlan::keyOf(''));
    }

    public function testApplyMovesResolvesAliasesAndReportsUnknown(): void
    {
        $plan = MigrationPlan::build(['accentToken', 'columns', 'cardStyle', 'blockTeamVariant'], $this->profileGroups(), 'blockTeam', ['accentToken' => 'tone']);
        $result = $plan->apply([
            'accentToken' => self::obj(['key' => 'surface']),
            'columns' => self::obj(['value' => '6']),
            'cardStyle' => self::obj(['value' => 'shadow']),
            'blockTeamVariant' => self::obj(['value' => null]),
        ], []);

        self::assertSame(['tone' => 'surface', 'cardStyle' => 'elevated'], $result['moved']);
        self::assertSame(['columns' => '6'], $result['unknown']);
        self::assertSame(['tone' => 'surface', 'cardStyle' => 'elevated'], $result['keys']);
        self::assertSame(['variant'], $result['empty'], 'an empty old field is reported, not moved');
    }

    public function testApplyKeepsEditorChoicesUnlessOverwrite(): void
    {
        $plan = MigrationPlan::build(['columns', 'cardStyle'], $this->profileGroups(), 'blockTeam');
        $values = ['columns' => self::obj(['value' => '4']), 'cardStyle' => self::obj(['value' => 'flat'])];
        $current = ['columns' => '3', 'cardStyle' => 'elevated'];

        $result = $plan->apply($values, $current);
        self::assertSame(['columns' => '4'], $result['moved'], 'a stored default counts as unset');
        self::assertSame(['cardStyle' => 'elevated'], $result['kept'], 'a non-default choice in the Design field wins');

        self::assertSame('flat', $plan->apply($values, $current, true)['keys']['cardStyle']);
    }

    public function testKeyOfReadsAColorValue(): void
    {
        $color = new class {
            public function getHex(): string
            {
                return '#1E40AF';
            }
        };

        self::assertSame('1e40af', MigrationPlan::keyOf($color));
    }
}
