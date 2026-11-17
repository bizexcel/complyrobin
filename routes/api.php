<?php

use App\Http\Controllers\EnquiryController;
use Illuminate\Support\Facades\Route;

// The www contact form. Laravel's api group: no session, cookies or CSRF. CORS is limited to www in config/cors.php.
Route::post('/enquiries', EnquiryController::class)
    ->middleware('throttle:enquiries')
    ->name('enquiries.store');
