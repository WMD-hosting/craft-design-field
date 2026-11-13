# Release Notes for Design Field

## 2.0.0-alpha.1 - Unreleased

> {warning} Design Field 2 is for Craft CMS 6 and not complete yet. Craft 5 sites stay on 1.x.

### Added
- A larger look at a layout tile: hovering it, or reaching it with the keyboard, shows the picture at its real size with the layout's name, above or below the tile's row. It opens after a short pause, closes at once, and never selects or saves. Touch has no hover, so a tap selects as before.
- The condition rule Design → Variant is back, with the class name and stored config of Craft 5, so conditions in a project config written there work as they are. In a field layout, set a field's Entry Condition to the layouts that use it, and the field shows only while one of them is picked: Craft checks the condition on every refresh of the form, against the choice in the open editor. The rule offers the layouts of the entry type's profile (every profile's until a new entry type is saved), and only where a condition tests an element, not where one filters a query. A database that applied such conditions while the plugin had no rule dropped them from its stored layouts: run `php artisan craft:project-config:apply --force` once after updating.
- Motion options (an entrance, a slide transition) show as cards again, as on Craft 5: each card's small stage plays its motion on hover and keyboard focus, and stays still for visitors who turn off animations.
- Sliders are back: a range over the options in order, with the choice's name beside it. The editor posts the choice's place on the scale (under `__slider`), and the field stores its key.
- Position grids are back: square cells, `columns` to a row, with Auto on its own row above them. Keys that name a position set lay themselves out without `columns`: a nine-point grid (`top-left` ... `bottom-right`), the four corners, a row (`left`, `center`, `right`, each cell drawing where it sits) or a column (`top`, `middle`, `bottom`).
- Width bars: an option's `percent` (`75`, `75%`, `0.75`) draws a bar filled that far beside its name, whatever the stored key is.
- Two-colour swatches: `swatchText` draws "Aa" in that colour on the swatch, and two colours joined by `|` split the chip on the diagonal.
- "Aa" samples (`sample`) show at their font size in the button again, and count as narrow buttons when the field picks buttons or a dropdown.

### Fixed
- The config file is read once per request. Building the options read it again for every layout picture it looked up (about 65 times on a site with many blocks), which cost about 125 ms on each request that shows a Design value, and memory that a long-running process such as a test suite never got back.
- A section whose options all wait for another choice (Colour & style and Spacing on an image block with the caption layout) no longer shows its heading over an empty area; it comes back with its options when the layout changes.

### Changed
- Requires Craft CMS 6.0.0-beta.1 and PHP 8.5.
- The Design panel is built on Craft's own controls, one per option: buttons, dropdowns, switches, sliders, position grids, colour chips, and cards with a picture, under the same section headings. An option that only applies to another choice shows or hides while editing, as Craft refreshes the form after each change.
- The field settings are back: show the options as a panel, as a summary (a line saying what is set, the options behind Edit) or collapsed (a closed section that says how many options changed); lay them out in sections, sections side by side, or inline; and which block's options the field shows. A profile's `'_view'` still replaces the field's setting, and a new element starts open.
- Layout pictures come from the web root again (`tileImages` in the config file, `/design-field/{type}/{key}.jpg` by default). A picture whose file is missing is left out instead of showing as a broken image.
- Swatches are a row of colour chips with the name on hover, as on Craft 5, instead of a list of radios with a small dot.
- The configuration is the config file only: `config/craft/design-field.php`. Token files stay in `config/designtokens/`.
- The console commands are `php artisan design-field:schema`, `design-field:check` and `design-field:usage`, with the same options as on Craft 5. The template check reads `resources/views` (or `templates`) and the PHP under `app/`; set `templateCheck` in the config file to change that.
- Craft's MCP server reads and writes a Design value as a map of option handle => choice key, on the element itself and on blocks inside a Matrix field. `elements_field_schema` lists every option of the block with its choice keys; an unknown option or a key that is no choice of its option is refused with a message that names it. A read returns the options the block has, so what is read can be written back.

### Fixed
- Icons given as inline SVG (the column bars and ratio frames of the default config) showed nothing: Craft's icons take names. They are drawn in the button now, sanitised.
- Saving a block with a switch failed with "“1” is not an option of …": the editor posts a switch as true or false, which now means its on and off choice.

### Removed
- Not ported yet: presets (Craft's controls have no action that sets several options at once), the "Most used" list in long dropdowns, the plugin settings page, the block preview, and the starter stylesheet registration (`craft.designField.starterCss()` does nothing).
- The Craft 5 migration tools (`adopt`, `migrate`, `import`, Tidy up) stay on 1.x.

## 1.0.7 - 2026-10-29

### Added
- A profile can set its own view mode with `'_view' => 'panel'` (or `summary`, `collapsed`), which replaces the field's View setting for that profile only. Useful where the layout choice comes first, such as a campaign whose design tiles should stay in view.

### Changed
- A collapsed or summary Design panel starts open on a new element (a new entry, a block just added), when the editor is picking how it looks. Saved elements keep the panel closed as before.

## 1.0.6 - 2026-10-29

### Added
- A condition rule, Design → Variant is one of. In a field layout, set a field's "Show when" to the variants that use it, and the field shows only while one of them is picked: Craft updates the form as the editor picks another variant. The rule offers the variants of the entry type's profile.

### Fixed
- A Design value sent as a plain string (`"dark"`) or a number now fails validation. It used to become an empty choice map, so the save reset every option of the block to its default without an error. MCP agents hit this when a schema tool told them to send a string; send a JSON object of option handle => choice key instead, the same shape `get_entry` returns.

## 1.0.5 - 2026-10-15

### Added
- MCP servers get the Design value as data. MCP - All in one (`rewi.sh/craft-mcp`) printed it as the block's CSS classes, so an agent reading an entry could not tell which options were picked; Design Field now registers a field translator with it (only when it is installed), and `get_entry` returns `{"tone": "dark", "spacing": "loose", …}`. Send the same object back with `update_entry` to change options; unknown keys fail validation. The MCP plugin by stimmt (`stimmt/craft-mcp`) already returned the keys.
- The Design value is `JsonSerializable`: `json_encode()` gives the stored keys, not the classes.

## 1.0.4 - 2026-10-09

### Changed
- Design Field is free with every feature, in one edition. The Pro edition is gone (it unlocked nothing); the settings page now links to the Plugin Store to leave a review. Installs on Pro move to the single edition on their own.

## 1.0.3 - 2026-10-08

### Removed
- The undocumented Entry Fields field type. It needed site templates the plugin never shipped, so it rendered nothing on other installs. An existing Entry Fields field becomes a missing field; its values stay in the database.

## 1.0.2 - 2026-10-08

### Added
- Long dropdowns (10 choices or more) are searchable and show the three most picked choices for that block type first, under **Most used**. Counts come from the usage report, worked out by a queue job and cached for a day.
- Rendered tiles can draw several token parts: `'preview' => ['bg', 'text']` shows a tone's real background and text colour.
- `Design::EVENT_DEFINE_PANEL_GROUPS` (`DefinePanelGroupsEvent`): hide choices or whole options per entry before a panel is drawn (`hiddenGroups`; hidden options keep their value).

## 1.0.1 - 2026-10-08

### Changed
- The editions are now **Standard** (free, every feature) and **Pro** (for people who like it; nothing is gated), matching the Plugin Store's edition handles. Installs on Draft move to Standard on their own.

## 1.0.0 - 2026-10-08

First release.

### Added
- **Design field**: every design option of a block (layout, tone, spacing, columns, motion…) in one field, stored as named keys. Options are defined once in `config/design-field.php` or in the settings tables; profiles per entry type, shared options, presets, and conditions so each layout shows only the options its templates read.
- **Looks** for options: labelled buttons, icons, labels only, dropdown, slider, toggle, chip, swatches, picture tiles, position grid, motion tiles (entrances and transitions that play) and rendered tiles (drawn with the site's own stylesheet and brand). Set per option, per block, or swapped site-wide on the settings page.
- **Panel**: sections, presets, view modes (panel, summary, collapsed) and option layouts (sections, side by side, inline).
- **Settings page** with tabs: Looks, Preview (the real block next to its options at desktop, tablet and mobile widths), Presets, Tidy up and Configuration.
- **Tidy up**: option fields on your blocks and what each would become, Move this block (checks every stored value, then a queue job), Copy brief for Claude, the usage report with Make it the default and Hide (per block, with Undo).
- **Make tile pictures**: layout pictures drawn in the browser from the live preview; layouts pick them up without a config change.
- **Start here**: Tailwind CSS, Bootstrap 5 or Plain CSS starter options for a site with none, and `craft.designField.starterCss()`.
- **Agents and CI**: `craft design-field/schema` (JSON, markdown, a marked AGENTS.md section) and `craft design-field/check` (options no template reads, reads of options a block lacks) with an exit code.
- **Moving an existing site**: `craft design-field/import`, `adopt` and `migrate` (Dropdown, Button Group, Radio Buttons, Lightswitch, Button Box, Color palette and Design Tokens fields).
- Editions: **Draft** (free, every feature) and **Published** (for people who like it; nothing is gated).
