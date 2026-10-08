<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * Default rows for the ordered Content variant: the entry type's field layout, in order,
 * each element shown the way the classic Content block shows it.
 *
 * Computed at render time and never stored, so a block with no rows follows layout changes.
 *
 * @author WMD
 * @since 1.1.0
 */
final class LayoutRows
{
    // Constants
    // =========================================================================

    /** The single slot the ordered variant renders. */
    public const SLOT = 'content';

    /** Product-type fields the parts already show (specs show productAttributes). */
    private const COVERED_BY_PARTS = ['image', 'productsBrandCategories', 'productsCategories', 'rating', 'body', 'productAttributes'];

    // Public Methods
    // =========================================================================

    /**
     * @param list<array{kind:string, handle:string, class:string, parents?:list<string>, matrix?:string}> $elements
     * @param list<string> $exclude Field handles to leave out (the Matrix that holds the block itself)
     * @return list<Row>
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function defaults(array $elements, array $exclude = []): array
    {
        $rows = [];
        $coverTaken = false;

        foreach ($elements as $element) {
            $handle = $element['handle'];
            if (in_array($handle, $exclude, true)) {
                continue;
            }

            if ($element['kind'] === 'title') {
                $rows[] = new Row(field: 'title', slot: self::SLOT, tag: 'h1', style: 'title');
                $rows[] = new Row(field: 'postDate', slot: self::SLOT, format: 'date', tag: 'p', style: 'meta');
                continue;
            }

            $auto = FormatPicker::auto($element['class'], $element['parents'] ?? []);
            if ($auto === null) {
                continue;
            }

            $row = match (true) {
                $auto === 'files' && !$coverTaken => new Row(field: $handle, slot: self::SLOT, format: 'cover'),
                $handle === 'eyebrow' => new Row(field: $handle, slot: self::SLOT, style: 'eyebrow'),
                $handle === 'subheading' => new Row(field: $handle, slot: self::SLOT, tag: 'div', style: 'lead'),
                in_array($element['class'], ['craft\fields\Entries', 'craft\fields\Categories', 'craft\fields\Tags'], true) => new Row(field: $handle, slot: self::SLOT, format: 'chips'),
                $auto === 'items' && isset($element['matrix']) => new Row(field: $handle, slot: self::SLOT, format: $element['matrix']),
                default => new Row(field: $handle, slot: self::SLOT, format: $auto === 'rich' ? 'rich' : 'auto'),
            };
            $coverTaken = $coverTaken || $row->format === 'cover';
            $rows[] = $row;
        }

        return $rows;
    }


    /**
     * Default two columns of a product page: Gallery (images, then the description under them)
     * and Details (context, title, rating, price, picker, cart, wishlist/compare, then the
     * remaining product-type fields in layout order).
     *
     * @param list<array{kind:string, handle:string, class:string, parents?:list<string>, matrix?:string}> $elements
     * @param list<string> $exclude
     * @return list<Row>
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function productDefaults(array $elements, array $exclude = []): array
    {
        $handles = array_column($elements, 'handle');
        $part = static fn(string $field, string $column, array $extra = []) => new Row(...['field' => $field, 'slot' => self::SLOT, 'format' => 'part', 'column' => $column] + $extra);

        $rows = [$part('gallery', 'gallery')];
        if (in_array('body', $handles, true)) {
            $rows[] = new Row(field: 'body', slot: self::SLOT, format: 'rich', column: 'gallery');
        }
        $rows[] = $part('context', 'details');
        $rows[] = new Row(field: 'title', slot: self::SLOT, tag: 'h1', style: 'title', column: 'details');
        foreach (['rating', 'price', 'variants', 'cart', 'actions'] as $name) {
            $rows[] = $part($name, 'details');
        }

        foreach (self::defaults(array_values(array_filter($elements, static fn(array $e) => $e['kind'] === 'field' && !in_array($e['handle'], self::COVERED_BY_PARTS, true))), $exclude) as $row) {
            $rows[] = Row::fromArray(['column' => 'details'] + $row->toArray()) ?? $row;
        }

        // Full width under both columns: today's lower product page, in its order.
        foreach (['specs', 'reviews', 'pager', 'related', 'similar'] as $name) {
            $rows[] = $part($name, 'below');
        }

        return $rows;
    }
}
