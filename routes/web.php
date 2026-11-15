<?php

use App\Http\Controllers\AcceptInvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/invitations/{organisation}/{token}', AcceptInvitationController::class)
    ->middleware('throttle:20,1')
    ->name('invitations.accept');
