<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\SideColumns;
use wmd\designfield\models\Group;

final class SideColumnsTest extends TestCase
{
    public function testASectionWeighsItsControlsAndItsHeading(): void
    {
        self::assertSame(0, SideColumns::weight([]), 'nothing to show');
        self::assertSame(5, SideColumns::weight([Group::INPUT_BUTTONS, Group::INPUT_TOGGLE]));
        self::assertSame(4, SideColumns::weight([Group::INPUT_BUTTONS, Group::INPUT_TOGGLE], false));
        self::assertGreaterThan(SideColumns::weight([Group::INPUT_BUTTONS]), SideColumns::weight([Group::INPUT_MOTION]));
    }

    public function testFewerThanTwoSectionsToShowKeepTheWholeRow(): void
    {
        self::assertNull(SideColumns::split([]));
        self::assertNull(SideColumns::split([7]));
        self::assertNull(SideColumns::split([0, 7, 0]));
    }

    public function testTheSplitKeepsTheOrderAndLeavesTheTallerColumnAsShortAsItCanBe(): void
    {
        self::assertSame(1, SideColumns::split([5, 5]));
        // One long list of switches: everything before it stands beside it.
        self::assertSame(3, SideColumns::split([9, 5, 5, 25, 5]));
        // The long section first: it stands alone, the short ones beside it.
        self::assertSame(1, SideColumns::split([25, 5, 5, 5]));
        self::assertSame(3, SideColumns::split([5, 5, 5, 25]));
    }

    public function testATieGivesTheLeftColumnTheExtra(): void
    {
        self::assertSame(2, SideColumns::split([5, 5, 5]));
        self::assertSame(2, SideColumns::split([3, 3, 3]));
    }

    public function testNeitherColumnIsLeftWithNothingToShow(): void
    {
        // The second section is hidden: the only split is between the first and the third.
        self::assertSame(2, SideColumns::split([5, 0, 5]));
        self::assertSame(2, SideColumns::split([0, 5, 5]));
    }
}
