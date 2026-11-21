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
 * Saves what the settings page's Looks and Presets tabs edit to the settings file
 * beside the config file (services/SettingsFile). Each tab keeps what the other saved.
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
     * choices, which this tab does not show, are kept as saved.
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
        $inputs = ['styles' => [], 'groups' => [], 'sections' => []] + $saved['inputs'];

        foreach ($data['styles'] ?? [] as $from => $to) {
            if (is_string($to) && $to !== '') {
                $inputs['styles'][(string)$from] = $to;
            }
        }

        foreach ($data['looks'] ?? [] as $handle => $row) {
            $look = is_string($row['look'] ?? null) ? $row['look'] : '';
            $section = is_string($row['section'] ?? null) ? trim($row['section']) : '';

            if (isset(StyleChoices::LABELS[$look])) {
                $inputs['groups'][(string)$handle] = StyleChoices::style($look);
            }
            if ($section !== '') {
                $inputs['sections'][(string)$handle] = $section;
            }
        }

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
}
