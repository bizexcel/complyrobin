<?php

namespace App\Http\Controllers;

use App\Http\RunnerRequests;
use App\Modules\Credentials\Actions\ClaimRotationRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A rotation runner checking in for the credentials it should rotate now. Anything wrong with the organisation or
 * the token is a plain 401.
 */
class RunnerClaimsController extends Controller
{
    public function __invoke(Request $request, ClaimRotationRuns $claimRotationRuns, string $organisation): JsonResponse
    {
        $tenant = RunnerRequests::organisation($organisation);
        $token = (string) $request->bearerToken();
        $claim = $tenant === null || $token === '' ? null : $claimRotationRuns->handle($tenant, $token);

        return $claim === null ? RunnerRequests::unauthenticated() : RunnerRequests::json($claim);
    }
}
