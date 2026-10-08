<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\Schema;

final class SchemaTest extends TestCase
{
    private function registry(): Registry
    {
        return Registry::fromConfig([
            'groups' => [
                'tone' => ['label' => 'Section tone', 'options' => ['none' => ['label' => 'None', 'value' => 'x'], 'dark' => ['label' => 'Dark', 'value' => 'y']], 'auto' => 'Block default', 'section' => 'Colour'],
            ],
            'profiles' => [
                'blockCta' => ['variant' => ['label' => 'Layout', 'options' => ['_default' => ['label' => 'Default'], 'split' => ['label' => 'Split']]], 'tone'],
            ],
            'presets' => ['blockCta' => ['Dark band' => ['tone' => 'dark']]],
        ], static fn() => []);
    }

    public function testBuildDescribesEveryBlocksOptions(): void
    {
        $schema = Schema::build($this->registry(), ['blockCta' => 'CTA']);
        $cta = $schema['profiles']['blockCta'];

        self::assertSame('CTA', $cta['label']);
        self::assertSame(['variant', 'tone'], array_column($cta['options'], 'handle'));
        self::assertSame([
            'handle' => 'tone',
            'label' => 'Section tone',
            'look' => 'buttons',
            'default' => 'auto',
            'section' => 'Colour',
            'choices' => [['key' => 'auto', 'label' => 'Block default'], ['key' => 'none', 'label' => 'None'], ['key' => 'dark', 'label' => 'Dark']],
        ], $cta['options'][1]);
        self::assertSame([['label' => 'Dark band', 'values' => ['tone' => 'dark']]], $cta['presets']);
        self::assertStringContainsString('craft.designField.of(block)', $schema['reading']['get']);
    }

    public function testMarkdownIsACompactReference(): void
    {
        $markdown = Schema::markdown(Schema::build($this->registry(), ['blockCta' => 'CTA']));

        self::assertStringContainsString('### CTA (`blockCta`)', $markdown);
        self::assertStringContainsString('- `tone` Section tone — buttons, default `auto`: `auto`, `none`, `dark`', $markdown);
        self::assertStringContainsString('Presets: Dark band', $markdown);
        self::assertStringContainsString("{% set design = craft.designField.of(block) %}", $markdown);
    }

    public function testInjectReplacesOnlyBetweenTheMarkers(): void
    {
        $section = "## Design Field\nnew";
        $created = Schema::inject("# Agents\n\nKeep this.\n", $section);

        self::assertSame("# Agents\n\nKeep this.\n\n<!-- design-field:start -->\n## Design Field\nnew\n<!-- design-field:end -->\n", $created);

        $updated = Schema::inject(str_replace('new', 'old', $created) . "\nAfter.\n", $section);
        self::assertSame("# Agents\n\nKeep this.\n\n<!-- design-field:start -->\n## Design Field\nnew\n<!-- design-field:end -->\n\nAfter.\n", $updated);
        self::assertSame($updated, Schema::inject($updated, $section), 'running it again changes nothing');
    }

    public function testOptionsSharedByBlocksAreListedOnce(): void
    {
        $registry = Registry::fromConfig([
            'groups' => ['tone' => ['options' => ['none' => 'x', 'dark' => 'y']]],
            'profiles' => [
                'blockCta' => ['variant' => ['options' => ['_default' => 'a', 'split' => 'b']], 'tone'],
                'blockText' => ['tone'],
            ],
        ], static fn() => []);
        $markdown = Schema::markdown(Schema::build($registry));

        self::assertSame(1, substr_count($markdown, '- `tone` Tone'), 'once, under Shared options');
        self::assertStringContainsString("### Shared options", $markdown);
        self::assertStringContainsString("Shared: `tone`", $markdown);
        self::assertStringContainsString('- `variant` Variant', $markdown, 'a block\'s own options stay under the block');
    }
}
