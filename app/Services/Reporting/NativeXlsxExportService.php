<?php

namespace App\Services\Reporting;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class NativeXlsxExportService
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    public function download(
        string $filename,
        string $sheetName,
        array $headers,
        iterable $rows,
    ): BinaryFileResponse {
        $file = tempnam(sys_get_temp_dir(), 'hsabyar-xlsx-');
        $sheetFile = tempnam(sys_get_temp_dir(), 'hsabyar-xlsx-sheet-');

        if ($file === false || $sheetFile === false) {
            throw new RuntimeException('Cannot create XLSX temporary files.');
        }

        $zip = new ZipArchive;
        $zipOpen = $zip->open($file, ZipArchive::OVERWRITE) === true;
        $stream = $zipOpen ? fopen($sheetFile, 'wb') : false;
        $response = null;

        try {
            if (! $zipOpen || $stream === false) {
                throw new RuntimeException('Cannot open XLSX archive or worksheet stream.');
            }

            $this->write($stream, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                .'<sheetData>');
            $this->writeRow($stream, 1, $headers, true);
            $rowNumber = 2;

            foreach ($rows as $row) {
                $this->writeRow($stream, $rowNumber, array_values($row));
                $rowNumber++;
            }

            $this->write($stream, '</sheetData></worksheet>');
            fclose($stream);
            $stream = null;

            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelations());
            $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelations());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');
            $zip->close();
            $zipOpen = false;

            $safeName = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $filename) ?: 'export.xlsx';

            if (! str_ends_with(mb_strtolower($safeName), '.xlsx')) {
                $safeName .= '.xlsx';
            }

            $response = response()
                ->download($file, $safeName, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' => 'no-store, no-cache',
                ])
                ->deleteFileAfterSend(true);

            return $response;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($zipOpen) {
                $zip->close();
            }

            @unlink($sheetFile);

            if ($response === null) {
                @unlink($file);
            }
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<mixed>  $row
     */
    private function writeRow($stream, int $rowNumber, array $row, bool $header = false): void
    {
        $cells = [];

        foreach ($row as $columnIndex => $value) {
            $reference = $this->column($columnIndex + 1).$rowNumber;
            $isNumber = is_int($value)
                || is_float($value)
                || (is_string($value) && is_numeric($value));
            $style = $header ? ' s="1"' : '';

            if ($isNumber) {
                $cells[] = sprintf(
                    '<c r="%s"%s><v>%s</v></c>',
                    $reference,
                    $style,
                    htmlspecialchars((string) $value, ENT_XML1),
                );
            } else {
                $cells[] = sprintf(
                    '<c r="%s" t="inlineStr"%s><is><t xml:space="preserve">%s</t></is></c>',
                    $reference,
                    $style,
                    htmlspecialchars((string) ($value ?? ''), ENT_XML1),
                );
            }
        }

        $this->write($stream, '<row r="'.$rowNumber.'">'.implode('', $cells).'</row>');
    }

    /** @param resource $stream */
    private function write($stream, string $value): void
    {
        if (fwrite($stream, $value) === false) {
            throw new RuntimeException('Cannot write XLSX worksheet stream.');
        }
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(string $sheetName): string
    {
        $name = mb_substr($sheetName, 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></patternFill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>';
    }

    private function column(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }
}
