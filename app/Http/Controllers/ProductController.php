<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPriceHistory;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Products\ProductManagementService;
use App\Support\Digits;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function image(Request $request, Product $product): StreamedResponse
    {
        abort_unless($request->user()?->canManageProducts() || $request->user()?->canManageSales(), 403);
        abort_if($product->image_path === null || ! Storage::disk('local')->exists($product->image_path), 404);

        return Storage::disk('local')->response($product->image_path, headers: [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index(Request $request, ProductCatalogService $catalog): Response
    {
        abort_unless($request->user()?->canManageProducts(), 403);

        $store = $this->mainStore();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'stock_status' => ['nullable', 'string', 'in:in_stock,low_stock,out_of_stock'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
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
            'category_id' => isset($validated['category_id'])
                ? (int) $validated['category_id']
                : null,
        ];

        return Inertia::render('products/Index', [
            'products' => $catalog->paginate($store->id, $filters),
            'filters' => $filters,
            'warehouses' => $store->warehouses()
                ->orderByDesc('is_active')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default', 'is_active']),
            'summary' => $catalog->summary(
                $store->id,
                $warehouseId,
                isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            ),
            'categories' => $this->categories(),
        ]);
    }

    public function create(
        Request $request,
        ProductManagementService $products,
    ): Response {
        abort_unless($request->user()?->canManageProducts(), 403);
        $parent = null;

        if ($request->filled('parent_product_id')) {
            $parent = Product::query()
                ->whereKey((int) $request->input('parent_product_id'))
                ->firstOrFail();
            abort_unless($products->canAddVariant($parent), 404);
        }

        return Inertia::render('products/Form', [
            'product' => null,
            'parent_product' => $parent === null ? null : [
                'id' => $parent->id,
                'code' => $parent->code,
                'name' => $parent->name,
                'unit' => $parent->unit,
                'category_id' => $parent->category_id,
                'purchase_price' => $parent->purchase_price,
                'sale_price' => $parent->sale_price,
                'tax_rate' => $parent->tax_rate,
            ],
            'categories' => $this->categories(),
            'parent_candidates' => Product::query()
                ->whereNull('parent_product_id')
                ->whereDoesntHave('invoiceItems')
                ->whereDoesntHave('inventoryMovements')
                ->whereDoesntHave('serials')
                ->orderBy('name')
                ->get([
                    'id',
                    'code',
                    'name',
                    'unit',
                    'category_id',
                    'purchase_price',
                    'sale_price',
                    'tax_rate',
                ]),
        ]);
    }

    public function store(
        ProductRequest $request,
        ProductManagementService $products,
    ): RedirectResponse {
        try {
            $product = $products->create($request->validated(), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['product' => $exception->getMessage()]);
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwProductUniqueException($exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "کالای «{$product->name}» با موفقیت ایجاد شد.",
        ]);

        return to_route('products.index');
    }

    public function edit(
        Request $request,
        Product $product,
        ProductManagementService $products,
    ): Response {
        abort_unless($request->user()?->canManageProducts(), 403);

        return Inertia::render('products/Form', [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'image_url' => $product->imageUrl(),
                'name' => $product->name,
                'category_id' => $product->category_id,
                'parent_product_id' => $product->parent_product_id,
                'variant_color' => $product->variant_color,
                'variant_size' => $product->variant_size,
                'unit' => $product->unit,
                'purchase_price' => $product->purchase_price,
                'sale_price' => $product->sale_price,
                'tax_rate' => $product->tax_rate,
                'reorder_level' => $product->reorder_level,
                'safety_stock' => $product->safety_stock,
                'reorder_quantity' => $product->reorder_quantity,
                'max_stock' => $product->max_stock,
                'stock_policy' => $product->stock_policy->value,
                'track_batches' => $product->track_batches,
                'track_serials' => $product->track_serials,
                'description' => $product->description,
                'is_active' => $product->is_active,
                'version' => $product->version,
            ],
            'parent_product' => $product->parent?->only(['id', 'code', 'name']),
            'categories' => $this->categories(),
            'parent_candidates' => [],
            'price_history' => $product->priceHistories()
                ->with('changedBy:id,name')
                ->limit(20)
                ->get()
                ->map(fn (ProductPriceHistory $history): array => [
                    'id' => $history->id,
                    'purchase_price' => $history->purchase_price,
                    'sale_price' => $history->sale_price,
                    'source' => $history->source,
                    'changed_by' => $history->changedBy?->name,
                    'effective_at' => $history->effective_at->toDateTimeString(),
                ]),
            'can_add_variant' => $products->canAddVariant($product),
            'has_variants' => $product->variants()->exists(),
        ]);
    }

    public function update(
        ProductRequest $request,
        Product $product,
        ProductManagementService $products,
    ): RedirectResponse {
        try {
            $updated = $products->update(
                $product,
                $request->validated(),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['product' => $exception->getMessage()]);
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwProductUniqueException($exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "تغییرات کالای «{$updated->name}» ذخیره شد.",
        ]);

        return to_route('products.index');
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function categories(): mixed
    {
        return ProductCategory::query()
            ->withCount('products')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active']);
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
