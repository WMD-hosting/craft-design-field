<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web\controls;

/**
 * The HTML our Vue controls fall back to on screens that are not drawn with Vue: a plain
 * dropdown of the same choices, posting the same key under the same name.
 *
 * @author WMD
 * @since 2.1.0
 */
trait SelectFallback
{
    /**
     * @param list<array{value:string,label:string}> $choices
     * @param mixed $value The chosen key
     * @param array{id:string,name:?string,disabled:bool,readonly:bool} $attributes From the renderer
     * @return string
     *
     * @author WMD
     * @since 2.1.0
     */
    protected static function selectHtml(array $choices, mixed $value, array $attributes): string
    {
        $options = '';

        foreach ($choices as $choice) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($choice['value']),
                (string)$choice['value'] === (string)$value ? ' selected' : '',
                e($choice['label']),
            );
        }

        return sprintf(
            '<select id="%s"%s%s>%s</select>',
            e($attributes['id']),
            $attributes['name'] !== null ? ' name="' . e($attributes['name']) . '"' : '',
            $attributes['disabled'] || $attributes['readonly'] ? ' disabled' : '',
            $options,
        );
    }
}
