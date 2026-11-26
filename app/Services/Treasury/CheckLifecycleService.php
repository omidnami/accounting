<?php

namespace App\Services\Treasury;

use App\Enums\CheckStatus;
use App\Models\Check;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CheckLifecycleService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Check $check, array $data, User $actor): Check
    {
        if (! $actor->canManageChecks()) {
            throw new DomainException('ویرایش چک فقط برای مدیر یا حسابدار مجاز است.');
        }

        $values = [
            'check_number' => trim((string) $data['check_number']),
            'bank_name' => trim((string) $data['bank_name']),
            'branch_name' => isset($data['branch_name']) && trim((string) $data['branch_name']) !== ''
                ? trim((string) $data['branch_name'])
                : null,
            'amount' => Decimal::normalize($data['amount'], Decimal::MONEY_SCALE),
            'issue_date' => isset($data['issue_date']) && $data['issue_date'] !== ''
                ? $data['issue_date']
                : null,
            'due_date' => $data['due_date'],
            'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                ? trim((string) $data['description'])
                : null,
        ];

        if ($values['check_number'] === '' || $values['bank_name'] === '') {
            throw new DomainException('شماره و نام بانک چک را وارد کنید.');
        }

        if (! Decimal::isPositive($values['amount'])) {
            throw new DomainException('مبلغ چک باید بیشتر از صفر باشد.');
        }

        try {
            return DB::transaction(function () use ($check, $values): Check {
                $locked = $this->lockEditable($check);
                $duplicate = Check::query()
                    ->where('store_id', $locked->store_id)
                    ->where('bank_name', $values['bank_name'])
                    ->where('check_number', $values['check_number'])
                    ->where('type', $locked->type->value)
                    ->where('id', '!=', $locked->id)
                    ->lockForUpdate()
                    ->exists();

                if ($duplicate) {
                    throw new DomainException('این چک قبلاً با همان بانک و شماره ثبت شده است.');
                }

                $oldValues = $this->editableValues($locked);
                $locked->fill($values);

                if (! $locked->isDirty()) {
                    return $locked->refresh();
                }

                $locked->save();
                $this->audit->record(
                    'check_updated',
                    $locked,
                    $oldValues,
                    $this->editableValues($locked),
                    "چک {$locked->document_number} ویرایش شد.",
                );

                return $locked->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $duplicate = Check::query()
                ->where('store_id', $check->store_id)
                ->where('bank_name', $values['bank_name'])
                ->where('check_number', $values['check_number'])
                ->where('type', $check->type->value)
                ->where('id', '!=', $check->id)
                ->exists();

            if ($duplicate) {
                throw new DomainException('این چک قبلاً با همان بانک و شماره ثبت شده است.');
            }

            throw $exception;
        }
    }

    public function cancel(Check $check, string $reason, User $actor): Check
    {
        if (! $actor->canManageChecks()) {
            throw new DomainException('ابطال چک فقط برای مدیر یا حسابدار مجاز است.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('دلیل ابطال چک را وارد کنید.');
        }

        return DB::transaction(function () use ($check, $reason): Check {
            $locked = $this->lockEditable($check);
            $oldValues = [
                'status' => $locked->status->value,
                'description' => $locked->description,
            ];

            $locked->markCancelled($reason);
            $this->audit->record(
                'check_cancelled',
                $locked,
                $oldValues,
                [
                    'status' => CheckStatus::Cancelled->value,
                    'description' => $locked->description,
                    'reason' => $reason,
                ],
                "چک {$locked->document_number} ابطال شد.",
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    private function lockEditable(Check $check): Check
    {
        $locked = Check::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== CheckStatus::Pending) {
            throw new DomainException('فقط چک در انتظار قابل ویرایش یا ابطال است.');
        }

        if ($locked->receipt()->exists() || $locked->payment()->exists()) {
            throw new DomainException('چک متصل به دریافت یا پرداخت قابل ویرایش یا ابطال نیست.');
        }

        return $locked;
    }

    /**
     * @return array<string, mixed>
     */
    private function editableValues(Check $check): array
    {
        return [
            'check_number' => $check->check_number,
            'bank_name' => $check->bank_name,
            'branch_name' => $check->branch_name,
            'amount' => $check->amount,
            'issue_date' => $check->issue_date?->toDateString(),
            'due_date' => $check->due_date->toDateString(),
            'description' => $check->description,
        ];
    }
}
