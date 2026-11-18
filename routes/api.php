<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

// Outside the web middleware group: no session, no CSRF token, no redirects (Pixelforge doesn't follow them).
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
