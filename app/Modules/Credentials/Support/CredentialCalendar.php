<?php

namespace App\Modules\Credentials\Support;

use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Carbon\CarbonInterface;
use Closure;

/**
 * Writes credentials' due dates as an iCalendar feed (RFC 5545): one all-day event per credential on its due date in
 * the organisation's time zone. Only what a calendar should hold goes in: never the hint, where it is used or notes.
 */
final class CredentialCalendar
{
    private const int MAX_LINE_OCTETS = 75;

    /**
     * @param  iterable<Credential>  $credentials
     * @param  Closure(Credential): string  $link
     */
    public static function render(string $name, string $timezone, iterable $credentials, Closure $link, CarbonInterface $now): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Bizexcel//ComplyRobin//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text("ComplyRobin: {$name}"),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        foreach ($credentials as $credential) {
            if ($credential->next_rotation_at === null) {
                continue;
            }

            $dueOn = $credential->next_rotation_at->copy()->setTimezone($timezone)->startOfDay();
            $application = $credential->application;
            $url = $link($credential);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:credential-{$credential->getKey()}@complyrobin.com";
            $lines[] = 'DTSTAMP:'.$now->copy()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART;VALUE=DATE:'.$dueOn->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$dueOn->copy()->addDay()->format('Ymd');
            $lines[] = 'SUMMARY:'.self::text("Rotate {$credential->name} ({$credential->reference})");
            $lines[] = 'DESCRIPTION:'.self::text(($application instanceof Application ? "{$application->name}\n" : '')."Record the rotation in ComplyRobin: {$url}");
            $lines[] = 'URL:'.$url;
            $lines[] = 'TRANSP:TRANSPARENT';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * Escape a TEXT value: backslashes, semicolons, commas and line breaks.
     */
    private static function text(string $value): string
    {
        return (string) preg_replace('/\r\n|\r|\n/', '\n', addcslashes($value, '\;,'));
    }

    /**
     * Fold a line longer than 75 octets onto continuation lines that start with a space, never inside a character.
     */
    private static function fold(string $line): string
    {
        $parts = [];
        $limit = self::MAX_LINE_OCTETS;

        while (strlen($line) > $limit) {
            $part = mb_strcut($line, 0, $limit, 'UTF-8');
            $parts[] = $part;
            $line = substr($line, strlen($part));
            $limit = self::MAX_LINE_OCTETS - 1;
        }

        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
