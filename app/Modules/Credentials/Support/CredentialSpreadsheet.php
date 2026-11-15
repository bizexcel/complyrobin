<?php

namespace App\Modules\Credentials\Support;

use App\Core\Files\SafeCsv;
use App\Core\People\Models\Person;
use App\Modules\Credentials\Models\Application;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Reads a spreadsheet (CSV) of credentials into what CreateCredential takes. Applications are matched by name and
 * owners by email, both within the current organisation only.
 */
final class CredentialSpreadsheet
{
    public const int MAX_ROWS = 1000;

    public const int MAX_BYTES = 1024 * 1024;

    /** @var list<string> */
    public const array COLUMNS = [
        'name', 'application', 'type', 'environment', 'owner_email', 'backup_owner_email',
        'rotation_days', 'last_rotated_on', 'expires_on', 'used_in', 'hint',
    ];

    /** @var list<string> */
    public const array REQUIRED_COLUMNS = ['name', 'application'];

    /**
     * An empty spreadsheet with the columns, to fill in.
     */
    public static function template(): string
    {
        return SafeCsv::make(self::COLUMNS, [[
            'Stripe live secret key', 'Stripe', 'api_token', 'production', 'siti@example.com', 'ahmad@example.com',
            '90', '2026-01-15', '', 'Laravel Cloud production: STRIPE_SECRET', 'sk_l',
        ]]);
    }

    /**
     * The data rows, keyed by column, each under its row number in the spreadsheet (the header is row 1). Refuses a file that is too big, has no
     * rows or too many, or lacks a required column.
     *
     * @return array<int, array<string, string>>
     *
     * @throws ValidationException
     */
    public static function rows(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'The file is bigger than 1 MB. Split it into smaller files.']);
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the spreadsheet.');
        }

        fwrite($handle, Str::of($csv)->replaceStart("\xEF\xBB\xBF", '')->toString());
        rewind($handle);

        $header = array_map(
            fn (?string $column): string => Str::of((string) $column)->trim()->lower()->replace(' ', '_')->toString(),
            fgetcsv($handle, escape: '') ?: [],
        );

        if (array_diff(self::REQUIRED_COLUMNS, $header) !== []) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => 'The first row must name the columns, including name and application. Download the template to see them all.']);
        }

        $rows = [];
        $rowNumber = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $rowNumber++;

            if (count($rows) > self::MAX_ROWS) {
                break;
            }

            if ($cells === [null] || implode('', array_map(strval(...), $cells)) === '') {
                continue;
            }

            $cells = array_map(strval(...), array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $rows[$rowNumber] = array_intersect_key(array_combine($header, $cells), array_flip(self::COLUMNS));
        }

        fclose($handle);

        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'A file can hold 1 to '.self::MAX_ROWS.' credentials.']);
        }

        return $rows;
    }

    /**
     * One row as CreateCredential's data. Blank optional cells are left out so defaults apply.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function toCredentialData(array $row): array
    {
        $cell = fn (string $column): ?string => filled($row[$column] ?? null) ? trim($row[$column]) : null;

        $application = $cell('application') === null ? null : Application::query()->whereRaw('lower(name) = ?', [Str::lower($cell('application'))])->first();

        if (! $application instanceof Application) {
            throw ValidationException::withMessages(['application' => "No application called \"{$cell('application')}\". Add it first, then import again."]);
        }

        return array_filter([
            'application_id' => $application->getKey(),
            'name' => $cell('name'),
            'type' => Str::of($cell('type') ?? 'other')->lower()->replace(' ', '_')->toString(),
            'environment' => Str::lower($cell('environment') ?? 'production'),
            'owner_id' => self::personByEmail($cell('owner_email'), 'owner_email'),
            'backup_owner_id' => self::personByEmail($cell('backup_owner_email'), 'backup_owner_email'),
            'rotation_days' => $cell('rotation_days'),
            'last_rotated_at' => $cell('last_rotated_on'),
            'expires_at' => $cell('expires_on'),
            'used_in' => $cell('used_in'),
            'hint' => $cell('hint'),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @throws ValidationException
     */
    private static function personByEmail(?string $email, string $column): ?string
    {
        if ($email === null) {
            return null;
        }

        $person = Person::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();

        if (! $person instanceof Person) {
            throw ValidationException::withMessages([$column => "No one in People has the email {$email}."]);
        }

        return $person->getKey();
    }
}
