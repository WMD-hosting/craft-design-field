<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Registry;

final class TileImagesTest extends TestCase
{
    private function registry(array $variant, ?callable $tileImage): Registry
    {
        return Registry::fromConfig([
            'groups' => [],
            'profiles' => [
                'blockCta' => ['variant' => $variant],
                'pageBuilder:blockText' => ['variant' => ['options' => ['_default' => ['label' => 'Default'], 'wide' => ['label' => 'Wide']]]],
            ],
            'tileImage' => $tileImage,
        ], static fn() => []);
    }

    public function testLayoutsGetTheirPicturesAndBecomeTiles(): void
    {
        $found = ['blockCta/_default' => '/design-field/blockCta/_default.jpg?v=1', 'blockCta/split' => '/design-field/blockCta/split.jpg?v=1'];
        $variant = $this->registry(['options' => ['_default' => ['label' => 'Default'], 'split' => ['label' => 'Split']]], static fn(string $type, string $key) => $found["$type/$key"] ?? null)->groupsFor('blockCta')['variant'];

        self::assertSame('/design-field/blockCta/split.jpg?v=1', $variant->options['split']['image']);
        self::assertSame('tiles', $variant->input, 'every layout has a picture and the config names no look');
    }

    public function testAConfiguredLookOrPictureWins(): void
    {
        $tile = static fn(string $type, string $key) => "/design-field/$type/$key.jpg";
        $select = $this->registry(['input' => 'select', 'options' => ['_default' => ['label' => 'Default'], 'split' => ['label' => 'Split', 'image' => '/own/split.png']]], $tile)->groupsFor('blockCta')['variant'];

        self::assertSame('select', $select->input, 'the config chose a dropdown');
        self::assertSame('/own/split.png', $select->options['split']['image'], 'a picture set in the config is kept');
        self::assertSame('/design-field/blockCta/_default.jpg', $select->options['_default']['image']);
    }

    public function testSomeLayoutsWithoutPicturesStayAsTheyAre(): void
    {
        $variant = $this->registry(['options' => ['_default' => ['label' => 'Default'], 'split' => ['label' => 'Split']]], static fn(string $type, string $key) => $key === 'split' ? '/s.jpg' : null)->groupsFor('blockCta')['variant'];

        self::assertSame('/s.jpg', $variant->options['split']['image']);
        self::assertNotSame('tiles', $variant->input, 'not every layout has a picture yet');
    }

    public function testAFieldScopedProfileLooksUpItsEntryType(): void
    {
        $asked = [];
        $this->registry(['options' => ['_default' => ['label' => 'Default']]], static function(string $type, string $key) use (&$asked) {
            $asked[] = $type;
            return null;
        });

        self::assertContains('blockText', $asked, '`pageBuilder:blockText` pictures live under blockText');
    }
}
