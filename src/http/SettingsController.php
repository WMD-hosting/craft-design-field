<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\http;

use CraftCms\Cms\Http\RespondsWithFlash;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use wmd\designfield\helpers\InputOverrides;
use wmd\designfield\helpers\SettingsRows;
use wmd\designfield\helpers\StyleChoices;
use wmd\designfield\services\Groups;
use wmd\designfield\services\SettingsFile;

/**
 * Saves what the settings page edits to the settings file beside the config file
 * (services/SettingsFile): the Looks and Presets tabs, and the looks of one block on the
 * Preview tab. Each save keeps the parts of the file it does not edit.
 * The routes are for admins where admin changes are allowed.
 *
 * @author WMD
 * @since 2.0.0
 */
class SettingsController
{
    use RespondsWithFlash;

    public function __construct(
        private readonly Groups $groups,
        private readonly SettingsFile $file,
        private readonly SettingsScreen $screen,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * The Presets tab: its rows become the saved `presets`. Refused, with the reason
     * on the table, when a row lacks its block, name or choices, a choice is not `group=key`,
     * or it names a group or key no block has.
     *
     * @param Request $request `presets`: rows of `profile`, `name` and `choices`
     * @return Response
     * @throws ValidationException
     *
     * @author WMD
     * @since 2.0.0
     */
    public function presets(Request $request): Response
    {
        // The config file wins: saving here would change nothing an editor sees.
        abort_if(array_key_exists('presets', $this->groups->fileConfig()), 409, 'Presets are set in the config file.');

        $rows = $request->validate(['presets' => ['nullable', 'array'], 'presets.*' => ['array']])['presets'] ?? [];

        // A row with something in it is a preset someone meant: say what it lacks instead
        // of leaving it out of a save that reports success. An empty row is just skipped.
        $problems = [];
        foreach (array_values($rows) as $i => $row) {
            $filled = array_map(static fn(string $key): bool => is_scalar($row[$key] ?? null) && trim((string)$row[$key]) !== '', ['profile' => 'profile', 'name' => 'name', 'choices' => 'choices']);

            if (($filled['name'] || $filled['choices']) && in_array(false, $filled, true)) {
                $problems[] = sprintf('Row %d: a preset needs a block, a name and its choices.', $i + 1);
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['presets' => $problems]);
        }

        try {
            $presets = SettingsRows::presets($rows);
            $problems = $this->groups->registryWith(['presets' => $presets])->invalidPresetChoices();
        } catch (InvalidArgumentException $e) {
            $problems = [$e->getMessage()];
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['presets' => $problems]);
        }

        $this->file->write($this->file->read()['inputs'], $presets);

        return $this->asSuccess('Presets saved.', redirect: SettingsScreen::url('presets'));
    }

    /**
     * The Looks tab: its style swaps, option looks and sections replace the saved ones.
     * Looks of one block only, other labels and icons, changed defaults and hidden
     * choices, which this form does not hold, are kept as saved.
     *
     * @param Request $request `styles`: style => style; `looks`: option handle => `look` and `section`
     * @return Response
     * @throws ValidationException
     *
     * @author WMD
     * @since 2.0.0
     */
    public function looks(Request $request): Response
    {
        $data = $request->validate([
            'styles' => ['nullable', 'array'],
            'styles.*' => ['nullable', 'string'],
            'looks' => ['nullable', 'array'],
            'looks.*' => ['array'],
        ]);

        $saved = $this->file->read();
        $inputs = SettingsScreen::looksInputs($data, $saved['inputs']);

        // Checked the way the site will read them: over the config file's own looks.
        try {
            $inputs = InputOverrides::clean($inputs);
            $registry = $this->groups->registryWith(['inputs' => InputOverrides::merge($this->groups->fileConfig()['inputs'] ?? [], $inputs)]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['looks' => [$e->getMessage()]]);
        }

        // Looks for options and blocks that are gone go with this save.
        $this->file->write(InputOverrides::prune($inputs, $registry), $saved['presets']);

        return $this->asSuccess('Looks saved.', redirect: SettingsScreen::url('looks'));
    }

