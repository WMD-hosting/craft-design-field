# Release Notes for Design Field

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
