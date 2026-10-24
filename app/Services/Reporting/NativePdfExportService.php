<?php

namespace App\Services\Reporting;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

class NativePdfExportService
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    public function download(
        string $filename,
        string $title,
        array $headers,
        iterable $rows,
    ): BinaryFileResponse {
        $html = $this->html($title, $headers, $rows);
        $htmlFile = tempnam(sys_get_temp_dir(), 'hsabyar-pdf-html-');
        $pdfFile = tempnam(sys_get_temp_dir(), 'hsabyar-pdf-');

        if ($htmlFile === false || $pdfFile === false) {
            throw new RuntimeException('Cannot create PDF temporary files.');
        }

        file_put_contents($htmlFile, $html);
        $binary = (string) config('accounting.pdf_renderer_binary', '/usr/bin/google-chrome');

        if (! is_executable($binary)) {
            throw new RuntimeException('Configured PDF renderer is not executable.');
        }

        try {
            (new Process([
                $binary,
                '--headless',
                '--disable-gpu',
                '--no-sandbox',
                '--print-to-pdf='.$pdfFile,
                '--no-pdf-header-footer',
                'file://'.$htmlFile,
            ]))->setTimeout(60)->mustRun();
        } finally {
            @unlink($htmlFile);
        }

        $safeName = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $filename) ?: 'export.pdf';

        if (! str_ends_with(mb_strtolower($safeName), '.pdf')) {
            $safeName .= '.pdf';
        }

        return response()
            ->download($pdfFile, $safeName, [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'no-store, no-cache',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    private function html(
        string $title,
        array $headers,
        iterable $rows,
    ): string {
        $head = implode('', array_map(
            fn (string $header): string => '<th>'.htmlspecialchars($header).'</th>',
            $headers,
        ));
        $body = '';

        foreach ($rows as $row) {
            $body .= '<tr>';

            foreach ($row as $value) {
                $body .= '<td>'.htmlspecialchars((string) ($value ?? '')).'</td>';
            }

            $body .= '</tr>';
        }

        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            .'<style>@page{size:A4 landscape;margin:10mm}body{font-family:Tahoma,Arial,sans-serif;font-size:9px;color:#111}h1{font-size:18px;margin:0 0 8mm}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:3px 4px;text-align:right}th{background:#eee;font-weight:bold}tr{page-break-inside:avoid}</style>'
            .'</head><body><h1>'.htmlspecialchars($title).'</h1><table><thead><tr>'
            .$head.'</tr></thead><tbody>'.$body.'</tbody></table></body></html>';
    }
}
