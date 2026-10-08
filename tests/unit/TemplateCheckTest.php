<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\TemplateCheck;

final class TemplateCheckTest extends TestCase
{
    public function testReadsEveryWayATemplateReadsAnOption(): void
    {
        $twig = <<<'TWIG'
{% set design = craft.designField.of(block) %}
{% set cardDesign = craft.designField.of(card) %}
<div class="{{ design.tone.get('bg') }} {{ design.classes('spacing', 'container') }}">
{% if design.has('columns') %}{{ cardDesign.get('imageShape') }}{% endif %}
{% set align = craft.designField.of(el).alignment.keyOr('left') %}
{{ block.design.motion.key }} {{ design['aspectRatio'] }}
{% set other = craft.designField.of(hb, 'blockHeading').headingSize %}
TWIG;

        self::assertSame(
            ['aspectRatio', 'columns', 'container', 'motion', 'spacing', 'tone'],
            TemplateCheck::reads($twig),
            'token methods (get, keyOr) are not options; another profile\'s read is not this block\'s',
        );
        self::assertSame(['alignment', 'imageShape'], TemplateCheck::otherReads($twig), 'of(el), of(card): nested elements, not this block');
        self::assertSame(['blockHeading' => ['headingSize']], TemplateCheck::namedReads($twig), 'of(hb, \'blockHeading\'): read for that profile');
        self::assertSame(['tableSearch'], TemplateCheck::reads("{{ craft.designField.of(block, 'blockTable').tableSearch.key }}"), 'of(block, profile) is still this block');
    }

    public function testReadsOnNestedElementsKeepOptionsAliveEverywhere(): void
    {
        $files = [
            '_blocks/blockHeader/_default.twig' => "{% import '_partials/_bucket' as b %}",
            '_partials/_bucket.twig' => "{% for el in els %}{{ craft.designField.of(el).headerElementAlign }}{{ el.design.headerElementDevice }}{% endfor %}",
            '_blocks/headerLogo/_default.twig' => 'logo',
        ];
        $findings = TemplateCheck::analyze($files, [
            'blockHeader' => ['groups' => [], 'layouts' => []],
            'headerLogo' => ['groups' => ['headerElementAlign', 'headerElementDevice'], 'layouts' => []],
        ], ['blocks' => '_blocks/{type}', 'everyBlock' => []]);

        self::assertSame([], $findings, 'the parent reads them for its elements: neither dead nor missing');
    }

    public function testIncludesAreFollowedAndDynamicOnesCounted(): void
    {
        $twig = <<<'TWIG'
{% import '_partials/_section-frame.twig' as frame %}
{% include '_atoms/picture' with { image: image } only %}
{% embed "_molecules/card" %}{% endembed %}
{{ include('_atoms/icon', { name: 'x' }) }}
{% include '_blocks/' ~ handle ~ '/' ~ variant only %}
{% from '_macros/ui' import button %}
TWIG;

        self::assertSame(['_atoms/icon', '_atoms/picture', '_macros/ui', '_molecules/card', '_partials/_section-frame.twig'], TemplateCheck::includes($twig));
        self::assertSame(1, TemplateCheck::dynamicIncludes($twig));
    }

    private function files(): array
    {
        return [
            '_blocks/_render.twig' => "{% set _design = craft.designField.of(block) %}{{ block.design.has('variant') }}{{ _design.motion.key }}{% include '_blocks/' ~ handle ~ '/' ~ variant %}",
            '_partials/_frame.twig' => "{% macro section(block) %}{% set design = craft.designField.of(block) %}{{ design.tone }}{{ design.spacing }}{% endmacro %}",
            '_blocks/blockCta/_default.twig' => "{% import '_partials/_frame' as frame %}{% set design = craft.designField.of(block) %}{{ design.imageShape }}",
            '_blocks/blockCta/split.twig' => "{% set design = craft.designField.of(block) %}{{ design.columns }}",
            '_blocks/blockCta/old-layout.twig' => '',
            '_blocks/blockCta/_parts.twig' => '',
        ];
    }

