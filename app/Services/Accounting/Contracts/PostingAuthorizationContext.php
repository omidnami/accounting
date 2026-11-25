<?php

namespace App\Services\Accounting\Contracts;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Receipt;
use App\Models\User;

interface PostingAuthorizationContext
{
    public function allows(JournalEntry $entry, User $user): bool;

    public function immediateAmount(Invoice $invoice): string;

    public function prepareTreasuryDocument(Receipt $receipt, User $user, ?int $tenderId = null): void;
}
