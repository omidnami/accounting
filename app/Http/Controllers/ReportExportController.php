<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Reporting\ReportExportService;
use App\Support\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function financial(
        Request $request,
        ReportExportService $exports,
    ): StreamedResponse {
        abort_unless($request->user()?->canViewFinancialReports(), 403);
        $store = $this->mainStore();
        [$from, $to] = $this->dates($request, $store);
        $validated = $request->validate([
            'report' => [
                'required',
                Rule::in(['trial', 'income', 'balance', 'journal', 'ledger']),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('store_id', $store->id),
            ],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('store_id', $store->id),
            ],
        ]);
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $accountId = isset($validated['account_id']) ? (int) $validated['account_id'] : null;

        return $exports->financial(
            $store,
            $validated['report'],
            $from,
            $to,
            $branchId,
            $accountId,
        );
    }

    public function operations(
        Request $request,
        ReportExportService $exports,
    ): StreamedResponse {
        abort_unless($request->user()?->canViewFinancialReports(), 403);
        $store = $this->mainStore();
        [$from, $to] = $this->dates($request, $store);
        $validated = $request->validate([
            'report' => [
                'required',
                Rule::in([
                    'statement',
                    'aging_receivable',
                    'aging_payable',
                    'sales_daily',
                    'sales_products',
                ]),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('store_id', $store->id),
            ],
            'party_id' => [
                'nullable',
                'integer',
                Rule::exists('parties', 'id')->where('store_id', $store->id),
            ],
        ]);
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $partyId = isset($validated['party_id']) ? (int) $validated['party_id'] : null;

        return $exports->operations(
            $store,
            $validated['report'],
            $from,
            $to,
            $branchId,
            $partyId,
        );
    }

    public function cardex(
        Request $request,
        ReportExportService $exports,
    ): StreamedResponse {
        abort_unless($request->user()?->canViewInventoryReports(), 403);
        $store = $this->mainStore();
        [$from, $to] = $this->dates($request, $store);
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('store_id', $store->id),
            ],
        ]);

        return $exports->cardex(
            $store,
            (int) $validated['product_id'],
            (int) $validated['warehouse_id'],
            $from,
            $to,
            $request->user()->canViewInventoryCosts(),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dates(Request $request, Store $store): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $fiscalYear = FiscalYear::query()
            ->where('store_id', $store->id)
            ->where('is_current', true)
            ->firstOrFail();
        $from = $validated['from'] ?? $fiscalYear->starts_on->toDateString();
        $to = $validated['to'] ?? $fiscalYear->ends_on->toDateString();

        try {
            $range = ReportDateRange::between($from, $to);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['to' => $exception->getMessage()]);
        }

        return [$range->from, $range->to];
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
