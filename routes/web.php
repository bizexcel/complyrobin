<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\RunnerClaimsController;
use App\Http\Controllers\RunnerReportsController;
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

// Rotation runners: the organisation's own programs, authenticated by a bearer token. No session, cookies or CSRF.
// The address comes from config('app.api'): api.complyrobin.com/v1/… or the app's own /api/v1/….
$api = Route::prefix((string) config('app.api.prefix'))
    ->withoutMiddleware('web')
    ->middleware('throttle:runner-api')
    ->where(['organisation' => '[a-z0-9-]{3,40}']);

if (is_string(config('app.api.domain'))) {
    $api->domain(config('app.api.domain'));
}

$api->group(function (): void {
    Route::post('/runner/{organisation}/claims', RunnerClaimsController::class)->name('runner.claims');
    Route::post('/runner/{organisation}/reports', RunnerReportsController::class)->name('runner.reports');
});
