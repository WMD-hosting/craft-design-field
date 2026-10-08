<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\models\Group;

final class RenderedTilesTest extends TestCase
{
    public function testTokenValuesBecomeTheTileClassesOnTopOfTheBase(): void
    {
        $radius = Group::fromConfig('cornerRadius', ['preview' => true, 'previewBase' => 'bg-primary/20', 'auto' => 'Block default'], [
            'none' => ['label' => 'None', 'value' => 'rounded-none'],
            'lg' => ['label' => 'Large', 'value' => 'rounded-2xl'],
        ]);

        self::assertSame('bg-primary/20 rounded-none', $radius->options['none']['preview']);
        self::assertSame('bg-primary/20 rounded-2xl', $radius->options['lg']['preview']);
        self::assertNull($radius->options['auto']['preview'], 'auto has no look of its own');
        self::assertContains('rendered', $radius->supportedInputs());
    }

    public function testOptionsCanNameTheirOwnClasses(): void
    {
        $card = Group::fromConfig('cardStyle', ['optionMeta' => [
            'flat' => ['preview' => 'bg-surface'],
            'bordered' => ['preview' => 'border border-border bg-bg'],
        ]], ['flat' => 'a', 'bordered' => 'b']);

        self::assertSame('border border-border bg-bg', $card->options['bordered']['preview']);
        self::assertSame(['value' => 'b'], $card->options['bordered']['parts'], 'preview is meta, not a class part');
        self::assertContains('rendered', $card->supportedInputs());
    }

    public function testOfferedOnlyWhenEveryChoiceHasALook(): void
    {
        $some = Group::fromConfig('cardStyle', ['optionMeta' => ['flat' => ['preview' => 'bg-surface']]], ['flat' => 'a', 'bordered' => 'b']);
        $plain = Group::fromConfig('spacing', [], ['sm' => 'py-8', 'lg' => 'py-24']);

        self::assertNotContains('rendered', $some->supportedInputs());
        self::assertNotContains('rendered', $plain->supportedInputs(), 'class values alone are not a preview');
    }
}
