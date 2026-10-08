<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\web;

use craft\helpers\Json;
use craft\web\View;

/**
 * Browser side of the Entry Fields table.
 *
 * - Narrows each row's Format to the formats that fit its Field, and labels `auto` with what it
 *   resolves to ("auto (date)"); custom formats show their config label. The map comes from the
 *   server (FormatPicker), never decided here.
 * - Shows the Global element picker only on `element` rows, with Edit (opens the element in a
 *   slideout) and New (creates one in the Global Elements section and opens it).
 * - Hides the Slot column and shows the "Following the layout" note while the block's Layout
 *   is Ordered, live as the Design panel changes.
 * - On a block with its own Section field (the Entry List), offers in Field only the fields of
 *   the ticked sections, live; nothing ticked offers all. A chosen field outside them stays,
 *   marked, so a saved row never changes by unticking.
 *
 * Same style as GroupInput: one inline script, document-level listeners, no build step.
 *
 * @author WMD
 * @since 1.1.0
 */
class EntryFieldsInput
{
    /**
     * Registers the script and styles once, and starts the input with the given id.
     *
     * @param View $view
     * @param string $id Namespaced id of the `.entry-fields` wrapper
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function register(View $view, string $id): void
    {
        $view->registerCss(self::CSS, [], 'entry-fields');
        $view->registerJs(self::JS, View::POS_END, 'entry-fields');
        // Key by the namespaced id: two blocks share the plain id, and Yii keeps one script per key.
        $namespaced = $view->namespaceInputId($id);
        $view->registerJs('window.EntryFieldsInput && window.EntryFieldsInput.init(' . Json::encode($namespaced) . ');', View::POS_END, "entry-fields-$namespaced");
    }

    private const CSS = <<<'CSS'
.entry-fields.ef-is-ordered .ef-col-slot { display: none; }
.entry-fields td.ef-off .ef-element-wrap { display: none; }
/* The Global element column only shows while some row uses format `element`. */
.entry-fields:not(.ef-has-element) .ef-col-element { display: none; }
.entry-fields .ef-element-wrap { display: flex; gap: 4px; align-items: center; }
.entry-fields .ef-element-wrap .btn { flex: none; }
/* Selects keep their natural width; give the free-text cells (Label, Text after) room. */
.entry-fields table.editable textarea { min-width: 8rem; }
CSS;

