<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

// Called by Pixelforge, authenticated by its HMAC signature. The api group has
// no session and no CSRF check, which is what a server-to-server call needs.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
