<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report downloads as CSV, streamed row by row so a long period does not
 * build the file in memory. A byte-order mark makes Excel read UTF-8 (৳,
 * Bangla names), and text that a spreadsheet would run as a formula is
 * prefixed with a quote.
 */
class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A filename with the period in it, e.g. sales-2026-11-01-to-2026-11-30.csv. */
    public static function filename(string $name, ?string $from, ?string $until): string
    {
        // A date picker's state may carry a time ("2026-11-01 00:00:00").
        $from = $from ? substr($from, 0, 10) : null;
        $until = $until ? substr($until, 0, 10) : null;

        return $name.($from ? '-from-'.$from : '').($until ? '-to-'.$until : '').'.csv';
    }

    private static function cell(string|int|float|bool|null $value): string|int|float|null
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value) && $value !== '' && ! is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