    private const JS = <<<'JS'
window.EntryFieldsInput = window.EntryFieldsInput || (function() {
    function cfg(root) {
        if (!root._ef) { root._ef = JSON.parse(root.getAttribute('data-ef')); }
        return root._ef;
    }
    function input(row, key) { return row.querySelector('[name$="[' + key + ']"]'); }
    function rows(root) { return root.querySelectorAll('table.editable tbody tr'); }

    // The Layout of the Design panel that sits next to this field in the same block.
    function layout(root) {
        var node = root.parentElement, panel = null;
        while (node && !panel) { panel = node.querySelector('.design-field'); node = node.parentElement; }
        var group = panel && panel.querySelector('[data-df-group="variant"]');
        if (!group) { return null; }
        var radio = group.querySelector('input[type=radio]:checked');
        if (radio) { return radio.value; }
        var hidden = group.querySelector('input[type=hidden][name$="[variant]"], select[name$="[variant]"]');
        return hidden ? hidden.value : null;
    }

    // Tag the Slot and Global element columns (header + cells) so CSS can hide them.
    function markColumns(root) {
        ['slot', 'element'].forEach(function(key) {
            var index = cfg(root).cols.indexOf(key);
            if (index === -1) { return; }
            var head = root.querySelectorAll('table.editable thead th')[index];
            if (head) { head.classList.add('ef-col-' + key); }
            rows(root).forEach(function(row) {
                var cell = input(row, key);
                if (cell) { cell.closest('td').classList.add('ef-col-' + key); }
            });
        });
    }

    function setElementColumn(root) {
        var any = false;
        rows(root).forEach(function(row) {
            var format = input(row, 'format');
            if (format && format.value === 'element') { any = true; }
        });
        root.classList.toggle('ef-has-element', any);
    }

    function setOrdered(root) {
        var key = layout(root);
        var keys = cfg(root).orderedKeys || ['ordered'];
        var ordered = key === null ? cfg(root).ordered : keys.indexOf(key) !== -1;
        root.classList.toggle('ef-is-ordered', ordered);
        var note = root.querySelector('.ef-ordered-note');
        if (note) { note.hidden = !ordered; }
    }

    // The block's own Section inputs: this field's input names with the section field's handle instead.
    function sectionInputs(root) {
        var c = cfg(root), any = c.sections && root.querySelector('[name*="[' + c.handle + ']"]');
        if (!any) { return []; }
        var at = any.name.lastIndexOf('[' + c.handle + ']');
        var prefix = any.name.slice(0, at) + '[' + c.sections.field + ']';
        return [].slice.call(document.querySelectorAll('[name^="' + prefix + '"]'));
    }

    // Positions of the Field groups the ticked sections offer, or null when none is ticked (offer every field).
    function allowedGroups(root) {
        var c = cfg(root), ids = [];
        sectionInputs(root).forEach(function(el) {
            if (el.tagName === 'SELECT') {
                [].forEach.call(el.selectedOptions, function(o) { if (o.value) { ids.push(o.value); } });
            } else if ((el.type === 'checkbox' || el.type === 'radio') && el.checked && el.value) {
                ids.push(el.value);
            }
        });
        if (!ids.length) { return null; }
        var allowed = {};
        c.sections.keep.forEach(function(i) { allowed[i] = true; });
        ids.forEach(function(id) { (c.sections.groups[id] || []).forEach(function(i) { allowed[i] = true; }); });
        return allowed;
    }

    // Rebuilds the Field options from the full list: the allowed groups, plus the current choice, marked.
    function filterFields(root, row, allowed) {
        var select = input(row, 'field');
        if (!select || !cfg(root).sections) { return; }
        if (select._efAll === undefined) { select._efAll = select.innerHTML; }
        var current = select.value, source = document.createElement('select'), position = -1, kept = false;
        source.innerHTML = select._efAll;
        select.innerHTML = '';
        // When an allowed group lists the current field, it shows there, unmarked.
        [].forEach.call(source.querySelectorAll('optgroup'), function(group, i) {
            if ((!allowed || allowed[i]) && [].some.call(group.children, function(o) { return o.value === current; })) { kept = true; }
        });
        [].forEach.call(source.children, function(child) {
            if (child.tagName !== 'OPTGROUP') { select.appendChild(child.cloneNode(true)); return; }
            position++;
            var open = !allowed || allowed[position], group = child.cloneNode(false);
            [].forEach.call(child.children, function(o) {
                // A filtered-out group still shows the current field, marked, unless an allowed group has it.
                if (open || (o.value === current && current && !kept)) {
                    var option = o.cloneNode(true);
                    if (!open) { option.textContent += ' (' + cfg(root).labels.notInSections + ')'; }
                    if (o.value === current) { kept = true; }
                    group.appendChild(option);
                }
            });
            if (group.children.length) { select.appendChild(group); }
        });
        select.value = current;
    }

    function filterAll(root) {
        if (!cfg(root).sections) { return; }
        var allowed = allowedGroups(root);
        rows(root).forEach(function(row) { filterFields(root, row, allowed); });
    }

    // Format options that fit the row's field; keeps the current choice when it still fits.
    function setFormats(root, row) {
        var c = cfg(root), field = input(row, 'field'), format = input(row, 'format');
        if (!field || !format) { return; }
        var meta = c.meta[field.value] || { formats: c.allFormats, auto: null };
        var current = format.value;
        format.innerHTML = '';
        meta.formats.forEach(function(f) {
            var option = document.createElement('option');
            option.value = f;
            option.textContent = f === 'auto' && meta.auto ? 'auto (' + meta.auto + ')' : ((c.formatLabels || {})[f] || f);
            format.appendChild(option);
        });
        format.value = meta.formats.indexOf(current) !== -1 ? current : meta.formats[0];
        setElement(root, row);
    }

    function addOption(select, value, label) {
        var option = select.querySelector('option[value="' + value + '"]');
        if (!option) {
            option = document.createElement('option');
            option.value = value;
            select.appendChild(option);
        }
        option.textContent = label;
        select.value = String(value);
    }

    function edit(root, select, elementId, draftId) {
        var c = cfg(root).element;
        var settings = { elementId: elementId, siteId: c.siteId };
        if (draftId) { settings.draftId = draftId; }
        var slideout = Craft.createElementEditor(c.elementType, settings);
        slideout.on('submit', function(ev) {
            var data = ev.data || {};
            addOption(select, data.canonicalId || data.id || elementId, data.title || cfg(root).labels.untitled);
        });
    }

    // The Global element picker only matters on `element` rows; Edit and New sit next to it.
    function setElement(root, row) {
        var select = input(row, 'element'), format = input(row, 'format');
        if (!select) { return; }
        var cell = select.closest('td');
        cell.classList.toggle('ef-off', !format || format.value !== 'element');
        setElementColumn(root);
        if (cell.querySelector('.ef-element-wrap')) { return; }
        var labels = cfg(root).labels;
        var wrap = document.createElement('div');
        wrap.className = 'ef-element-wrap';
        // Craft wraps a select in div.select: move that whole wrapper into ours, never into itself.
        var box = select.closest('.select') || select;
        box.parentNode.insertBefore(wrap, box);
        wrap.appendChild(box);
        [['edit', labels.edit], ['new', labels.new]].forEach(function(pair) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn small';
            button.textContent = pair[1];
            button.setAttribute('data-ef-action', pair[0]);
            wrap.appendChild(button);
        });
    }

    function onAction(root, button) {
        var row = button.closest('tr'), select = input(row, 'element'), c = cfg(root).element;
        if (button.getAttribute('data-ef-action') === 'edit') {
            if (select.value) { edit(root, select, select.value); }
            return;
        }
        Craft.sendActionRequest('POST', 'elements/create', { data: {
            elementType: c.elementType, siteId: c.siteId, sectionId: c.sectionId, typeId: c.typeId,
        } }).then(function(response) {
            var element = response.data.element;
            addOption(select, element.canonicalId || element.id, element.title || cfg(root).labels.untitled);
            edit(root, select, element.canonicalId || element.id, element.draftId);
        });
    }

    function refresh(root) {
        markColumns(root);
        setOrdered(root);
        filterAll(root);
        rows(root).forEach(function(row) { setFormats(root, row); });
    }

    function init(id) {
        var root = document.getElementById(id);
        if (!root || root._efStarted) { return; }
        root._efStarted = true;
        refresh(root);
        // New rows from "Add a field".
        var body = root.querySelector('table.editable tbody');
        if (body) {
            new MutationObserver(function() { markColumns(root); rows(root).forEach(function(row) {
                if (!row._ef) { row._ef = true; filterFields(root, row, cfg(root).sections ? allowedGroups(root) : null); setFormats(root, row); }
            }); }).observe(body, { childList: true });
            rows(root).forEach(function(row) { row._ef = true; });
        }
        root.addEventListener('change', function(event) {
            var name = event.target.name || '';
            var row = event.target.closest('tr');
            if (!row) { return; }
            if (/\[field\]$/.test(name)) { setFormats(root, row); filterAll(root); }
            if (/\[format\]$/.test(name)) { setElement(root, row); }
        });
        root.addEventListener('click', function(event) {
            var button = event.target.closest('[data-ef-action]');
            if (button) { event.preventDefault(); onAction(root, button); }
        });
    }

    // Ticking a section narrows the Field options of the Entry Fields in the same block.
    document.addEventListener('change', function(event) {
        var name = event.target.name || '';
        document.querySelectorAll('.entry-fields').forEach(function(root) {
            var c = cfg(root);
            if (c.sections && name.indexOf('[' + c.sections.field + ']') !== -1 && sectionInputs(root).indexOf(event.target) !== -1) { filterAll(root); }
        });
    }, true);

    // Layout changes in a Design panel switch ordered mode live (button groups set hidden inputs, so watch clicks too).
    ['click', 'change', 'keyup'].forEach(function(type) {
        document.addEventListener(type, function(event) {
            if (event.target.closest && event.target.closest('.design-field')) {
                setTimeout(function() { document.querySelectorAll('.entry-fields').forEach(setOrdered); }, 0);
            }
        }, true);
    });

    return { init: init };
})();
JS;
}
