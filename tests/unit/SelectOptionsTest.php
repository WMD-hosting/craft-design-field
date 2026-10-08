<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\SelectOptions;

final class SelectOptionsTest extends TestCase
{
    public function testStoredValuesMissingFromOptionsAreAddedSoTheyRoundTrip(): void
    {
        $options = [['optgroup' => 'Entry'], ['label' => 'title', 'value' => 'title']];

        $result = SelectOptions::withMissing($options, ['title', 'productsBrandCategories', '', 'productsBrandCategories'], 'not available here');

        self::assertSame([
            ['optgroup' => 'Entry'],
            ['label' => 'title', 'value' => 'title'],
            ['optgroup' => 'not available here'],
            ['label' => 'productsBrandCategories', 'value' => 'productsBrandCategories'],
        ], $result);
    }

    public function testNothingMissingLeavesOptionsAlone(): void
    {
        $options = [['label' => 'a', 'value' => 'a']];

        self::assertSame($options, SelectOptions::withMissing($options, ['a'], 'x'));
    }
}
