<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\models\Group;

final class GroupTranslatedTest extends TestCase
{
    public function testEveryTextAnEditorReadsIsTranslated(): void
    {
        $group = Group::fromConfig('tone', [
            'label' => 'Tone',
            'section' => 'Colour & style',
            'instructions' => 'The band behind the block.',
            'auto' => 'Block default',
        ], ['light' => 'bg-white', 'dark' => ['value' => 'bg-black', 'label' => 'Night', 'icon' => 'moon']]);

        $translated = $group->translated(static fn(string $text) => "[$text]");

        self::assertSame('[Tone]', $translated->label);
        self::assertSame('[Colour & style]', $translated->section);
        self::assertSame('[The band behind the block.]', $translated->instructions);
        self::assertSame(['[Block default]', '[Light]', '[Night]'], array_column($translated->options, 'label'));
    }

    public function testKeysClassesAndTheRestStay(): void
    {
        $group = Group::fromConfig('tone', ['label' => 'Tone', 'input' => 'select', 'showIf' => ['variant' => ['band']]], [
            'light' => 'bg-white',
            'dark' => ['value' => 'bg-black', 'icon' => 'moon'],
        ])->withHidden(['dark']);

        $translated = $group->translated(static fn(string $text) => strtoupper($text));

        self::assertSame(['light', 'dark'], array_keys($translated->options));
        self::assertSame($group->options['dark']['parts'], $translated->options['dark']['parts']);
        self::assertSame('moon', $translated->options['dark']['icon']);
        self::assertSame([$group->default, $group->input, $group->showIf, $group->hidden], [$translated->default, $translated->input, $translated->showIf, $translated->hidden]);
    }

    public function testAnEmptyTextIsNotLookedUp(): void
    {
        $asked = [];
        Group::fromConfig('tone', ['label' => 'Tone'], ['light' => 'bg-white'])->translated(static function (string $text) use (&$asked) {
            $asked[] = $text;

            return $text;
        });

        self::assertEqualsCanonicalizing(['Tone', 'Light'], $asked, 'no section and no help text: nothing to translate there');
    }
}
