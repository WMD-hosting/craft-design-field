<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\web\OptionLabels;

final class OptionLabelsTest extends TestCase
{
    public function testAWidthReadsAsAPercent(): void
    {
        self::assertSame(75.0, OptionLabels::percent('75'));
        self::assertSame(75.0, OptionLabels::percent('75%'));
        self::assertSame(75.0, OptionLabels::percent(' 0.75 '));
        self::assertSame(100.0, OptionLabels::percent('140'));
        self::assertSame(1.0, OptionLabels::percent('1'), 'a whole 1 is one percent, not all of it');
        self::assertNull(OptionLabels::percent('wide'));
        self::assertNull(OptionLabels::percent(null));
    }

    public function testOnlyPlainCssValuesPass(): void
    {
        self::assertSame('#4f46e5', OptionLabels::safeCss(' #4f46e5 '));
        self::assertSame('color-mix(in srgb, #4f46e5 12%, white)', OptionLabels::safeCss('color-mix(in srgb, #4f46e5 12%, white)'));
        self::assertNull(OptionLabels::safeCss('red;background:url(x)'));
        self::assertNull(OptionLabels::safeCss('red" onmouseover="x'));
        self::assertNull(OptionLabels::safeCss(null));
        self::assertNull(OptionLabels::safeCss('url(//tracker.example/p.png)'), 'nothing that loads a file');
        self::assertNull(OptionLabels::safeCss('image-set(x.png 1x)'));
        self::assertSame('calc(1rem + 2px)', OptionLabels::safeCss('calc(1rem + 2px)'));
    }

    public function testInlineSvgIsToldFromAnIconName(): void
    {
        self::assertTrue(OptionLabels::isSvg(' <svg viewBox="0 0 20 20"></svg>'));
        self::assertFalse(OptionLabels::isSvg('align-left'));
        self::assertFalse(OptionLabels::isSvg(null));
    }
}
