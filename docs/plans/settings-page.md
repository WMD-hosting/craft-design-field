# The settings page on Craft 6

The 1.x settings page (Settings → Plugins → Design Field) comes to 2.x in stages. Each stage is one pull
request into `2.x` that can be released on its own. The config file `config/craft/design-field.php` stays
the whole configuration: nothing on the page saves a setting until stage 4 is decided.

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
| Screen | a button on the Preview tab, shown when the block has layouts, `blockPreviewUrl` and `tileImages` are set and admin changes are allowed |
| Routes | `routes/cp.php`: `POST design-field/tiles/save` |
| Permissions | admin and `RequireAdminChanges` (the pictures are site files that belong in the repository) |
| Reused | `services\Groups` (`tilePath()`, `tileImage()`), `helpers\Registry` (the block's layout list), `modern-screenshot` as on 1.x (MIT, shipped with the build) |

- Each layout without a picture loads in a hidden frame through `blockPreviewUrl`, is drawn to a 480 px
  JPEG in the browser and posted to the action, which checks block, layout, size and type (480 px wide
  JPEG, under 2 MB) and writes it to `tileImages` under the web root. With none missing it offers to make
  them all again. `tileSkip` (a CSS selector) leaves things out of the picture.
- **Tests:** `TilesTest`: a valid picture is written to the tile path; an unknown layout, another image
  type, a wrong width and an oversized body are refused; refused with admin changes off and for a
  non-admin.

## Stage 4: Looks and Presets (not started: needs a decision)

On 1.x the Looks and Presets tabs saved to project config. On 2.x the config file is the whole
configuration, so where such edits would be stored has to be decided before anything is built.

## Not ported

Tidy up (moving old option fields into the Design field) stays on 1.x: a Craft 6 site has none. The
1.x "Start here" first run and the Groups, Options and Profiles tables are not ported either: on 2.x
they are the config file.
