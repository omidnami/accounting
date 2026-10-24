<?php

namespace App\Services\Printing;

use App\Models\Branch;

class PrintSettingsService
{
    /**
     * @return array{paper_width_mm: int, copies: int}
     */
    public function forBranch(Branch $branch): array
    {
        return [
            'paper_width_mm' => (int) ($branch->thermal_paper_width ?? 80),
            'copies' => (int) ($branch->thermal_copies ?? 1),
        ];
    }

    /**
     * @param  array{paper_width_mm: int, copies: int}  $settings
     */
    public function update(Branch $branch, array $settings): void
    {
        $branch->update([
            'thermal_paper_width' => $settings['paper_width_mm'],
            'thermal_copies' => $settings['copies'],
        ]);
    }
}
