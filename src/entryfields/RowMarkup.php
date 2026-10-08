<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * Wraps one rendered value in its row's tag, style, label and text after.
 *
 * Inputs are already safe HTML; this class only assembles. An empty value
 * renders nothing, so a label never dangles without its value.
 *
 * @author WMD
 * @since 1.1.0
 */
final class RowMarkup
{
    private const BLOCK_TAGS = '/<(ul|ol|div|section|table|p|h[1-6]|figure|blockquote)[\s>]/i';

    /**
     * @param Row $row
     * @param string $valueHtml
     * @param string $labelHtml
     * @param string $textAfterHtml
     * @param array<string,string|array{label?:string,value?:string}> $styles
     * @return string
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function wrap(Row $row, string $valueHtml, string $labelHtml, string $textAfterHtml, array $styles): string
    {
        if (self::isEmpty($valueHtml)) {
            return '';
        }

        $token = $styles[$row->style] ?? '';
        $class = trim('entry-field ' . (is_array($token) ? (string)($token['value'] ?? '') : (string)$token));
        $tag = preg_match(self::BLOCK_TAGS, $valueHtml) ? 'div' : $row->tag;

        $inner = trim($valueHtml);
        if ($labelHtml !== '') {
            $inner = '<span class="entry-field-label">' . $labelHtml . '</span> ' . $inner;
        }
        if ($textAfterHtml !== '') {
            $inner .= ' <span class="entry-field-after">' . $textAfterHtml . '</span>';
        }

        return sprintf('<%1$s class="%2$s">%3$s</%1$s>', $tag, htmlspecialchars($class, ENT_QUOTES), $inner);
    }

    /**
     * @param string $html
     * @return bool
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function isEmpty(string $html): bool
    {
        return trim(strip_tags($html)) === '' && !preg_match('/<(img|svg|iframe|video|picture|input|select|textarea|button)[\s>]|<i\s[^>]*class="[^"]+"/i', $html);
    }
}
