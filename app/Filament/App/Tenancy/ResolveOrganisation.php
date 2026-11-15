<?php

namespace App\Filament\App\Tenancy;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use Filament\Facades\Filament;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Finds the organisation named by the first URL segment. A renamed organisation's old slug redirects members to the
 * current one; anyone else gets the same 404 as for a slug that never existed, so the URL confirms nothing.
 */
final readonly class ResolveOrganisation
{
    public function __construct(private Request $request) {}

    public function __invoke(string $slug): Organisation
    {
        $organisation = Organisation::query()->where('slug', $slug)->first();

        if ($organisation instanceof Organisation) {
            return $organisation;
        }

        $renamed = Organisation::query()->whereJsonContains('previous_slugs', $slug)->first();
        $user = Filament::auth()->user();

        if ($renamed instanceof Organisation && $user instanceof User && $user->canAccessTenant($renamed)
            && $this->request->isMethodSafe()) {
            throw new HttpResponseException(redirect(Str::replaceFirst("/{$slug}", "/{$renamed->slug}", $this->request->getRequestUri())));
        }

        abort(404);
    }
}
