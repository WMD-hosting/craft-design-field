<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\TidyRows;

final class TidyRowsTest extends TestCase
{
    private function field(string $type, string $handle, ?string $planned, array $extra = []): array
    {
        return $extra + ['type' => $type, 'typeName' => ucfirst(substr($type, 5)), 'field' => $handle, 'name' => $handle, 'kind' => 'Dropdown', 'planned' => $planned];
    }

    public function testRowsSayWhatMoveWouldDo(): void
    {
        $rows = TidyRows::build(
            [
                $this->field('blockCta', 'accentToken', 'tone'),
                $this->field('blockCta', 'blockCtaVariant', 'variant'),
                $this->field('blockCta', 'showDate', null),
                $this->field('blockAlert', 'icon', null),
            ],
            ['fieldMap' => ['icon' => 'alertIcon'], 'skipped' => []],
            ['blockCta'],
        );

        self::assertSame(['blockAlert', 'blockCta'], array_column($rows, 'type'), 'sorted by block name');
        $cta = $rows[1];
        self::assertSame([['tone', 'moves'], ['variant', 'moves'], ['showDate', 'new']], array_map(static fn($f) => [$f['group'], $f['status']], $cta['fields']), 'the block\'s own layout list moves into variant');
        self::assertTrue($cta['movable']);
        self::assertSame('', $cta['reason']);
        self::assertSame('alertIcon', $rows[0]['fields'][0]['group'], 'a suggested group from the fieldMap');
        self::assertSame([false, 'no-profile'], [$rows[0]['movable'], $rows[0]['reason']]);
    }

    public function testAClashBlocksMoveWithItsReason(): void
    {
        $rows = TidyRows::build(
            [$this->field('blockAlert', 'accentToken', 'tone'), $this->field('blockAlert', 'tone', 'tone')],
            ['fieldMap' => ['accentToken' => 'tone'], 'skipped' => ['tone' => 'group "tone" is already configured for something else']],
            ['blockAlert'],
        );

        self::assertSame(['moves', 'clash'], array_column($rows[0]['fields'], 'status'));
        self::assertStringContainsString('already configured', $rows[0]['fields'][1]['note']);
        self::assertSame([false, 'clash'], [$rows[0]['movable'], $rows[0]['reason']], 'adopt would move the status field into tone: refuse');
    }

    public function testNothingPlannedIsNotMovable(): void
    {
        $rows = TidyRows::build([$this->field('blockHeading', 'headingLevel', null)], ['fieldMap' => [], 'skipped' => []], ['blockHeading']);

        self::assertSame([false, 'nothing'], [$rows[0]['movable'], $rows[0]['reason']], 'a profile, but adopt would move nothing');
    }

    public function testRowsCarryWhetherTemplatesReadTheField(): void
    {
        $rows = TidyRows::build(
            [$this->field('blockEntryList', 'showDate', null, ['read' => false]), $this->field('blockEntryList', 'orderBy', null)],
            ['fieldMap' => [], 'skipped' => []],
            [],
        );

        self::assertSame([false, true], array_column($rows[0]['fields'], 'read'), 'unknown counts as read: no false alarm');
    }
}
