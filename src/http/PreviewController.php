<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\http;

use CraftCms\Cms\Support\File;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\Ui\UiResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use wmd\designfield\services\Groups;

/**
 * What the settings page's Preview and Looks tabs ask the server for besides a save. Only saveTile() writes:
 * a picture file, never a setting or an element.
 *
 * @author WMD
 * @since 2.0.0
 */
class PreviewController
{
    // Const Properties
    // =========================================================================

    /**
     * Tile width in pixels.
     */
    public const TILE_WIDTH = 480;

    /**
     * Largest accepted picture, in bytes.
     */
    public const TILE_MAX_BYTES = 2_000_000;

    public function __construct(
        private readonly SettingsScreen $screen,
        private readonly Groups $groups,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * The Preview tab's Ui built again for the choices the panel holds, the way an
     * element's form refreshes it: options that wait for another one (`showIf`) follow,
     * and the panel is built with the looks picked for this block ("Customize this
     * block"), saved or not. Nothing is saved here.
     *
     * The Ui page posts the values of the scope that changed: the panel's own map
     * (`scope` is its path), or every value of the tab (`scope` is empty). The block is
     * in the URL, as on the page.
     *
     * @param Request $request
     * @return JsonResponse
     *
     * @author WMD
     * @since 2.0.0
     */
    public function ui(Request $request): JsonResponse
    {
        $data = $request->validate([
            'values' => ['present', 'array'],
            'scope' => ['present', 'array'],
            'scope.*' => ['string'],
        ]);

        $design = $data['scope'] === [] ? ($data['values'][SettingsScreen::PANEL] ?? []) : $data['values'];
        $ui = $this->screen->previewUi($request->query('block'), is_array($design) ? $design : []);

        abort_if($ui === null, 422, 'The configuration does not build.');

        try {
            $payload = app(UiResolver::class)->resolve($ui, new UiContext(refreshable: true))->forScope($data['scope']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return new JsonResponse(['ui' => $payload]);
    }

    /**
     * The Looks tab's Ui built again for the looks its form holds ("Try these looks"):
     * the block picked there shows with them before they are saved. With `option` in the
     * URL it is that option's labels and icons instead. Nothing is saved here.
     *
     * @param Request $request `values`: the form's; `scope`: empty, the whole tab
     * @return JsonResponse
     *
     * @author WMD
     * @since 2.0.0
     */
    public function looksUi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'values' => ['present', 'array'],
            'scope' => ['present', 'array', 'max:0'],
        ]);

        $option = $this->screen->labelsOption($request->query('option'));
        $form = $option !== null ? $this->screen->labelsForm($option, $data['values']) : $this->screen->looksForm($data['values']);

        abort_if($form === null, 422, 'The configuration does not build with these looks.');

        try {
            $payload = app(UiResolver::class)->resolve(Ui::make($form['nodes']), new UiContext(values: $form['values'], refreshable: true))->forScope([]);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return new JsonResponse(['ui' => $payload]);
    }

    /**
     * Saves a layout's picture, drawn in the browser from the block's preview page
     * ("Make tile pictures"), to `tileImages` under the web root.
     *
     * Refused unless the block has that layout and the picture is a 480 px wide JPEG
     * under 2 MB: the path is built from the block's type and the layout key only.
     *
     * @param Request $request `block` (a profile name), `key` (a layout of it), `image` (a JPEG data URL)
     * @return JsonResponse The picture's URL
     *
     * @author WMD
     * @since 2.0.0
     */
    public function saveTile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'block' => ['required', 'string'],
            'key' => ['required', 'string'],
            'image' => ['required', 'string'],
        ]);

        try {
            $layouts = $this->groups->getRegistry()->profiles[$data['block']]['variant'] ?? null;
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $type = SettingsScreen::blockType($data['block']);
        $path = $this->groups->tilePath($type, $data['key']);

        abort_if($layouts === null || !isset($layouts->options[$data['key']]) || $path === null, 422, sprintf('Unknown layout %s / %s.', $data['block'], $data['key']));

        $prefix = 'data:image/jpeg;base64,';
        $jpeg = str_starts_with($data['image'], $prefix) ? base64_decode(substr($data['image'], strlen($prefix)), true) : false;
        $size = is_string($jpeg) && strlen($jpeg) <= self::TILE_MAX_BYTES && str_starts_with($jpeg, "\xFF\xD8\xFF") ? @getimagesizefromstring($jpeg) : false;

        abort_if($size === false || $size[0] !== self::TILE_WIDTH || $size[2] !== IMAGETYPE_JPEG, 422, 'The picture must be a 480 px wide JPEG under 2 MB.');

        $file = public_path(ltrim($path, '/'));
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $jpeg);

        return new JsonResponse(['url' => $this->groups->tileImage($type, $data['key'])]);
    }
}
