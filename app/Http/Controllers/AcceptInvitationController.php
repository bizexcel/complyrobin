<?php

namespace App\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\AcceptInvitation;
use App\Core\Organisations\Exceptions\InvalidInvitation;
use App\Core\Organisations\Models\Organisation;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The link in an invitation email. Guests sign in (or sign up) first and come back here.
 */
class AcceptInvitationController extends Controller
{
    public function __invoke(Request $request, AcceptInvitation $acceptInvitation, string $organisation, string $token): RedirectResponse
    {
        $user = $request->user();
        $panel = Filament::getPanel('app');

        if (! $user instanceof User) {
            return redirect()->guest($panel->getLoginUrl());
        }

        $tenant = Organisation::query()->where('slug', $organisation)->first();
        abort_if($tenant === null, 404);

        try {
            $acceptInvitation->handle($user, $tenant, $token);
        } catch (InvalidInvitation) {
            abort(404);
        }

        return redirect($panel->getUrl($tenant));
    }
}
