<?php

namespace App\Services\Treasury;

use App\Enums\CheckType;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Branch;
use App\Models\Check;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Parties\PartySnapshotService;
use App\Services\Purchasing\SupplierPayableService;
use App\Services\Sales\CustomerReceivableService;
use App\Support\Decimal;
use App\Support\IdempotencyHasher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CheckRegistrationService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CustomerReceivableService $receivables,
        private readonly SupplierPayableService $payables,
        private readonly PartySnapshotService $partySnapshots,
    ) {}

    /**
     * @param  array<int, int|string>  $sourceInvoiceIds
     */
    public function register(
        int $storeId,
        int $branchId,
        int $fiscalYearId,
        CheckType $type,
        int $partyProfileId,
        string $checkNumber,
        string $bankName,
        ?string $branchName,
        string|int|float $amount,
        ?string $issueDate,
        string $dueDate,
        User $creator,
        string $idempotencyKey,
        ?string $description = null,
        array $sourceInvoiceIds = [],
    ): Check {
        $normalizedAmount = Decimal::normalize($amount, Decimal::MONEY_SCALE);
        $checkNumber = trim($checkNumber);
        $bankName = trim($bankName);
        $branchName = $branchName !== null ? trim($branchName) : null;
        $issueDate = $issueDate !== ''
            ? CarbonImmutable::parse($issueDate)->toDateString()
            : null;
        $dueDate = CarbonImmutable::parse($dueDate)->toDateString();
        $description = $description !== null ? trim($description) : null;
        $sourceInvoiceIds = collect($sourceInvoiceIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $requestPayload = [
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'type' => $type->value,
            'party_profile_id' => $partyProfileId,
            'customer_id' => $type === CheckType::Receivable ? $partyProfileId : null,
            'supplier_id' => $type === CheckType::Payable ? $partyProfileId : null,
            'check_number' => $checkNumber,
            'bank_name' => $bankName,
            'branch_name' => $branchName,
            'amount' => $normalizedAmount,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'created_by' => $creator->id,
            'description' => $description,
            'source_invoice_ids' => $sourceInvoiceIds,
        ];
        $requestHash = IdempotencyHasher::hash('check.registration', $requestPayload);

        try {
            return DB::transaction(function () use (
                $storeId,
                $branchId,
                $fiscalYearId,
                $type,
                $partyProfileId,
                $checkNumber,
                $bankName,
                $branchName,
                $normalizedAmount,
                $issueDate,
                $dueDate,
                $creator,
                $idempotencyKey,
                $description,
                $requestHash,
                $sourceInvoiceIds,
            ): Check {
                $existing = Check::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->resolveIdempotentCheck($existing, $requestHash);
                }

                $branch = Branch::query()
                    ->whereKey($branchId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $branch->store_id !== $storeId || ! $branch->is_active) {
                    throw new DomainException('شعبه چک باید فعال و متعلق به فروشگاه باشد.');
                }

                $externalDuplicate = Check::query()
                    ->where('store_id', $storeId)
                    ->where('bank_name', $bankName)
                    ->where('check_number', $checkNumber)
                    ->where('type', $type->value)
                    ->lockForUpdate()
                    ->exists();

                if ($externalDuplicate) {
                    throw new DomainException('این چک قبلاً با همان بانک و شماره ثبت شده است.');
                }

                $hasHistoricalContext = $sourceInvoiceIds !== [];

                if ($hasHistoricalContext) {
                    $this->assertSourceInvoices(
                        $sourceInvoiceIds,
                        $type,
                        $partyProfileId,
                        $storeId,
                        $branchId,
                        $normalizedAmount,
                    );
                }

                if ($type === CheckType::Receivable) {
                    $customer = Customer::query()
                        ->whereKey($partyProfileId)
                        ->lockForUpdate()
                        ->first();
                    $party = $customer
                        ? Party::query()->whereKey($customer->party_id)->lockForUpdate()->first()
                        : null;

                    if (! $customer || (! $customer->is_active && ! $hasHistoricalContext) || ! $party || (! $party->is_active && ! $hasHistoricalContext)
                        || (int) $party->store_id !== $storeId) {
                        throw new DomainException('مشتری چک باید معتبر و متعلق به فروشگاه باشد.');
                    }

                    $partyRelationship = ['customer_id' => $customer->id, 'supplier_id' => null];
                } else {
                    $supplier = Supplier::query()
                        ->whereKey($partyProfileId)
                        ->lockForUpdate()
                        ->first();
                    $party = $supplier
                        ? Party::query()->whereKey($supplier->party_id)->lockForUpdate()->first()
                        : null;

                    if (! $supplier || (! $supplier->is_active && ! $hasHistoricalContext) || ! $party || (! $party->is_active && ! $hasHistoricalContext)
                        || (int) $party->store_id !== $storeId) {
                        throw new DomainException('تأمین‌کننده چک باید معتبر و متعلق به فروشگاه باشد.');
                    }

                    $partyRelationship = ['customer_id' => null, 'supplier_id' => $supplier->id];
                }

                return Check::query()->create([
                    'store_id' => $storeId,
                    'branch_id' => $branchId,
                    'fiscal_year_id' => $fiscalYearId,
                    'document_number' => $this->documentNumbers->next(
                        $storeId,
                        $branchId,
                        $fiscalYearId,
                        DocumentType::Check,
                    ),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_request_hash' => $requestHash,
                    'party_snapshot' => $this->partySnapshots->capture($party),
                    'check_number' => $checkNumber,
                    'bank_name' => $bankName,
                    'branch_name' => $branchName,
                    'amount' => $normalizedAmount,
                    'issue_date' => $issueDate,
                    'due_date' => $dueDate,
                    'type' => $type,
                    'created_by' => $creator->id,
                    'description' => $description,
                    'source_invoice_ids' => $sourceInvoiceIds,
                    ...$partyRelationship,
                ]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $existing = Check::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $this->resolveIdempotentCheck($existing, $requestHash);
                }

                $externalDuplicate = Check::query()
                    ->where('store_id', $storeId)
                    ->where('bank_name', $bankName)
                    ->where('check_number', $checkNumber)
                    ->where('type', $type->value)
                    ->exists();

                if ($externalDuplicate) {
                    throw new DomainException('این چک قبلاً با همان بانک و شماره ثبت شده است.');
                }

                throw new DomainException('شماره داخلی چک تکراری است. دوباره تلاش کنید.');
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function persistedPayload(Check $check): array
    {
        $sourceInvoiceIds = collect($check->source_invoice_ids ?? [])
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'store_id' => (int) $check->store_id,
            'branch_id' => (int) $check->branch_id,
            'fiscal_year_id' => $check->fiscal_year_id === null ? null : (int) $check->fiscal_year_id,
            'type' => $check->type->value,
            'party_profile_id' => $check->type === CheckType::Receivable
                ? (int) $check->customer_id
                : (int) $check->supplier_id,
            'customer_id' => $check->customer_id === null ? null : (int) $check->customer_id,
            'supplier_id' => $check->supplier_id === null ? null : (int) $check->supplier_id,
            'check_number' => $check->check_number,
            'bank_name' => $check->bank_name,
            'branch_name' => $check->branch_name,
            'amount' => Decimal::normalize($check->amount, Decimal::MONEY_SCALE),
            'issue_date' => $check->issue_date?->toDateString(),
            'due_date' => $check->due_date->toDateString(),
            'created_by' => $check->created_by === null ? null : (int) $check->created_by,
            'description' => $check->description,
            'source_invoice_ids' => $sourceInvoiceIds,
        ];
    }

    private function resolveIdempotentCheck(Check $existing, string $requestHash): Check
    {
        if ($existing->idempotency_request_hash !== null) {
            if (! hash_equals($existing->idempotency_request_hash, $requestHash)) {
                throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
            }
        } elseif (! hash_equals(
            IdempotencyHasher::hash('check.registration', $this->persistedPayload($existing)),
            $requestHash,
        )) {
            throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
        }

        return $existing;
    }

    /** @param array<int, int> $sourceInvoiceIds */
    private function assertSourceInvoices(
        array $sourceInvoiceIds,
        CheckType $type,
        int $partyProfileId,
        int $storeId,
        int $branchId,
        string $amount,
    ): void {
        $invoices = Invoice::query()
            ->whereIn('id', $sourceInvoiceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($invoices->count() !== count($sourceInvoiceIds)) {
            throw new DomainException('فاکتورهای انتخاب‌شده برای چک معتبر نیستند.');
        }

        $outstanding = '0.00';

        foreach ($invoices as $invoice) {
            $expectedType = $type === CheckType::Receivable ? InvoiceType::Sales : InvoiceType::Purchase;
            $belongsToParty = $type === CheckType::Receivable
                ? (int) $invoice->customer_id === $partyProfileId
                : (int) $invoice->supplier_id === $partyProfileId;

            if ($invoice->type !== $expectedType
                || ! $belongsToParty
                || (int) $invoice->store_id !== $storeId
                || (int) $invoice->branch_id !== $branchId
                || ! in_array($invoice->status, [InvoiceStatus::Confirmed, InvoiceStatus::PartiallyPaid], true)) {
                throw new DomainException('فاکتورهای انتخاب‌شده برای چک معتبر نیستند.');
            }

            $invoiceOutstanding = $type === CheckType::Receivable
                ? $this->receivables->outstandingForInvoice($invoice, true)
                : $this->payables->outstandingForInvoice($invoice, true);

            if (! Decimal::isPositive($invoiceOutstanding)) {
                throw new DomainException('فاکتورهای انتخاب‌شده برای چک مانده قابل تسویه ندارند.');
            }

            $outstanding = Decimal::add($outstanding, $invoiceOutstanding, Decimal::MONEY_SCALE);
        }

        if (Decimal::compare($amount, $outstanding, Decimal::MONEY_SCALE) > 0) {
            throw new DomainException('مبلغ چک از مانده فاکتورهای انتخاب‌شده بیشتر است.');
        }
    }
}
