<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use App\Support\Digits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManageProducts(), 403);

        return Inertia::render('product-categories/Index', [
            'categories' => ProductCategory::query()
                ->withCount('products')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (ProductCategory $category): array => [
                    'id' => $category->id,
                    'code' => $category->code,
                    'name' => $category->name,
                    'description' => $category->description,
                    'is_active' => $category->is_active,
                    'products_count' => $category->products_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canManageProducts(), 403);

        try {
            ProductCategory::query()->create($this->validated($request));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => 'این کد دسته‌بندی قبلاً ثبت شده است.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'دسته‌بندی کالا ایجاد شد.',
        ]);

        return to_route('product-categories.index');
    }

    public function update(Request $request, ProductCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->canManageProducts(), 403);
        $validated = $this->validateData($request, $category);

        if ($validated['is_active'] === false
            && $category->products()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'category' => 'دسته‌بندی دارای کالای فعال قابل غیرفعال‌سازی نیست.',
            ]);
        }

        try {
            $category->update($validated);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => 'این کد دسته‌بندی قبلاً ثبت شده است.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'دسته‌بندی کالا ذخیره شد.',
        ]);

        return to_route('product-categories.index');
    }

    /**
     * @return array{code: string, name: string, description: string|null, is_active: bool}
     */
    private function validated(Request $request): array
    {
        return $this->validateData($request);
    }

    /**
     * @return array{code: string, name: string, description: string|null, is_active: bool}
     */
    private function validateData(
        Request $request,
        ?ProductCategory $category = null,
    ): array {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/u',
                Rule::unique('product_categories', 'code')->ignore($category),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        return [
            'code' => trim(Digits::toLatin((string) $validated['code'])),
            'name' => trim((string) $validated['name']),
            'description' => isset($validated['description'])
                ? trim((string) $validated['description']) ?: null
                : null,
            'is_active' => (bool) $validated['is_active'],
        ];
    }
}
