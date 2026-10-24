<?php

namespace App\Services\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExportService
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    public function download(
        string $filename,
        array $headers,
        iterable $rows,
    ): StreamedResponse {
        $filename = $this->filename($filename);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            $this->writeRow($output, $headers);

            foreach ($rows as $row) {
                $this->writeRow($output, $row);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * @param  resource  $output
     * @param  array<int, string|int|float|null>  $row
     */
    private function writeRow($output, array $row): void
    {
        fputcsv(
            $output,
            array_map(fn (mixed $value): string => $this->safe($value), $row),
            ';',
            '"',
            '',
        );
    }

    private function safe(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = (string) $value;

        if (
            ! is_numeric($text)
            && preg_match('/^\s*[=+\-@\t\r]/u', $text) === 1
        ) {
            return "'".$text;
        }

        return $text;
    }

    private function filename(string $filename): string
    {
        $filename = str_replace(["\r", "\n", "\0", '/', '\\'], '-', $filename);

        return preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $filename)
            ?: 'export.csv';
    }
}
