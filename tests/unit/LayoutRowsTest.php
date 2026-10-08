<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\entryfields\LayoutRows;
use wmd\designfield\entryfields\Row;

final class LayoutRowsTest extends TestCase
{
    private function vijesti(): array
    {
        return [
            ['kind' => 'title', 'handle' => 'title', 'class' => ''],
            ['kind' => 'field', 'handle' => 'image', 'class' => 'craft\fields\Assets'],
            ['kind' => 'field', 'handle' => 'vijestiCategories', 'class' => 'craft\fields\Entries'],
            ['kind' => 'field', 'handle' => 'eyebrow', 'class' => 'craft\fields\PlainText'],
            ['kind' => 'field', 'handle' => 'subheading', 'class' => 'craft\fields\PlainText'],
            ['kind' => 'field', 'handle' => 'body', 'class' => 'craft\ckeditor\Field'],
            ['kind' => 'field', 'handle' => 'pageBuilder', 'class' => 'craft\fields\Matrix', 'matrix' => 'pageBuilder'],
            ['kind' => 'field', 'handle' => 'seo', 'class' => 'nystudio107\seomatic\fields\SeoSettings'],
        ];
    }

    public function testLayoutOrderBecomesRows(): void
    {
        $rows = LayoutRows::defaults($this->vijesti());

        self::assertSame(
            ['title', 'postDate', 'image', 'vijestiCategories', 'eyebrow', 'subheading', 'body', 'pageBuilder'],
            array_map(static fn(Row $r) => $r->field, $rows),
            'title first, a meta date line under it, unsupported SEO left out',
        );
        self::assertSame(['content'], array_values(array_unique(array_map(static fn(Row $r) => $r->slot, $rows))));
    }

    public function testDefaultFormatsAndStyles(): void
    {
        $by = [];
        foreach (LayoutRows::defaults($this->vijesti()) as $row) {
            $by[$row->field] = $row;
        }

        self::assertSame(['h1', 'title'], [$by['title']->tag, $by['title']->style]);
        self::assertSame(['date', 'meta'], [$by['postDate']->format, $by['postDate']->style]);
        self::assertSame('cover', $by['image']->format);
        self::assertSame('chips', $by['vijestiCategories']->format);
        self::assertSame('eyebrow', $by['eyebrow']->style);
        self::assertSame('lead', $by['subheading']->style);
        self::assertSame('rich', $by['body']->format);
        self::assertSame('pageBuilder', $by['pageBuilder']->format);
    }

    public function testOnlyFirstAssetsBecomesCover(): void
    {
        $rows = LayoutRows::defaults([
            ['kind' => 'field', 'handle' => 'image', 'class' => 'craft\fields\Assets'],
            ['kind' => 'field', 'handle' => 'images', 'class' => 'craft\fields\Assets'],
        ]);

        self::assertSame(['cover', 'auto'], array_map(static fn(Row $r) => $r->format, $rows));
    }

    public function testNoTitleNoMetaAndEmptyLayout(): void
    {
        self::assertSame([], LayoutRows::defaults([]));
        self::assertSame(['body'], array_map(static fn(Row $r) => $r->field, LayoutRows::defaults([['kind' => 'field', 'handle' => 'body', 'class' => 'craft\ckeditor\Field']])));
    }

    public function testExcludedFieldIsLeftOut(): void
    {
        // The Matrix that holds the block itself: rendering it would repeat every sibling block.
        $rows = LayoutRows::defaults($this->vijesti(), ['pageBuilder']);

        self::assertNotContains('pageBuilder', array_map(static fn(Row $r) => $r->field, $rows));
        self::assertContains('body', array_map(static fn(Row $r) => $r->field, $rows));
    }

    public function testProductDefaultsTwoColumns(): void
    {
        $rows = LayoutRows::productDefaults([
            ['kind' => 'title', 'handle' => 'title', 'class' => ''],
            ['kind' => 'field', 'handle' => 'image', 'class' => 'craft\fields\Assets'],
            ['kind' => 'field', 'handle' => 'productsBrandCategories', 'class' => 'craft\fields\Entries'],
            ['kind' => 'field', 'handle' => 'rating', 'class' => 'craft\fields\PlainText'],
            ['kind' => 'field', 'handle' => 'body', 'class' => 'craft\ckeditor\Field'],
            ['kind' => 'field', 'handle' => 'productAttributes', 'class' => 'craft\fields\Entries'],
            ['kind' => 'field', 'handle' => 'seo', 'class' => 'nystudio107\seomatic\fields\SeoSettings'],
        ]);
        $by = static fn(string $c) => array_map(static fn(Row $r) => $r->field, array_values(array_filter($rows, static fn(Row $r) => $r->column === $c)));

        self::assertSame(['gallery', 'body'], $by('gallery'));
        self::assertSame(['context', 'title', 'rating', 'price', 'variants', 'cart', 'actions'], $by('details'), 'productAttributes are shown by the specs part below');
        self::assertSame(['specs', 'reviews', 'pager', 'related', 'similar'], $by('below'));
        $price = array_values(array_filter($rows, static fn(Row $r) => $r->field === 'price'))[0];
        self::assertSame('part', $price->format);
    }
}
