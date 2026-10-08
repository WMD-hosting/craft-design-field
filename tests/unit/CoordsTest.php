<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\Coords;

final class CoordsTest extends TestCase
{
    public function testParsesLatLng(): void
    {
        self::assertSame('45.901,16.852', Coords::parse(' 45.901 , 16.852 '));
        self::assertSame('-33.86,151.2', Coords::parse('-33.86,151.2'));
    }

    public function testRejectsJunkAndOutOfRange(): void
    {
        foreach (['', 'Zagreb', '45.9', '91,10', '45,181', null, 12, ['45', '16']] as $bad) {
            self::assertNull(Coords::parse($bad), var_export($bad, true));
        }
    }
}
