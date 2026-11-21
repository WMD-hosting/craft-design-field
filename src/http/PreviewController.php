<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\http;

use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\Ui\UiResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * What the settings page's Preview tab asks the server for. Nothing here saves.
 *
 * @author WMD
 * @since 2.0.0
 */
class PreviewController
{
    public function __construct(
        private readonly SettingsScreen $screen,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * The Preview tab's Ui built again for the choices the panel holds, the way an
     * element's form refreshes it: options that wait for another one (`showIf`) follow.
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
}
