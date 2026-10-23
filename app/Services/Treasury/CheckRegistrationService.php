<?php

namespace App\Services\Treasury;

use App\Enums\CheckType;
use App\Enums\DocumentType;
use App\Models\Branch;
use App\Models\Check;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\DocumentNumberService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CheckRegistrationService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
    ) {}

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
    ): Check {
        $normalizedAmount = Decimal::normalize($amount, Decimal::MONEY_SCALE);
        $checkNumber = trim($checkNumber);
        $bankName = trim($bankName);
        $branchName = $branchName !== null ? trim($branchName) : null;
        $issueDate = $issueDate !== '' ? $issueDate : null;
        $description = $description !== null ? trim($description) : null;
        $requestHash = hash('sha256', json_encode([
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'type' => $type->value,
            'party_profile_id' => $partyProfileId,
            'check_number' => $checkNumber,
            'bank_name' => $bankName,
            'branch_name' => $branchName,
            'amount' => $normalizedAmount,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'created_by' => $creator->id,
            'description' => $description,
        ], JSON_THROW_ON_ERROR));

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
            ): Check {
                $existing = Check::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    if ($existing->idempotency_request_hash !== null
                        && ! hash_equals($existing->idempotency_request_hash, $requestHash)) {
                        throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
                    }

                    return $existing;
                }

                $branch = Branch::query()->findOrFail($branchId);

                if ((int) $branch->store_id !== $storeId || ! $branch->is_active) {
                    throw new DomainException('شعبه چک باید فعال و متعلق به فروشگاه باشد.');
                }

                if ($type === CheckType::Receivable) {
                    $customer = Customer::query()
                        ->whereKey($partyProfileId)
                        ->lockForUpdate()
                        ->first();
                    $party = $customer
                        ? Party::query()->whereKey($customer->party_id)->lockForUpdate()->first()
                        : null;

                    if (! $customer || ! $customer->is_active || ! $party || ! $party->is_active
                        || (int) $party->store_id !== $storeId) {
                        throw new DomainException('مشتری چک باید فعال و متعلق به فروشگاه باشد.');
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

                    if (! $supplier || ! $supplier->is_active || ! $party || ! $party->is_active
                        || (int) $party->store_id !== $storeId) {
                        throw new DomainException('تأمین‌کننده چک باید فعال و متعلق به فروشگاه باشد.');
                    }

                    $partyRelationship = ['customer_id' => null, 'supplier_id' => $supplier->id];
                }

                return Check::query()->create([
                    'store_id' => $storeId,
                    'branch_id' => $branchId,
                    'document_number' => $this->documentNumbers->next(
                        $storeId,
                        $branchId,
                        $fiscalYearId,
                        DocumentType::Check,
                    ),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_request_hash' => $requestHash,
                    'check_number' => $checkNumber,
                    'bank_name' => $bankName,
                    'branch_name' => $branchName,
                    'amount' => $normalizedAmount,
                    'issue_date' => $issueDate,
                    'due_date' => $dueDate,
                    'type' => $type,
                    'created_by' => $creator->id,
                    'description' => $description,
                    ...$partyRelationship,
                ]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $existing = Check::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    if ($existing->idempotency_request_hash !== null
                        && ! hash_equals($existing->idempotency_request_hash, $requestHash)) {
                        throw new DomainException('کلید تکرارپذیری قبلاً برای درخواست متفاوتی استفاده شده است.');
                    }

                    return $existing;
                }

                throw new DomainException('شماره داخلی چک تکراری است. دوباره تلاش کنید.');
            }

            throw $exception;
        }
    }
}
