<?php
declare(strict_types=1);

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;
use wmd\designfield\http\PreviewController;

// The settings page itself is Craft's own route for plugin settings (settings/plugins/design-field).
// These are what its Preview tab calls; like the page, they are for admins and save nothing.
Route::middleware(RequireAdmin::class)->prefix('design-field')->group(function(): void {
    // The Preview tab's Ui, built again for the choices its panel holds
    Route::post('preview/ui', [PreviewController::class, 'ui']);
});
