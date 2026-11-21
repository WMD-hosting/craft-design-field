<?php
declare(strict_types=1);

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\Cms\Http\Middleware\RequireAdminChanges;
use Illuminate\Support\Facades\Route;
use wmd\designfield\http\PreviewController;
use wmd\designfield\http\SettingsController;

// The settings page itself is Craft's own route for plugin settings (settings/plugins/design-field).
// These are what its tabs call; like the page, they are for admins. What writes a site file
// (the settings file beside the config file, a tile picture) needs admin changes to be allowed.
Route::middleware(RequireAdmin::class)->prefix('design-field')->group(function(): void {
    // The Preview tab's Ui, built again for the choices its panel holds
    Route::post('preview/ui', [PreviewController::class, 'ui']);

    // The Looks tab's Ui, built again with the looks its form holds ("Try these looks")
    Route::post('looks/ui', [PreviewController::class, 'looksUi']);

    // Make tile pictures: a layout's picture, drawn in the browser, is written under the web root.
    // The pictures are site files that belong in the repository, so admin changes must be allowed.
    Route::post('tiles/save', [PreviewController::class, 'saveTile'])->middleware(RequireAdminChanges::class);

    // The Looks and Presets tabs, and the looks of one block on the Preview tab, save to
    // config/craft/design-field.settings.json
    Route::middleware(RequireAdminChanges::class)->prefix('settings')->group(function(): void {
        Route::post('looks', [SettingsController::class, 'looks']);
        Route::post('block-looks', [SettingsController::class, 'blockLooks']);
        Route::post('labels', [SettingsController::class, 'labels']);
        Route::post('presets', [SettingsController::class, 'presets']);
    });
});
