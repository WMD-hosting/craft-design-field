<?php

declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Registry;

/**
 * What the field makes of each kind of input. A non-JSON string used to turn
 * into an empty key map, so an MCP agent that sent `"dark"` reset every option
 * of the block to its default without an error.
 */
final class ValueFromInputTest extends TestCase
{
    private function registry(): Registry
    {
        return Registry::fromConfig([
            'groups' => [
                'tone' => ['options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
                'spacing' => ['options' => ['tight' => 'py-4', 'loose' => 'py-16'], 'default' => 'tight'],
            ],
            'profiles' => ['blockCta' => ['tone', 'spacing']],
        ], static fn() => []);
    }

    public function testJsonAndArrayInputGiveTheSameKeys(): void
    {
        $fromJson = $this->registry()->valueFromInput('{"tone":"dark"}', 'blockCta');
        $fromArray = $this->registry()->valueFromInput(['tone' => 'dark'], 'blockCta');

        self::assertSame(['tone' => 'dark', 'spacing' => 'tight'], $fromJson->keys());
        self::assertSame($fromJson->keys(), $fromArray->keys());
        self::assertNull($fromJson->rejected());
    }

    public function testEmptyInputIsNoChoicesYet(): void
    {
        foreach ([null, '', '[]', 'null'] as $input) {
            $value = $this->registry()->valueFromInput($input, 'blockCta');

            self::assertNull($value->rejected(), var_export($input, true));
            self::assertSame(['tone' => 'light', 'spacing' => 'tight'], $value->keys());
        }
    }

    public function testInputThatIsNotAKeyMapIsRejected(): void
    {
        self::assertSame('dark', $this->registry()->valueFromInput('dark', 'blockCta')->rejected());
        self::assertSame('"dark"', $this->registry()->valueFromInput('"dark"', 'blockCta')->rejected());
        self::assertSame('42', $this->registry()->valueFromInput(42, 'blockCta')->rejected());
        self::assertSame('true', $this->registry()->valueFromInput(true, 'blockCta')->rejected());
    }

    public function testRejectedValueStillRendersDefaults(): void
    {
        $value = $this->registry()->valueFromInput('dark', 'blockCta');

        self::assertSame('bg-white py-4', (string)$value);
        self::assertSame([], $value->invalid());
    }

    public function testAStoredZeroOutsideTheProfileSurvivesASave(): void
    {
        // `gap` belongs to another block's profile: its key is kept for when the block
        // gets the option back, and `0` is a key like any other.
        $value = $this->registry()->valueFromInput(['tone' => 'dark', 'gap' => '0', 'old' => ''], 'blockCta');

        self::assertSame(['tone' => 'dark', 'gap' => '0', 'spacing' => 'tight'], $value->toArray());
    }

    public function testWithoutAConfigurationAValueKeepsWhatIsStored(): void
    {
        $value = Registry::standIn()->valueFromInput('{"tone":"dark","gap":"0","columns":3}', ['pageBuilder:blockCta', 'blockCta']);

        self::assertSame(['tone' => 'dark', 'gap' => '0', 'columns' => '3'], $value->toArray());
        self::assertSame($value->toArray(), $value->keys(), 'an API reads the stored map, not an empty one');
        self::assertSame('', (string)$value);
        self::assertSame('', $value->tone->key);
        self::assertSame([], $value->groups());
        self::assertSame([], $value->changes());
        self::assertSame([], $value->invalid());
        self::assertNull($value->rejected());

        // What is no map is still refused, so a save cannot replace the map with it.
        self::assertSame('dark', Registry::standIn()->valueFromInput('dark', 'blockCta')->rejected());
    }

    public function testASliderPostedAsItsPlaceOnTheScaleBecomesItsKey(): void
    {
        $registry = $this->registry();

        $value = $registry->valueFromInput(['tone' => 'dark', Registry::SLIDER_KEY => ['spacing' => '1']], 'blockCta');
        self::assertSame(['tone' => 'dark', 'spacing' => 'loose'], $value->toArray());
        self::assertSame([], $value->invalid());

        // Off the scale, not a number, or an option the profile lacks: left as it was.
        self::assertSame('tight', $registry->valueFromInput([Registry::SLIDER_KEY => ['spacing' => '5']], 'blockCta')->toArray()['spacing']);
        self::assertSame('tight', $registry->valueFromInput([Registry::SLIDER_KEY => ['spacing' => 'x']], 'blockCta')->toArray()['spacing']);
        self::assertSame([], $registry->valueFromInput([Registry::SLIDER_KEY => ['gone' => '1']], 'blockCta')->invalid());
    }
}
