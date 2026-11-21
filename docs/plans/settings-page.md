# The settings page on Craft 6

The 1.x settings page (Settings → Plugins → Design Field) comes to 2.x in stages. Each stage is one pull
request into `2.x` that can be released on its own. The config file `config/craft/design-field.php` stays
the place for groups, options and profiles; stage 4 adds a second file for the looks and presets
edited on the page.

## How the page is built

- **Where:** Craft's own plugin settings URL, `settings/plugins/design-field`, as on 1.x. The plugin sets
  `hasCpSettings` and `hasReadOnlyCpSettings` and overrides `getSettingsResponse()` and
  `getReadOnlySettingsResponse()`, so Craft's Plugins list links to it and it opens with admin changes off
  (production). There is no settings model and no nav item.
- **Who:** admins only. Craft's route already carries `RequireAdmin`; the plugin's own routes (stages 2
  and 3) repeat it. No new permission.
- **Tabs:** one URL per tab, `?tab=configuration|usage|preview`, drawn as the screen's sub-navigation
  (`CpScreenResponse::subnav()` with `NavItem`s, the pattern of WMD SEO's Global SEO screen). In-page Ui
  tabs would build every tab on each load, and the usage report reads every entry.
- **What draws it:** `wmd\designfield\http\SettingsScreen` builds a `CpScreenResponse` from Craft's Ui
  nodes (`Callout`, `Heading`, `MarkdownContent`, `Table`) with no submit action. Stage 1 needs no
  template, no script and no asset build.
- **Tests:** feature tests in `tests/feature/` on `PluginTestCase` open the URL as user 1 and read the
  Inertia page props (the nodes by uid), with the configuration given through a `Groups` double as the
  other feature tests do.

## Stage 1: Configuration and Usage (read only)

| | |
|---|---|
| Screen | `settings/plugins/design-field` (Configuration, the default tab) and `?tab=usage` |
| Routes | none of its own: Craft's `GET settings/plugins/{handle}` |
| Permissions | admin; opens with admin changes off |
| Reused | `services\Health` (`check()`, `templates()`: what `design-field:check` prints), `services\TemplateChecker` through it, `services\Usage` (`report()`, `storeCounts()`), `helpers\UsageReport::summarize()` |

- **Configuration:** a callout with the verdict, then two tables, Configuration check and Template check,
  one row per finding with its level. A configuration that does not build shows its error as the only
  finding.
- **Usage:** runs only when asked (`?tab=usage&run=1`), because it reads every entry. A period filter
  (`months=0|12|6|3`: all time, last 12, 6, 3 months), three totals (blocks with findings, options never
  changed, always the same choice), "Never changed in any block", then one table with a row per block:
  the options nobody changed from the default and the ones where every entry picked the same choice
  (one table per block ran to 400 rows on a real site). It can be searched and sorted. Blocks with
  fewer than five entries are marked as a hint and come last. A
  run over all time also refreshes "Most used" through `Usage::storeCounts()`, as on 1.x; a run limited
  to a period does not, or the counts would shrink to that window.
- **Field settings:** the Design field's settings end with a link to the page.
- **Tests:** `SettingsPageTest`: the page opens for an admin, also with admin changes off, and not for
  another user; the findings of a broken and of a clean configuration; the usage tab before a run, after
  a run with seeded entries, with a period, and what it stores for "Most used"; the link in the field
  settings.

## Stage 2: Preview

| | |
|---|---|
| Screen | `?tab=preview&block=<profile>` |
| Routes | `routes/cp.php`: `POST design-field/preview/ui?block=<profile>` (the tab's Ui built again for the choices the panel holds) |
| Permissions | admin (`RequireAdmin`); reading only, so it works with admin changes off |
| Reused | `fields\Design::uiControl()` and `web\OptionControls` (the real panel, its presets and defaults), `web\controls\PresetBlock`, `services\Groups`, the Vue controls in `resources/js` |

- The real Design panel of one block, picked from a dropdown of the profiles (the block with the most
  options first), with its defaults and presets. It is a Ui form without a submit: nothing is saved.
  Picking a block opens its URL (`design-field:block-picker`), so the page, its address and the panel's
  refreshes name the same block. Under the panel, the block's group handles and keys, for writing
  preset choices in the config file.
- The panel refreshes as it does in an element's form: the Ui page posts the panel's values and its
  scope to the route, which builds the tab again and answers with that scope.
- With `blockPreviewUrl` in the config file (`/_design-field/block?type={type}&design={design}`), the
  panel's control becomes `design-field:preview` (`PresetBlock::frame()`): the panel and, beside it, the
  block in an iframe at desktop (1280), tablet (768) or mobile (375) width, scaled to fit and loaded
  again after each change. `{design}` is the panel's choices as JSON, switches as their keys.
- `examples/block-preview.twig` comes back in a Craft 6 form (a Laravel route with `TemplateRoute`,
  `Request.query()`, `setFieldValue` for this render only).
- **Tests:** `PreviewTabTest`: the tab lists the profiles and shows the panel of the picked one; an
  unknown block falls back; the frame is there only with `blockPreviewUrl`; the refresh route answers
  for the panel's scope and for the whole tab, for admins only, and saves nothing.

## Stage 3: Make tile pictures

| | |
|---|---|
| Screen | a button on the Preview tab, above the block, shown when the block has a layout list, `blockPreviewUrl` and `tileImages` are set and admin changes are allowed |
| Routes | `routes/cp.php`: `POST design-field/tiles/save` |
| Permissions | admin and `RequireAdminChanges` (the pictures are site files that belong in the repository) |
| Reused | `services\Groups` (`tilePath()`, `tileImage()`), `helpers\Registry` (the block's layout list), the `design-field:preview` control of stage 2, `modern-screenshot` as on 1.x (MIT, bundled by Vite as its own chunk, loaded on the first click) |

- Each layout without a picture loads in a hidden frame through `blockPreviewUrl`, is drawn to a 480 px
  JPEG in the browser and posted to the route, which checks block, layout, size and type (480 px wide
  JPEG, under 2 MB) and writes it to `tileImages` under the web root. With none missing it offers to make
  them all again. `tileSkip` (a CSS selector) leaves things out of the picture. The page loads again
  when pictures were made, so the panel shows them.
- Pictures are per entry type, as the registry reads them: `pageBuilder:blockHero` and `blockHero`
  share theirs.
- **Tests:** `TilesTest`: the tab offers it for a block with layouts only, and not with admin changes
  off; a valid picture is written to the tile path and the panel then shows it; an unknown block or
  layout, a path as a key, another width, another image type and an oversized body are refused and
  nothing is written; the route carries `RequireAdmin` and `RequireAdminChanges`.

## Stage 4: Looks and Presets

Decided by wmd on 21.11.2026: the page writes a file beside the config file.

| | |
|---|---|
| Screen | `?tab=looks` and `?tab=presets` |
| Routes | `routes/cp.php`: `POST design-field/settings/looks`, `POST design-field/settings/presets` |
| Permissions | admin and `RequireAdminChanges`; with admin changes off the tabs show read only |
| Reused | `helpers\StyleChoices` (`rows()`, `value()`, `style()`: the Looks table and its choices), `helpers\InputOverrides` (`clean()`, `prune()`, new `merge()`), `helpers\SettingsRows` (`presets()`, `presetRows()`), `helpers\Registry` (`invalidPresetChoices()`, and it already applies `inputs` and `presets`) |

- **Storage:** `services\SettingsFile` reads and writes `config/craft/design-field.settings.json`, holding
  only `inputs` and `presets`. `Groups::config()` lays it over `design-field.php` (`Groups::fileConfig()`
  is that file as written): saved `inputs` win entry by entry, saved `presets` apply unless the config
  file has a `presets` key. A file that is not JSON is ignored. With nothing to keep the file is removed.
- **Looks:** a quick switch per style in use (`inputs.styles`), and one editable table row per option
  with its look on every block (`inputs.groups`, the choices per row from `StyleChoices`) and its
  section (`inputs.sections`). A save replaces those three parts and keeps the rest of the saved
  `inputs`; it is checked by building the registry with it, and pruned of looks for options that are gone.
- **Presets:** an editable table (block, name, choices as `group=key`). A save is refused with the
  reason when a choice is malformed or names a group or key no block has. With `presets` in the config
  file the tab is read only and the route answers 409.
- Each tab keeps what the other saved.
- **Not in this stage** (1.x had them on the Looks tab): a look for one block only ("Customize this
  block"), other labels and icons for an option's choices, and the live preview of a look before it is
  saved. The file format already carries the first two (`inputs.profiles`, `inputs.meta`), so a later
  stage can add the screens without a migration.
- **Tests:** `LooksPresetsTest`: the configuration without, with and with a broken settings file, and
  with `presets` in the config file; the Presets tab saves rows, shows them and the panel gets the
  preset; invalid choices are refused; presets in the config file show read only; the Looks tab lists
  every option; a look, a swap and a section are saved, the registry reads them and the other parts of
  the file are kept; read only and refused with admin changes off; the routes carry both middlewares.

## Not ported

Tidy up (moving old option fields into the Design field) stays on 1.x: a Craft 6 site has none. The
1.x "Start here" first run and the Groups, Options and Profiles tables are not ported either: on 2.x
they are the config file.
