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
}
