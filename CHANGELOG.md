# Release Notes for Design Field

## Unreleased

### Added
- The configuration check reports a config file left at the Craft 5 path, `config/design-field.php`, which Craft 6 does not read: an error while it is the only one (no option is configured), a warning beside `config/craft/design-field.php`. While it is the only one, admins also see it as a control-panel alert.

### Changed
- The plugin names its developer as WMD Hosting (https://wmd.hosting/), as its Plugin Store listing does, and its description in the control panel and on Packagist is one sentence.

### Fixed
- A configuration that does not build no longer takes the site down. A profile naming a group that is gone, a missing token file or a syntax error in `config/craft/design-field.php` was an error page on every request that read a Design value, and on every save of such an element. Pages now keep rendering, with each template's own defaults instead of the design classes; stored choices stay as they are through a save, and a panel that was open when the file broke is refused instead of saved half-read; MCP and GraphQL read the stored map and MCP refuses to write Design options until the file is fixed. Every control-panel page shows an alert (admins read the reason, with a link to the settings page), the reason is logged once per request, and `design-field:check` still fails on it.
- A config file of the wrong shape (a group that is a string, a file with a syntax error) is a finding of the configuration check and of the settings page, instead of a crash of both.
- With such a configuration the registry is built once per request, not once per Design value on the page.
- A stored choice `0` of an option the block does not have at the moment (a gap of `0` kept from another profile) is no longer dropped when the element is saved.
- GraphQL reads a Design field as a JSON string of option handle => choice key again (`{"tone":"dark","spacing":"normal"}`), as 1.x did and as the README says. 2.x answered a query with the block's CSS classes (`"bg-black py-8"`), which name no choice and cannot be sent back in a mutation. An element without options reads as `{}`.
- A value that is a list (`["dark"]`), or one whose choice is a list or a map (`{"tone": ["dark"]}`), is refused on save with "… is not a design value", whichever way it arrives. Sent through a GraphQL mutation or set from PHP it used to be accepted: the list was stored under `0` beside every default, and such a choice counted as not chosen, so the block's options were reset without an error. MCP already refused both by its schema.

## 2.0.0-beta.7 - 2026-11-26

### Added
- The Design panel speaks the editor's language. The names a site's config gives its options, choices, section headings, help text and presets are translated in Craft's `site` category, as Craft translates field names: a site gives them in `lang/<locale>/site.php`, keyed by the config's English. The summary line, the presets' names and the "Option: Choice" tooltips follow. A name without a translation stays as the config has it; saved values, templates, the settings page, validation messages and the schema for agents keep the config's words.
- The panel's own words (Edit, Options · N changes, All options at their defaults, Most used, All choices, Presets) are translated in the plugin's category `design-field`, with Croatian included.

## 2.0.0-beta.6 - 2026-11-24

### Changed
- With sections side by side (the field's option layout), the sections below the layout pictures stand in two columns of about the same height, in reading order: the left column holds the first sections, the right one the rest. Paired row by row, a short section left a hole beside a long one (a list of twelve switches beside one dropdown), and the next pair started below the longer of the two. A narrow panel, where Craft stacks the columns, lists the sections in the same order as before.
- Side by side, options without a section stand in the columns as a group without a heading, instead of full-width rows between the sections. A section with nothing to stand beside keeps the whole row.

## 2.0.0-beta.5 - 2026-11-23

### Changed
- With sections side by side (the field's option layout), the section that shows layout pictures takes the whole row, and the other sections pair up below it. In a wide slideout, half the row held four pictures and left the other half empty; at about 1,450px a row now holds eight, in an 800px panel four instead of two. Where Craft stacks the sections anyway (a narrow panel, a phone) nothing changes.
- The plugin's icon is the new one of the wmd.hr family.

## 2.0.0-beta.4 - 2026-11-21

### Added
- The settings page has its **Looks** and **Presets** tabs back. Looks: a quick switch of one input style for another on every block, and one row per option with its look and the section it is listed under. Presets: one row per preset, checked against the options before it is saved. Both save to `config/craft/design-field.settings.json`, beside the config file, only where admin changes are allowed: commit that file with the site.
- **Customize this block** is back on the settings page's Preview tab: a look for one block only, one dropdown per option at the end of the block's panel. The panel shows the look as soon as it is picked, before it is saved; Save writes the looks of that block to `config/craft/design-field.settings.json` (`inputs.profiles`) and keeps everything else in the file. The choices made in the panel itself are still never saved.
- **Labels and icons** are back on the Looks tab: pick an option to give its choices other names and icons (Craft's icon picker) on every block. An empty cell keeps what the config file says. Saved to the settings file as `inputs.meta`.
- **Try these looks** on the Looks tab shows one block's panel with the looks as they stand in the form, before they are saved.
- The configuration check also reports a `design-field.settings.json` that is not valid JSON, for example after a merge conflict: nothing saved on the page applies until it is fixed.

### Changed
- The configuration is read from two files: `config/craft/design-field.php`, then `config/craft/design-field.settings.json` when the settings page has written it. Its looks win entry by entry; its presets apply unless the config file has a `presets` key. A site without that file reads exactly as before.

## 2.0.0-beta.3 - 2026-11-21

### Added
- The settings page is back (Settings → Plugins → Design Field, admins only), with two tabs that only read, so it also opens where admin changes are off. **Configuration** shows what `design-field:check` prints: the configuration check and the template check. **Usage** shows the usage report, one row per block, for all time or the last 12, 6 or 3 months; a run over all time also refreshes the "Most used" counts. A Design field's settings link to the page.
- The settings page has its **Preview** tab back: the real Design panel of any block with its defaults and presets, and nothing in it is saved. With `blockPreviewUrl` in the config file the block itself renders next to the panel, at desktop, tablet or mobile width, and follows each change. `examples/block-preview.twig` is back as a page for it, in its Craft 6 form.
- **Make tile pictures** is back on the Preview tab: the layout pictures a block is missing are drawn in the browser from `blockPreviewUrl` and saved to `tileImages`, where admin changes are allowed. `tileSkip` leaves things such as a dev toolbar out of the pictures.

## 2.0.0-beta.2 - 2026-11-21

### Added
- The starter stylesheet is back: `{% do craft.designField.starterCss() %}` in the site layout adds it to the page's head, once.
- A site can trim a panel for the element being edited again. Listen for `wmd\designfield\events\DefinePanelGroups` (a Laravel event, in place of `Design::EVENT_DEFINE_PANEL_GROUPS`): replace an option with `withHidden()` to leave choices out of the picker, or add its handle to `hiddenGroups` to leave the whole option out. Stored values, validation and templates are unaffected.
- "Most used" is back: a dropdown of ten choices or more lists the three most picked choices for that block type first, under their own heading. The counts come from a queue job and are kept for a day.

## 2.0.0-beta.1 - 2026-11-21

> {warning} Design Field 2 is for Craft CMS 6 and is a beta, as Craft 6 is. The parts of 1.x it does not have yet are listed under Removed. Craft 5 sites stay on 1.x.

### Added
- Presets are back: a row of buttons above a block's options, and clicking one sets its choices in one change. They are set in the config file (`presets`), a block's own first, then the `*` ones.
- Rendered tiles are back: choices drawn as small cards with the site's own stylesheet (`previewStylesheet`, `previewAttributes`), so Corner radius and Card style show the brand's real radius, border and shadow.
- Sliders name every stop under the track, with Block default as its own button beside it.
- Buttons that show a drawing instead of a label (icons, position cells) read out the chosen name.
- Layout pictures show at the Craft 5 size again: 146px wide instead of Craft's 85px card picture.
- The layout comes first in the Design panel, at the top of its section, ahead of options without a section: it is the first thing an editor picks, and most other options follow it.
- A larger look at a layout tile: hovering it, or reaching it with the keyboard, shows the picture at its real size with the layout's name, above or below the tile's row. It opens after a short pause, closes at once, and never selects or saves. Touch has no hover, so a tap selects as before.
- The condition rule Design → Variant is back, with the class name and stored config of Craft 5, so conditions in a project config written there work as they are. In a field layout, set a field's Entry Condition to the layouts that use it, and the field shows only while one of them is picked: Craft checks the condition on every refresh of the form, against the choice in the open editor. The rule offers the layouts of the entry type's profile (every profile's until a new entry type is saved), and only where a condition tests an element, not where one filters a query. A database that applied such conditions while the plugin had no rule dropped them from its stored layouts: run `php artisan craft:project-config:apply --force` once after updating.
- Motion options (an entrance, a slide transition) show as cards again, as on Craft 5: each card's small stage plays its motion on hover and keyboard focus, and stays still for visitors who turn off animations.
- Sliders are back: a range over the options in order, with the choice's name beside it. The editor posts the choice's place on the scale (under `__slider`), and the field stores its key.
- Position grids are back: square cells, `columns` to a row, with Auto on its own row above them (leading the row when there is one row). A choice a block leaves out keeps its place empty in a nine-point grid or the corners, and cells outside a position set show their names. Keys that name a position set lay themselves out without `columns`: a nine-point grid (`top-left` ... `bottom-right`), the four corners, a row (`left`, `center`, `right`, each cell drawing where it sits) or a column (`top`, `middle`, `bottom`).
- Width bars: an option's `percent` (`75`, `75%`, `0.75`) draws a bar filled that far beside its name, whatever the stored key is.
- Two-colour swatches: `swatchText` draws "Aa" in that colour on the swatch, and two colours joined by `|` split the chip on the diagonal.
- "Aa" samples (`sample`) show at their font size in the button again, and count as narrow buttons when the field picks buttons or a dropdown.

### Fixed
- Layout pictures in a narrow column (sections side by side on a laptop screen) showed one stretched picture per row, which made a block with ten layouts about 2,500px tall. Two fit in a row now.
- The config file is read once per request. Building the options read it again for every layout picture it looked up (about 65 times on a site with many blocks), which cost about 125 ms on each request that shows a Design value, and memory that a long-running process such as a test suite never got back.
- A section whose options all wait for another choice (Colour & style and Spacing on an image block with the caption layout) no longer shows its heading over an empty area; it comes back with its options when the layout changes.

### Changed
- Requires Craft CMS 6.0.0-beta.2 and PHP 8.5.
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
- Not ported yet: the plugin settings page (so the config file is the whole configuration), the block preview and Make tile pictures, the "Most used" list in long dropdowns, `Design::EVENT_DEFINE_PANEL_GROUPS`, and the starter stylesheet registration (`craft.designField.starterCss()` does nothing).
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
