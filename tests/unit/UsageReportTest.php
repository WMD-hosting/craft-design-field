<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\UsageReport;
use wmd\designfield\models\Group;

final class UsageReportTest extends TestCase
{
    private function sampleGroups(): array
    {
        return [
            'tone' => Group::fromConfig('tone', ['auto' => 'Block default'], ['primary' => 'bg-primary', 'surface' => 'bg-surface']),
            'imageShape' => Group::fromConfig('imageShape', ['auto' => 'Auto'], ['round' => ['label' => 'Round'], 'square' => ['label' => 'Square']]),
            'variant' => Group::fromConfig('variant', [], ['_default' => ['label' => 'Grid'], 'cards' => ['label' => 'Cards'], 'list' => ['label' => 'List']]),
        ];
    }

    public function testVerdictsAndCounts(): void
    {
        $report = UsageReport::analyze($this->sampleGroups(), [
            ['tone' => 'auto', 'imageShape' => 'square', 'variant' => '_default'],
            ['tone' => 'auto', 'imageShape' => 'square', 'variant' => 'cards'],
            ['tone' => 'auto', 'imageShape' => 'square', 'variant' => '_default'],
        ]);

        self::assertSame(UsageReport::NEVER_CHANGED, $report['tone']['verdict']);
        self::assertSame(['primary', 'surface'], $report['tone']['unused']);

        self::assertSame(UsageReport::ONE_VALUE, $report['imageShape']['verdict']);
        self::assertSame('square', $report['imageShape']['value']);
        self::assertSame(3, $report['imageShape']['changed']);

        self::assertSame(UsageReport::USED, $report['variant']['verdict']);
        self::assertSame(['_default' => 2, 'cards' => 1], $report['variant']['counts']);
        self::assertSame(['list'], $report['variant']['unused'], 'a layout nobody picked');
        self::assertSame(1, $report['variant']['changed']);
    }

    public function testGroupsNoEntryHasAreLeftOut(): void
    {
        $report = UsageReport::analyze($this->sampleGroups(), [['tone' => 'primary']]);

        self::assertSame(['tone'], array_keys($report));
        self::assertSame(UsageReport::ONE_VALUE, $report['tone']['verdict']);
        self::assertSame([], UsageReport::analyze($this->sampleGroups(), []));
    }

    public function testSummaryForConsoleAndSettingsPage(): void
    {
        $groups = $this->sampleGroups();
        $report = [
            'blockA' => ['entries' => 2, 'groups' => UsageReport::analyze($groups, [
                ['tone' => 'auto', 'imageShape' => 'square', 'variant' => '_default'],
                ['tone' => 'auto', 'imageShape' => 'square', 'variant' => '_default'],
            ])],
            'blockB' => ['entries' => 2, 'groups' => UsageReport::analyze($groups, [
                ['tone' => 'auto', 'variant' => '_default'],
                ['tone' => 'auto', 'variant' => 'cards'],
            ])],
        ];
        $summary = UsageReport::summarize($report);

        self::assertSame(['tone', 'imageShape', 'variant'], array_column($summary['types']['blockA']['findings'], 'handle'));
        self::assertSame('only the default layout is used', $summary['types']['blockA']['findings'][2]['text']);
        self::assertSame(['cards', 'list'], $summary['types']['blockA']['findings'][2]['unused'], 'unpicked layouts are listed');
        self::assertSame(['tone'], array_column($summary['types']['blockB']['findings'], 'handle'), 'used groups are left out by default');
        self::assertSame([['label' => 'Tone', 'types' => 2, 'entries' => 4]], $summary['nowhere'], 'site-wide: never changed in any block');
        self::assertSame(3, $summary['neverChanged']);
        self::assertSame(1, $summary['oneValue']);

        $shape = $summary['types']['blockA']['findings'][1];
        self::assertSame(['square' => 2], $shape['counts'], 'the page shows a count on every choice');
        self::assertSame(['square', 'square'], [$shape['value'], array_key_first($shape['counts'])]);
        self::assertSame('auto', $summary['types']['blockA']['findings'][0]['default']);

        $all = UsageReport::summarize($report, true);
        self::assertSame('used: _default 1, cards 1', $all['types']['blockB']['findings'][1]['text']);
    }
}
