<?php

use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\RazorpayWebhookController;
use Illuminate\Support\Facades\Route;

// Loaded outside the "web" group (bootstrap/app.php): no session, cookies or CSRF.
Route::get('webhooks/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('webhooks/meta', [MetaWebhookController::class, 'receive'])->name('webhooks.meta.receive');
Route::post('webhooks/razorpay', RazorpayWebhookController::class)->middleware('throttle:120,1')->name('webhooks.razorpay'); // PAYMENTS_ENABLED
