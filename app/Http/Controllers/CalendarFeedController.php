<?php

namespace App\Http\Controllers;

use App\Core\Organisations\Models\Organisation;
use App\Modules\Credentials\Actions\RenderCalendarFeed;
use Illuminate\Http\Response;

/**
 * A member's private calendar link, fetched by calendar apps without a session. Anything wrong with the link is a
 * plain 404. Links keep working after the organisation's slug is renamed.
 */
class CalendarFeedController extends Controller
{
    public function __invoke(RenderCalendarFeed $renderCalendarFeed, string $organisation, string $token): Response
    {
        $tenant = Organisation::query()
            ->where(fn ($query) => $query->where('slug', $organisation)->orWhereJsonContains('previous_slugs', $organisation))
            ->first();
        abort_unless($tenant instanceof Organisation, 404);

        $calendar = $renderCalendarFeed->handle($tenant, $token);
        abort_if($calendar === null, 404);

        return response($calendar, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="complyrobin.ics"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
