<?php

namespace App\Core\Files;

use RuntimeException;

/**
 * CSV for people to open in a spreadsheet. Spreadsheet apps run a cell starting with =, +, -, @, a tab or a carriage
 * return as a formula, so such cells get a leading apostrophe (OWASP's advice against CSV injection).
 */
final class SafeCsv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|bool|null>>  $rows
     */
    public static function make(array $header, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the CSV.');
        }

        self::write($handle, $header, $rows);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Write the CSV to an open stream, row by row, e.g. php://output for a download.
     *
     * @param  resource  $handle
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|bool|null>>  $rows
     */
    public static function write($handle, array $header, iterable $rows): void
    {
        fputcsv($handle, array_map(self::cell(...), $header), escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), escape: '');
        }
    }

    public static function cell(string|int|float|bool|null $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
