<?php

namespace App\Http\Controllers;

use App\Enums\ProductSerialStatus;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Branches\BranchContextService;
use App\Services\Products\ProductTrackingService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductTrackingController extends Controller
{
    public function index(Request $request, Product $product): Response
    {
        abort_unless($request->user()?->canManageProducts(), 403);
        $store = $this->mainStore();
        $branch = app(BranchContextService::class)->active($request->user(), $store);
        $product->load(['category:id,code,name', 'parent:id,code,name']);
        $today = today();
        $expiringUntil = $today->copy()->addDays(30);

        return Inertia::render('products/Tracking', [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'unit' => $product->unit,
                'category' => $product->category?->only(['code', 'name']),
                'parent' => $product->parent?->only(['id', 'code', 'name']),
                'is_variant_parent' => $product->variants()->exists(),
            ],
            'batches' => $product->batches()
                ->withCount('serials')
                ->orderByRaw('expiry_date IS NULL')
                ->orderBy('expiry_date')
                ->get()
                ->map(fn (ProductBatch $batch): array => [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'production_date' => $batch->production_date?->toDateString(),
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'notes' => $batch->notes,
                    'serials_count' => $batch->serials_count,
                    'status' => $batch->expiry_date === null
                        ? 'no_expiry'
                        : ($batch->expiry_date->lt($today)
                            ? 'expired'
                            : ($batch->expiry_date->lte($expiringUntil)
                                ? 'expiring'
                                : 'valid')),
                ]),
            'serials' => $product->serials()
                ->with(['batch:id,batch_number', 'warehouse:id,name,code'])
                ->latest('id')
                ->limit(300)
                ->get()
                ->map(fn (ProductSerial $serial): array => [
                    'id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'status' => $serial->status->value,
                    'status_label' => $serial->status->label(),
                    'batch' => $serial->batch?->only(['id', 'batch_number']),
                    'warehouse' => $serial->warehouse?->only(['id', 'code', 'name']),
                    'acquired_at' => $serial->acquired_at?->toDateString(),
                    'notes' => $serial->notes,
                ]),
            'warehouses' => Warehouse::query()
                ->where('store_id', $store->id)
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_default']),
            'summary' => [
                'batches' => $product->batches()->count(),
                'expiring' => $product->batches()
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', $today)
                    ->whereDate('expiry_date', '<=', $expiringUntil)
                    ->count(),
                'expired' => $product->batches()
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<', $today)
                    ->count(),
                'available_serials' => $product->serials()
                    ->where('status', ProductSerialStatus::Available->value)
                    ->count(),
            ],
        ]);
    }

    public function storeBatch(
        Request $request,
        Product $product,
        ProductTrackingService $tracking,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageProducts(), 403);
        $validated = $request->validate([
            'batch_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('product_batches', 'batch_number')
                    ->where('product_id', $product->id),
            ],
            'production_date' => ['nullable', 'date'],
            'expiry_date' => [
                'nullable',
                'date',
                'after_or_equal:production_date',
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $tracking->createBatch($product, $validated);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['tracking' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'سری ساخت کالا ثبت شد.',
        ]);

        return to_route('products.tracking', $product);
    }

    public function storeSerials(
        Request $request,
        Product $product,
        ProductTrackingService $tracking,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageProducts(), 403);
        $store = $this->mainStore();
        $branch = app(BranchContextService::class)->active($request->user(), $store);
        $validated = $request->validate([
            'serial_numbers_text' => ['required', 'string', 'max:50000'],
            'product_batch_id' => [
                'nullable',
                'integer',
                Rule::exists('product_batches', 'id')
                    ->where('product_id', $product->id),
            ],
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')
                    ->where('store_id', $store->id)
                    ->where('branch_id', $branch->id),
            ],
            'acquired_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $serialNumbers = preg_split('/[\r\n,]+/', (string) $validated['serial_numbers_text']);

        try {
            $count = $tracking->createSerials(
                $product,
                is_array($serialNumbers) ? $serialNumbers : [],
                isset($validated['product_batch_id'])
                    ? (int) $validated['product_batch_id']
                    : null,
                isset($validated['warehouse_id'])
                    ? (int) $validated['warehouse_id']
                    : null,
                $validated['acquired_at'] ?? null,
                $validated['notes'] ?? null,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['tracking' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$count} شماره سریال ثبت شد.",
        ]);

        return to_route('products.tracking', $product);
    }

    public function voidSerial(
        Request $request,
        Product $product,
        ProductSerial $serial,
        ProductTrackingService $tracking,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageProducts(), 403);
        abort_unless((int) $serial->product_id === (int) $product->id, 404);

        try {
            $tracking->voidSerial($serial, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['tracking' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'شماره سریال باطل شد.',
        ]);

        return to_route('products.tracking', $product);
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
