<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Registry;

final class DesignValueChangesTest extends TestCase
{
    private function registry(): Registry
    {
        return Registry::fromConfig([
            'groups' => [
                'tone' => ['options' => ['light' => 'x', 'dark' => 'y'], 'default' => 'light'],
                'spacing' => ['options' => ['tight' => 'a', 'loose' => 'b'], 'auto' => 'Block default'],
            ],
            'profiles' => [
                'blockCta' => [
                    'variant' => ['options' => ['_default' => ['label' => 'Default'], 'split' => ['label' => 'Split']]],
                    'tone',
                    'spacing',
                    'columns' => ['options' => ['2' => 'a', '3' => 'b'], 'default' => '2', 'variants' => ['split']],
                ],
            ],
        ], static fn() => []);
    }

    public function testChangesListOnlyChoicesThatDifferFromTheDefault(): void
    {
        $value = $this->registry()->value(['tone' => 'dark', 'spacing' => 'auto'], 'blockCta');

        self::assertSame(['tone' => 'dark'], $value->changes());
    }

    public function testChangesSkipOptionsTheLayoutHides(): void
    {
        $hidden = $this->registry()->value(['columns' => '3'], 'blockCta');
        $shown = $this->registry()->value(['variant' => 'split', 'columns' => '3'], 'blockCta');

        self::assertSame([], $hidden->changes(), 'columns only shows for the split layout');
        self::assertSame(['variant' => 'split', 'columns' => '3'], $shown->changes());
    }
}
