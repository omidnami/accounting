<?php

namespace App\Http\Controllers;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Http\Requests\InventoryCardexRequest;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
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
        BranchContextService $branches,
    ): StreamedResponse {
        abort_unless($request->user()?->canViewFinancialReports(), 403);
        $store = $this->mainStore();
        [$from, $to] = $this->dates($request, $store);
        $validated = $request->validate([
            'report' => [
                'required',
                Rule::in(['trial', 'income', 'balance', 'journal', 'ledger']),
            ],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('store_id', $store->id),
            ],
        ]);
        $branchId = $branches->active($request->user(), $store)->id;
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
        BranchContextService $branches,
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
            'party_id' => [
                'nullable',
                'integer',
                Rule::exists('parties', 'id')->where('store_id', $store->id),
            ],
        ]);
        $branchId = $branches->active($request->user(), $store)->id;
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
        InventoryCardexRequest $request,
        ReportExportService $exports,
    ): StreamedResponse {
        $store = $this->mainStore();
        $validated = $request->validated();

        return $exports->cardex(
            $store,
            (int) $validated['product_id'],
            (int) $validated['warehouse_id'],
            $validated['from'],
            $validated['to'],
            isset($validated['movement_type'])
                ? InventoryMovementType::from($validated['movement_type'])
                : null,
            isset($validated['direction'])
                ? InventoryDirection::from($validated['direction'])
                : null,
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
