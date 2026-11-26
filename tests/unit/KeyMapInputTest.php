<?php

declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Registry;

/**
 * A Design value is a map of option handle => choice key. A list (`["dark"]`) used
 * to pass as one and was stored as every default plus a junk `0` key; a choice that
 * is a list or a map (`{"tone": ["dark"]}`) silently became the default. Both reset
 * a block's options without an error, on every path but MCP, whose schema refuses them.
 */
final class KeyMapInputTest extends TestCase
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

    public function testAListIsNotAKeyMap(): void
    {
        foreach (['["dark"]', ['dark'], '["dark","loose"]', '[["dark"]]'] as $input) {
            $value = $this->registry()->valueFromInput($input, 'blockCta');

            self::assertNotNull($value->rejected(), var_export($input, true));
            self::assertArrayNotHasKey(0, $value->toArray(), 'no junk key is stored');
        }

        // The message quotes what was sent, also when it came as an array.
        self::assertSame('["dark"]', $this->registry()->valueFromInput('["dark"]', 'blockCta')->rejected());
        self::assertSame('["dark"]', $this->registry()->valueFromInput(['dark'], 'blockCta')->rejected());
    }

    public function testAChoiceThatIsAListOrAMapIsNotAKey(): void
    {
        foreach (['{"tone":["dark"]}', '{"tone":{"key":"dark"},"spacing":"loose"}', ['tone' => ['dark']], ['gone' => ['x' => 1]]] as $input) {
            self::assertNotNull($this->registry()->valueFromInput($input, 'blockCta')->rejected(), var_export($input, true));
        }

        self::assertSame('{"tone":["dark"]}', $this->registry()->valueFromInput(['tone' => ['dark']], 'blockCta')->rejected());
    }

    public function testEmptyAndUnsetChoicesAreStillNoChoice(): void
    {
        $value = $this->registry()->valueFromInput('{"tone":null,"spacing":"loose","gone":""}', 'blockCta');

        self::assertNull($value->rejected());
        self::assertSame(['tone' => 'light', 'spacing' => 'loose'], $value->keys());

        // An empty list is how JSON and a form spell "nothing chosen yet".
        self::assertNull($this->registry()->valueFromInput('[]', 'blockCta')->rejected());
        self::assertNull($this->registry()->valueFromInput([], 'blockCta')->rejected());
    }

    public function testWhatTheEditorPostsIsStillAKeyMap(): void
    {
        $registry = $this->registry();

        // A slider's places on their scale come as a map of their own, taken out first.
        $value = $registry->valueFromInput(['tone' => 'dark', Registry::SLIDER_KEY => ['spacing' => '1']], 'blockCta');
        self::assertNull($value->rejected());
        self::assertSame(['tone' => 'dark', 'spacing' => 'loose'], $value->keys());

        self::assertNull($registry->valueFromInput([Registry::SLIDER_KEY => ['spacing' => '1']], 'blockCta')->rejected());

        // Numbers and booleans are keys or switches, checked by invalid() as before.
        $value = $registry->valueFromInput(['tone' => 1.5, 'spacing' => true], 'blockCta');
        self::assertNull($value->rejected());
        self::assertSame(['tone' => '1.5', 'spacing' => '1'], $value->invalid());
    }
}