    public function testFindsDeadMissingAndLayoutFileProblems(): void
    {
        $findings = TemplateCheck::analyze($this->files(), [
            'blockCta' => ['groups' => ['variant', 'tone', 'spacing', 'motion', 'imageShape', 'cardStyle'], 'layouts' => ['_default', 'split', 'cards']],
            'blockGone' => ['groups' => ['tone'], 'layouts' => []],
        ], ['blocks' => '_blocks/{type}', 'everyBlock' => ['_blocks/_render']]);

        $by = static fn(string $kind) => array_values(array_filter($findings, static fn(array $f) => $f['kind'] === $kind));

        self::assertSame([['profile' => 'blockCta', 'group' => 'cardStyle']], array_map(static fn($f) => ['profile' => $f['profile'], 'group' => $f['group']], $by('dead')), 'variant, motion (dispatcher) and tone, spacing (imported partial) are read');
        self::assertSame([['profile' => 'blockCta', 'group' => 'columns', 'file' => '_blocks/blockCta/split.twig']], array_map(static fn($f) => ['profile' => $f['profile'], 'group' => $f['group'], 'file' => $f['file']], $by('missing')));
        self::assertSame(['cards'], array_column($by('layout-without-file'), 'layout'));
        self::assertSame(['old-layout'], array_column($by('file-without-layout'), 'layout'), '_parts.twig is a partial, not a layout');
        self::assertSame(['blockGone'], array_column($by('no-templates'), 'profile'));
    }

    public function testCodeReadsCount(): void
    {
        $php = <<<'PHP'
$autoplay = $design?->get('autoplay')->key === 'on';
$speed = $key('speed', 400);
$label = 'Something else entirely';
PHP;
        self::assertSame(['autoplay', 'speed'], TemplateCheck::codeReads($php, ['autoplay', 'speed', 'cardStyle']));

        $findings = TemplateCheck::analyze($this->files(), [
            'blockCta' => ['groups' => ['variant', 'tone', 'spacing', 'motion', 'imageShape', 'cardStyle'], 'layouts' => []],
        ], ['blocks' => '_blocks/{type}', 'everyBlock' => ['_blocks/_render'], 'readInCode' => ['cardStyle']]);

        self::assertSame([], array_values(array_filter($findings, static fn(array $f) => $f['kind'] === 'dead')), 'read in a Twig extension, so not dead');
    }

    public function testANamedProfileReadCountsForThatProfile(): void
    {
        $files = [
            '_blocks/blockPageHeader/_default.twig' => "{{ craft.designField.of(firstHb, 'blockHeading').headingAlignment }}",
            '_blocks/blockHeading/_default.twig' => 'heading',
        ];
        $findings = TemplateCheck::analyze($files, [
            'blockPageHeader' => ['groups' => [], 'layouts' => []],
            'blockHeading' => ['groups' => ['headingAlignment', 'headingSize'], 'layouts' => []],
        ], ['blocks' => '_blocks/{type}', 'everyBlock' => []]);

        self::assertSame([['kind' => 'dead', 'profile' => 'blockHeading', 'group' => 'headingSize']], $findings);
    }

    public function testFieldReadsFindPlainFieldReadsButNotQueryMethods(): void
    {
        $twig = <<<'TWIG'
{% set fl = block.fieldLayout %}
{% if fl.getFieldByHandle('tabsSource') and block.tabsSource %}{% endif %}
{{ block['triggerType'].value }} {{ element.drawerMenuMode.value }}
{% set list = craft.entries.section('news').orderBy('postDate desc').all() %}
{# showDate is mentioned in a comment only #}
TWIG;

        self::assertSame(
            ['drawerMenuMode', 'tabsSource', 'triggerType'],
            TemplateCheck::fieldReads($twig, ['showDate', 'orderBy', 'tabsSource', 'triggerType', 'drawerMenuMode']),
            '.orderBy( is a query method, a comment is not a read',
        );
    }

    public function testBlockFilesAreItsTemplatesAndWhatTheyInclude(): void
    {
        $files = [
            '_blocks/blockCta/_default.twig' => "{% include '_partials/_card' with {} only %}",
            '_blocks/blockCta/split.twig' => 'x',
            '_partials/_card.twig' => "{% include '_atoms/icon' %}",
            '_atoms/icon.twig' => 'icon',
            '_blocks/blockCtaWide/_default.twig' => 'not mine',
        ];

        self::assertSame(['_atoms/icon.twig', '_blocks/blockCta/_default.twig', '_blocks/blockCta/split.twig', '_partials/_card.twig'], TemplateCheck::blockFiles($files, '_blocks/blockCta'));
        self::assertSame([], TemplateCheck::blockFiles($files, '_blocks/blockGone'));
    }
}
