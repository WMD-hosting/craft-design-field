<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\services\EntryFieldsRenderer;

final class EntryFieldsRendererConfigTest extends TestCase
{
    public function testPurifierAllowsIconsAndInlineTextOnly(): void
    {
        $allowed = EntryFieldsRenderer::PURIFIER_CONFIG['HTML.Allowed'];

        self::assertStringContainsString('i[class]', $allowed);
        self::assertStringContainsString('a[href|class|target]', $allowed);
        foreach (['script', 'style', 'iframe', 'onerror', 'onclick'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $allowed);
        }
    }
}
