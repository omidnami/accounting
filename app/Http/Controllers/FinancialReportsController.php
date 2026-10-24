<?php

namespace App\Http\Controllers;

use App\Enums\AccountLevel;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Reporting\AccountLedgerReportService;
use App\Services\Reporting\FinancialStatementReportService;
use App\Services\Reporting\JournalReportService;
use App\Services\Reporting\TrialBalanceReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialReportsController extends Controller
{
    public function __invoke(
        Request $request,
        JournalReportService $journals,
        TrialBalanceReportService $trialBalance,
        FinancialStatementReportService $statements,
        AccountLedgerReportService $ledgers,
    ): Response {
        abort_unless($request->user()?->canViewFinancialReports(), 403);

        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $fiscalYear = FiscalYear::query()
            ->where('store_id', $store->id)
            ->where('is_current', true)
            ->firstOrFail();
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);
        $from = $validated['from'] ?? $fiscalYear->starts_on->toDateString();
        $to = $validated['to'] ?? $fiscalYear->ends_on->toDateString();
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $accounts = Account::query()
            ->where('store_id', $store->id)
            ->where('level', AccountLevel::Subsidiary)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'store_id', 'code', 'name']);
        $account = $accounts->firstWhere(
            'id',
            isset($validated['account_id']) ? (int) $validated['account_id'] : $accounts->first()?->id,
        );

        if ($branchId !== null && ! Branch::query()
            ->whereKey($branchId)
            ->where('store_id', $store->id)
            ->exists()) {
            abort(422, 'شعبه انتخاب‌شده متعلق به فروشگاه نیست.');
        }

        if ($account === null && isset($validated['account_id'])) {
            abort(422, 'حساب انتخاب‌شده متعلق به فروشگاه یا فعال نیست.');
        }

        return Inertia::render('reports/Index', [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'branch_id' => $branchId,
                'account_id' => $account?->id,
            ],
            'accounts' => $accounts,
            'branches' => $store->branches()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'name']),
            'trialBalance' => $trialBalance->report($store->id, $from, $to, $branchId),
            'incomeStatement' => $statements->incomeStatement($store->id, $from, $to, $branchId),
            'balanceSheet' => $statements->balanceSheet($store->id, $to, $branchId),
            'accountLedger' => $account
                ? $ledgers->report($account, $store->id, $from, $to, $branchId)
                : null,
            'journal' => $journals->paginate(
                $store->id,
                $from,
                $to,
                $branchId,
                15,
                (int) ($validated['page'] ?? 1),
            ),
        ]);
    }
}
