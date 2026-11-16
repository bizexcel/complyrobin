<?php

namespace App\Filament\App\Tenancy;

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the request as the organisation Filament identified from the URL (after checking membership), so model scopes
 * and row-level security both apply. Registered as persistent tenant middleware, so Livewire updates run it too. The
 * organisation stays set until the request ends, because Livewire handles the component after this middleware returns.
 */
final class SetCurrentOrganisation
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organisation = Filament::getTenant();

        if (! $organisation instanceof Organisation) {
            return $next($request);
        }

        Tenancy::setUntilTheRequestEnds($organisation);

        return $next($request);
    }
}