    /**
     * The Preview tab's "Customize this block": the looks picked there replace the saved
     * looks of that block only (`inputs.profiles[<block>]`). An option left "As on every
     * block" has none. Everything else in the file is kept.
     *
     * The tab posts its whole form; only the looks are read, the panel's choices are not saved.
     *
     * @param Request $request `block` in the URL (a profile name); `design._looks`: option handle => look
     * @return Response
     * @throws ValidationException
     *
     * @author WMD
     * @since 2.0.0
     */
    public function blockLooks(Request $request): Response
    {
        $block = $request->query('block');
        $path = SettingsScreen::PANEL . '.' . SettingsScreen::LOOKS;
        $data = $request->validate([$path => ['nullable', 'array'], "$path.*" => ['nullable', 'string']]);
        $picked = $data[SettingsScreen::PANEL][SettingsScreen::LOOKS] ?? [];

        try {
            $offered = is_string($block) ? $this->screen->blockLooks($this->groups->getRegistry(), $block) : [];
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        abort_if(!is_string($block) || !isset($this->groups->getRegistry()->profiles[$block]), 404, 'Unknown block.');

        // Only a look the option can take on this block: any other would be dropped when
        // the file is read, after a save that said it worked.
        $looks = [];
        $problems = [];
        foreach ($picked as $handle => $look) {
            if ($look === null || $look === '') {
                continue;
            }

            if (!in_array($look, $offered[$handle]['values'] ?? [], true)) {
                $problems["$path.$handle"] = [sprintf('%s cannot look like that on this block.', $offered[$handle]['label'] ?? $handle)];
                continue;
            }

            $looks[(string)$handle] = StyleChoices::style($look);
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $saved = $this->file->read();
        $inputs = $saved['inputs'];
        $inputs['profiles'][$block] = $looks;

        try {
            $inputs = InputOverrides::clean($inputs);
            $registry = $this->groups->registryWith(['inputs' => InputOverrides::merge($this->groups->fileConfig()['inputs'] ?? [], $inputs)]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$path => [$e->getMessage()]]);
        }

        $this->file->write(InputOverrides::prune($inputs, $registry), $saved['presets']);

        return $this->asSuccess('Looks of this block saved.', redirect: SettingsScreen::url('preview', ['block' => $block]));
    }

    /**
     * The Looks tab's labels and icons of one option: its rows replace the saved ones
     * for that option only (`inputs.meta[<option>]`); a row left empty has none.
     * Everything else in the file is kept.
     *
     * @param Request $request `option` in the URL (a group handle); `labels`: row key => `label` and `icon`
     * @return Response
     * @throws ValidationException
     *
     * @author WMD
     * @since 2.0.0
     */
    public function labels(Request $request): Response
    {
        $option = $this->screen->labelsOption($request->query('option'));
        abort_if($option === null, 404, 'Unknown option.');

        $rows = $request->validate(['labels' => ['nullable', 'array'], 'labels.*' => ['array']])['labels'] ?? [];
        ['meta' => $meta, 'problems' => $problems] = SettingsScreen::labelsMeta($rows);

        if ($problems !== []) {
            throw ValidationException::withMessages(['labels' => $problems]);
        }

        $saved = $this->file->read();
        $inputs = $saved['inputs'];
        $inputs['meta'][$option] = $meta;

        try {
            $inputs = InputOverrides::clean($inputs);
            $registry = $this->groups->registryWith(['inputs' => InputOverrides::merge($this->groups->fileConfig()['inputs'] ?? [], $inputs)]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['labels' => [$e->getMessage()]]);
        }

        // Labels for choices the option no longer has go with this save.
        $this->file->write(InputOverrides::prune($inputs, $registry), $saved['presets']);

        return $this->asSuccess('Labels and icons saved.', redirect: SettingsScreen::url('looks', ['option' => $option]));
    }
}
