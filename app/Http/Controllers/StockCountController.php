<?php

namespace App\Http\Controllers;

use App\Enums\StockCountStatus;
use App\Http\Requests\StockCountRequest;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\Store;
use App\Services\Inventory\StockCountService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StockCountController extends Controller
{
    public function index(Request $request, StockCountService $counts): Response
    {
        $this->authorizeAccess($request);
        $store = $this->mainStore();
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(StockCountStatus::class)],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);
        $filters = [
            'status' => $validated['status'] ?? null,
            'warehouse_id' => isset($validated['warehouse_id'])
                ? (int) $validated['warehouse_id']
                : null,
        ];

        return Inertia::render('inventory/counts/Index', [
            'counts' => $counts->paginate($store, $filters),
            'filters' => $filters,
            'warehouses' => $this->warehouses($store),
            'can_post' => $request->user()->canPostStockCounts(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeAccess($request);

        return Inertia::render('inventory/counts/Form', [
            'count' => null,
            ...$this->formOptions($this->mainStore()),
            'can_view_costs' => $request->user()->canViewInventoryCosts(),
        ]);
    }

    public function store(
        StockCountRequest $request,
        StockCountService $counts,
    ): RedirectResponse {
        try {
            $count = $counts->createDraft(
                $this->mainStore(),
                $request->validated(),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['count' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پیش‌نویس شمارش {$count->count_number} ایجاد شد.",
        ]);

        return to_route('stock-counts.show', $count);
    }

    public function show(
        Request $request,
        StockCount $stockCount,
        StockCountService $counts,
    ): Response {
        $this->authorizeAccess($request);

        try {
            $detail = $counts->detail(
                $this->mainStore(),
                $stockCount,
                $request->user()->canViewInventoryCosts(),
            );
        } catch (DomainException $exception) {
            abort(404, $exception->getMessage());
        }

        return Inertia::render('inventory/counts/Show', [
            'count' => $detail,
            'can_post' => $request->user()->canPostStockCounts(),
            'can_view_costs' => $request->user()->canViewInventoryCosts(),
        ]);
    }

    public function edit(
        Request $request,
        StockCount $stockCount,
        StockCountService $counts,
    ): Response {
        $this->authorizeAccess($request);

        if ((int) $stockCount->store_id !== (int) $this->mainStore()->id
            || $stockCount->status !== StockCountStatus::Draft) {
            abort(404);
        }

        return Inertia::render('inventory/counts/Form', [
            'count' => $counts->detail(
                $this->mainStore(),
                $stockCount,
                $request->user()->canViewInventoryCosts(),
            ),
            ...$this->formOptions($this->mainStore()),
            'can_view_costs' => $request->user()->canViewInventoryCosts(),
        ]);
    }

    public function update(
        StockCountRequest $request,
        StockCount $stockCount,
        StockCountService $counts,
    ): RedirectResponse {
        try {
            $count = $counts->updateDraft(
                $this->mainStore(),
                $stockCount,
                $request->validated(),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['count' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "شمارش {$count->count_number} ذخیره شد.",
        ]);

        return to_route('stock-counts.show', $count);
    }

    public function post(
        Request $request,
        StockCount $stockCount,
        StockCountService $counts,
    ): RedirectResponse {
        abort_unless($request->user()?->canPostStockCounts(), 403);

        try {
            $count = $counts->postCount(
                $this->mainStore(),
                $stockCount,
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['count' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "شمارش {$count->count_number} ثبت و تعدیل اعمال شد.",
        ]);

        return to_route('stock-counts.show', $count);
    }

    public function destroy(
        Request $request,
        StockCount $stockCount,
        StockCountService $counts,
    ): RedirectResponse {
        $this->authorizeAccess($request);

        try {
            $counts->deleteDraft(
                $this->mainStore(),
                $stockCount,
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['count' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'پیش‌نویس شمارش حذف شد.',
        ]);

        return to_route('stock-counts.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Store $store): array
    {
        return [
            'warehouses' => $this->warehouses($store),
            'products' => Product::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'unit']),
        ];
    }

    private function warehouses(Store $store): mixed
    {
        return $store->warehouses()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default']);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->canManageStockCounts(), 403);
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
