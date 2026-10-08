<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\Row;
use wmd\designfield\entryfields\RowMarkup;

final class RowMarkupTest extends TestCase
{
    private const STYLES = [
        'default' => ['label' => 'Default', 'value' => ''],
        'muted' => ['label' => 'Muted', 'value' => 'text-sm text-muted'],
        'bold' => 'font-semibold',
    ];

    private function row(array $extra = []): Row
    {
        return Row::fromArray(['field' => 'trajanje', 'slot' => 'below-title'] + $extra);
    }

    public function testWrapsWithTagStyleLabelAndTextAfter(): void
    {
        $html = RowMarkup::wrap($this->row(['tag' => 'span', 'style' => 'muted']), '7', 'Duration:', 'days', self::STYLES);

        self::assertSame('<span class="entry-field text-sm text-muted"><span class="entry-field-label">Duration:</span> 7 <span class="entry-field-after">days</span></span>', $html);
    }

    public function testStringTokenAndNoLabel(): void
    {
        $html = RowMarkup::wrap($this->row(['style' => 'bold']), '7', '', '', self::STYLES);

        self::assertSame('<p class="entry-field font-semibold">7</p>', $html);
    }

    public function testEmptyValueRendersNothingEvenWithLabel(): void
    {
        self::assertSame('', RowMarkup::wrap($this->row(), '', 'Duration:', 'days', self::STYLES));
        self::assertSame('', RowMarkup::wrap($this->row(), "  <p> </p>\n", 'Duration:', '', self::STYLES));
    }

    public function testImageOnlyValueIsNotEmpty(): void
    {
        self::assertNotSame('', RowMarkup::wrap($this->row(), '<img src="/a.jpg" alt="">', '', '', self::STYLES));
    }

    public function testUnknownStyleUsesNoClass(): void
    {
        self::assertSame('<p class="entry-field">7</p>', RowMarkup::wrap($this->row(['style' => 'ghost']), '7', '', '', self::STYLES));
    }

    public function testLabelIsInsertedAsGiven(): void
    {
        // Purifying is the renderer's job; RowMarkup must not double-escape safe HTML.
        $html = RowMarkup::wrap($this->row(), '7', '<i class="fa-light fa-clock"></i> Duration', '', self::STYLES);

        self::assertStringContainsString('<i class="fa-light fa-clock"></i> Duration', $html);
    }

    public function testBlockLevelValueUsesDivWrapper(): void
    {
        // A <p> cannot hold the <ul>/<div> that files and pageBuilder render.
        $html = RowMarkup::wrap($this->row(['tag' => 'p']), '<ul><li>a.pdf</li></ul>', '', '', self::STYLES);

        self::assertStringStartsWith('<div class="entry-field">', $html);
    }

    public function testIconOnlyValueIsNotEmpty(): void
    {
        self::assertFalse(RowMarkup::isEmpty('<i class="fa-solid fa-star" aria-hidden="true"></i>'));
        self::assertTrue(RowMarkup::isEmpty('<i></i> '), 'an <i> without a class is still empty');
    }

    public function testFormInputsAreContent(): void
    {
        // A single-variant picker is only a hidden purchasableId input; dropping it breaks the cart.
        self::assertFalse(RowMarkup::isEmpty('<input type="hidden" name="purchasableId" value="12">'));
    }
}
