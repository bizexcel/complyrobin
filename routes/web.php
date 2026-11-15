<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\CalendarFeedController;
use Illuminate\Support\Facades\Route;

Route::get('/invitations/{organisation}/{token}', AcceptInvitationController::class)
    ->middleware('throttle:20,1')
    ->name('invitations.accept');

// Fetched by calendar apps: no session, cookies or CSRF.
Route::get('/calendar/{organisation}/{token}.ics', CalendarFeedController::class)
    ->where('token', '[A-Za-z0-9]{64}')
    ->withoutMiddleware('web')
    ->middleware('throttle:calendar-feed')
    ->name('calendar.feed');
