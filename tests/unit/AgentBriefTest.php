<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\AgentBrief;

final class AgentBriefTest extends TestCase
{
    private function types(): array
    {
        return [['type' => 'blockCta', 'typeName' => 'CTA', 'movable' => true, 'fields' => [
            ['field' => 'accentToken', 'name' => 'Accent', 'kind' => 'Dropdown', 'group' => 'tone', 'status' => 'exists', 'note' => ''],
        ]]];
    }

    public function testTheBriefListsFieldsGroupsAndHowToRead(): void
    {
        $brief = AgentBrief::build($this->types(), ['reading' => ['get' => '{% set design = craft.designField.of(block) %}', 'key' => '{{ design.tone.key }}'], 'profiles' => []], [['kind' => 'missing', 'profile' => 'blockCta', 'group' => 'columns', 'file' => '_blocks/blockCta/split.twig']], 'twig');

        self::assertStringContainsString('`accentToken` (Dropdown) → group `tone`', $brief);
        self::assertStringContainsString('craft.designField.of(block)', $brief);
        self::assertStringContainsString('_blocks/blockCta/split.twig reads `columns`', $brief);
        self::assertStringContainsString('craft design-field/adopt --types=blockCta', $brief);
        self::assertStringContainsString('docs/design-field-schema.md', $brief);
    }

    public function testHeadlessBriefExplainsTheGraphqlKeys(): void
    {
        $brief = AgentBrief::build($this->types(), ['reading' => ['get' => 'x', 'key' => 'y'], 'profiles' => []], [], 'graphql');

        self::assertStringContainsString('GraphQL', $brief);
        self::assertStringContainsString('JSON', $brief);
        self::assertStringNotContainsString('craft.designField.of(block)', $brief);
    }
}
