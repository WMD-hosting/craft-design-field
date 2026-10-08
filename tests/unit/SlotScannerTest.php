<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\SlotScanner;

final class SlotScannerTest extends TestCase
{
    public function testFindsSlotsInOrderOnce(): void
    {
        $twig = <<<'TWIG'
            {{ craft.entryFields.render(block, entry, 'above-title') }}
            <h1>{{ entry.title }}</h1>
            {{ craft.entryFields.render( block , entry , "below-title" ) }}
            {{ craft.entryFields.render(block, entry, 'above-title') }}
            {# craft.designField.of(block) is not a slot #}
            TWIG;

        self::assertSame(['above-title', 'below-title'], SlotScanner::scan($twig));
    }

    public function testNoCalls(): void
    {
        self::assertSame([], SlotScanner::scan('<p>{{ entry.title }}</p>'));
    }
}
