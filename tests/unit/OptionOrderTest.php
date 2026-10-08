<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\OptionOrder;

final class OptionOrderTest extends TestCase
{
    private function keys(int $n): array
    {
        return array_merge(['auto'], array_map(static fn(int $i) => "k$i", range(1, $n - 1)));
    }

    public function testLongListsPutTheMostUsedFirst(): void
    {
        $split = OptionOrder::split($this->keys(12), ['auto' => 40, 'k7' => 9, 'k2' => 5, 'k9' => 5, 'k4' => 1, 'k3' => 0]);

        self::assertSame(['k7', 'k2', 'k9'], $split['top'], 'by use, ties in list order; Block default never counts');
        self::assertSame(['auto', 'k1', 'k3', 'k4', 'k5', 'k6', 'k8', 'k10', 'k11'], $split['rest'], 'the others keep their order, nothing twice');
    }

    public function testShortListsAndUnusedChoicesStayAsTheyAre(): void
    {
        self::assertSame([], OptionOrder::split($this->keys(6), ['k1' => 10])['top'], 'short lists keep their order');
        self::assertSame(['k1'], OptionOrder::split($this->keys(12), ['k1' => 3, 'k2' => 0])['top'], 'only choices someone picked');
        self::assertSame([], OptionOrder::split($this->keys(12), [])['top'], 'no counts yet: the usual order');
    }

    public function testCountsComeFromTheBlockTypeElseTheWholeSite(): void
    {
        $all = [
            'blockCta' => ['transition' => ['fade' => 2], 'tone' => []],
            'blockHero' => ['transition' => ['slide' => 7], 'tone' => ['dark' => 4]],
            'blockTeam' => ['tone' => ['dark' => 1, 'light' => 3]],
        ];

        self::assertSame(['fade' => 2], OptionOrder::countsFor($all, 'blockCta')['transition'], 'the block type\'s own picks first');
        self::assertSame(['dark' => 5, 'light' => 3], OptionOrder::countsFor($all, 'blockCta')['tone'], 'none on this type: the whole site');
        self::assertSame(['fade' => 2, 'slide' => 7], OptionOrder::countsFor($all, null)['transition'], 'no type: the whole site');
        self::assertSame([], OptionOrder::countsFor([], 'blockCta'), 'nothing counted yet');
    }
}
