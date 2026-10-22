<?php

namespace App\Services\Accounting;

use App\Enums\DocumentType;
use App\Models\DocumentSequence;
use DomainException;

class DocumentNumberService
{
    public function next(
        int $storeId,
        int $branchId,
        int $fiscalYearId,
        DocumentType $documentType,
    ): string {
        $sequence = DocumentSequence::query()
            ->for($storeId, $branchId, $fiscalYearId, $documentType)
            ->first();

        if (! $sequence) {
            throw new DomainException('شماره‌گذاری این نوع سند برای سال و شعبه انتخاب‌شده تعریف نشده است.');
        }

        return $sequence->allocate();
    }
}
