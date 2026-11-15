<?php

namespace App\Filament\Admin\Middleware;

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff work across organisations: each staff-panel request runs inside a tenancy bypass, on the platform connection.
 * Bypass is allowed only while a signed-in staff member's request runs, and the permission ends with the request.
 */
final class RunAsPlatform
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User && $user->platform_role instanceof PlatformRole, 403);

        Tenancy::allowBypassUsing(fn (): bool => true);

        try {
            return Tenancy::bypass("staff panel: {$request->method()} {$request->path()}", fn (): Response => $next($request));
        } finally {
            Tenancy::allowBypassUsing(null);
        }
    }
}
