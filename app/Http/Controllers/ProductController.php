<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Digits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request, ProductCatalogService $catalog): Response
    {
        abort_unless($request->user()?->canManageProducts(), 403);

        $store = $this->mainStore();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'stock_status' => ['nullable', 'string', 'in:in_stock,low_stock,out_of_stock'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);
        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;

        if ($warehouseId !== null && ! Warehouse::query()
            ->whereKey($warehouseId)
            ->where('store_id', $store->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'انبار انتخاب‌شده معتبر یا متعلق به این فروشگاه نیست.',
            ]);
        }

        $filters = [
            'search' => trim(Digits::toLatin((string) ($validated['search'] ?? ''))),
            'stock_status' => $validated['stock_status'] ?? null,
            'status' => $validated['status'] ?? null,
            'warehouse_id' => $warehouseId,
        ];

        return Inertia::render('products/Index', [
            'products' => $catalog->paginate($store->id, $filters),
            'filters' => $filters,
            'warehouses' => $store->warehouses()
                ->orderByDesc('is_active')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default', 'is_active']),
            'summary' => $catalog->summary($store->id, $warehouseId),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canManageProducts(), 403);

        return Inertia::render('products/Form', [
            'product' => null,
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        try {
            $product = DB::transaction(fn (): Product => Product::query()->create(
                collect($request->validated())->except('version')->all(),
            ));
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwProductUniqueException($exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "کالای «{$product->name}» با موفقیت ایجاد شد.",
        ]);

        return to_route('products.index');
    }

    public function edit(Request $request, Product $product): Response
    {
        abort_unless($request->user()?->canManageProducts(), 403);

        return Inertia::render('products/Form', [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'unit' => $product->unit,
                'purchase_price' => $product->purchase_price,
                'sale_price' => $product->sale_price,
                'tax_rate' => $product->tax_rate,
                'reorder_level' => $product->reorder_level,
                'description' => $product->description,
                'is_active' => $product->is_active,
                'version' => $product->version,
            ],
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();

        try {
            $updatedName = DB::transaction(function () use ($product, $data): string {
                $lockedProduct = Product::query()
                    ->whereKey($product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedProduct->version !== (int) $data['version']) {
                    throw ValidationException::withMessages([
                        'product' => 'این کالا هم‌زمان توسط کاربر دیگری تغییر کرده است. اطلاعات تازه را دوباره بررسی کنید.',
                    ]);
                }

                $lockedProduct->update([
                    ...collect($data)->except('version')->all(),
                    'version' => $lockedProduct->version + 1,
                ]);

                return $lockedProduct->name;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwProductUniqueException($exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "تغییرات کالای «{$updatedName}» ذخیره شد.",
        ]);

        return to_route('products.index');
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function throwProductUniqueException(UniqueConstraintViolationException $exception): never
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'products_code_unique')
            || str_contains($message, 'products.code')) {
            throw ValidationException::withMessages([
                'code' => 'این کد کالا قبلاً ثبت شده است.',
            ]);
        }

        if (str_contains($message, 'products_barcode_unique')
            || str_contains($message, 'products.barcode')) {
            throw ValidationException::withMessages([
                'barcode' => 'این بارکد قبلاً برای کالای دیگری ثبت شده است.',
            ]);
        }

        throw $exception;
    }
}
