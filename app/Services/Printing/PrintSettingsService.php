<?php

namespace App\Services\Printing;

use App\Enums\InvoicePrintFormat;
use App\Models\Branch;

class PrintSettingsService
{
    /**
     * @return array{
     *     format: string,
     *     paper_width_mm: int,
     *     copies: int,
     *     header_note: string|null
     * }
     */
    public function forBranch(Branch $branch): array
    {
        $paperWidth = (int) ($branch->thermal_paper_width ?? 80);
        $format = InvoicePrintFormat::tryFrom((string) $branch->invoice_print_format)
            ?? ($paperWidth === 58
                ? InvoicePrintFormat::Thermal58
                : InvoicePrintFormat::Thermal80);

        return [
            'format' => $format->value,
            'paper_width_mm' => $format->isThermal()
                ? $format->thermalPaperWidth()
                : $paperWidth,
            'copies' => (int) ($branch->thermal_copies ?? 1),
            'header_note' => $branch->invoice_header_note,
        ];
    }

    /**
     * @param  array{
     *     format: InvoicePrintFormat,
     *     copies: int,
     *     header_note: string|null
     * }  $settings
     */
    public function update(Branch $branch, array $settings): void
    {
        $branch->update([
            'invoice_print_format' => $settings['format']->value,
            'thermal_paper_width' => $settings['format']->isThermal()
                ? $settings['format']->thermalPaperWidth()
                : (int) ($branch->thermal_paper_width ?? 80),
            'thermal_copies' => $settings['copies'],
            'invoice_header_note' => $settings['header_note'],
        ]);
    }
}
