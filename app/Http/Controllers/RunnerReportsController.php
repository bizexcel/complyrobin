<?php

namespace App\Http\Controllers;

use App\Http\RunnerRequests;
use App\Modules\Credentials\Actions\ReportRotationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A rotation runner reporting how a run went. Anything wrong with the organisation or the token is a plain 401; a
 * report that fails validation is a 422 with the errors by field.
 */
class RunnerReportsController extends Controller
{
    public function __invoke(Request $request, ReportRotationRun $reportRotationRun, string $organisation): JsonResponse
    {
        $tenant = RunnerRequests::organisation($organisation);
        $token = (string) $request->bearerToken();
        $outcome = $tenant === null || $token === '' ? null : $reportRotationRun->handle($tenant, $token, $request->json()->all());

        return $outcome === null ? RunnerRequests::unauthenticated() : RunnerRequests::json($outcome['body'], $outcome['status']);
    }
}
