<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\RenderStack;

final class RenderStackTest extends TestCase
{
    public function testSameKeyCannotReenter(): void
    {
        $stack = new RenderStack();

        self::assertTrue($stack->enter('block:12'));
        self::assertFalse($stack->enter('block:12'), 'a block showing its own page builder must not render itself again');
        $stack->leave('block:12');
        self::assertTrue($stack->enter('block:12'));
    }

    public function testDepthIsCapped(): void
    {
        $stack = new RenderStack(2);

        self::assertTrue($stack->enter('a'));
        self::assertTrue($stack->enter('b'));
        self::assertFalse($stack->enter('c'));
        $stack->leave('b');
        self::assertTrue($stack->enter('c'));
    }

    public function testLeavingAnUnknownKeyIsHarmless(): void
    {
        $stack = new RenderStack();
        $stack->leave('nope');

        self::assertTrue($stack->enter('nope'));
    }
}
