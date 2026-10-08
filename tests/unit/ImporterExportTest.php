<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\services\Importer;

final class ImporterExportTest extends TestCase
{
    public function testAliasesKeepTheirKeysInTheGeneratedConfig(): void
    {
        $php = (new Importer())->toPhp(['groups' => ['showDate' => ['options' => ['off' => ['label' => 'Off'], 'on' => ['label' => 'On']], 'aliases' => ['0' => 'off', '1' => 'on']]], 'profiles' => ['blockCta' => ['showDate']]], 'Test');

        self::assertStringContainsString("'0' => 'off'", $php, 'stored 0/1 map to keys: a list would hide that');
        self::assertStringContainsString("'blockCta' => [\n            'showDate',", $php, 'profiles stay plain lists');
    }
}
