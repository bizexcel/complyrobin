<?php

namespace App\Http;

use App\Core\Organisations\Models\Organisation;
use Illuminate\Http\JsonResponse;

/**
 * What the runner endpoints share: finding the organisation by its slug (a previous one too, so runners keep working
 * after a rename) and answering without anything a cache or a browser should keep.
 */
final class RunnerRequests
{
    public static function organisation(string $slug): ?Organisation
    {
        return Organisation::query()
            ->where(fn ($query) => $query->where('slug', $slug)->orWhereJsonContains('previous_slugs', $slug))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store, private']);
    }

    public static function unauthenticated(): JsonResponse
    {
        return self::json(['message' => 'Unauthenticated.'], 401);
    }
}
