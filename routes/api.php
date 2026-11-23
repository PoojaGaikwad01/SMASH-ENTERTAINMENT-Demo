<?php

use App\Http\Controllers\PublicEnquiryController;
use Illuminate\Support\Facades\Route;

// Prefix /api, no session/CSRF. CORS: config/cors.php (WEBSITE_ALLOWED_ORIGINS).
Route::post('enquiries', [PublicEnquiryController::class, 'api'])
    ->middleware('throttle:public-enquiry')
    ->name('api.enquiries.store');
