<?php

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Sales\SalesCheckoutLookupService;
use Database\Seeders\StoreSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function productImageUpload(string $name = 'product.png'): UploadedFile
{
    // Real PNG bytes keep these tests independent of the optional GD extension.
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=',
    ));
}

function imageProductPayload(array $overrides = []): array
{
    return array_replace([
        'code' => 'IMAGE-1', 'name' => 'کالای تصویردار', 'unit' => 'عدد',
        'purchase_price' => '100', 'sale_price' => '200', 'tax_rate' => '0',
        'reorder_level' => '0', 'is_active' => true,
    ], $overrides);
}

it('uploads an optional image, previews it on edit and exposes it in the POS lookup', function () {
    Storage::fake('local');
    app(StoreSeeder::class)->run();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($manager)->post(route('products.store'), imageProductPayload([
        'image' => productImageUpload(),
    ]))->assertRedirect(route('products.index'));
    $product = Product::query()->where('code', 'IMAGE-1')->firstOrFail();
    expect($product->image_path)->toStartWith('products/');
    Storage::disk('local')->assertExists($product->image_path);
    $this->get(route('products.edit', $product))->assertInertia(fn (Assert $page) => $page
        ->where('product.image_url', $product->imageUrl()));
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $warehouse = $store->defaultBranch->warehouses()->firstOrFail();
    $lookup = app(SalesCheckoutLookupService::class)->products($store->id, $warehouse->id, 'IMAGE-1', limit: 15);
    expect($lookup[0]['image_url'])->toBe($product->imageUrl());

    $cashier = User::factory()->create(['role' => UserRole::Cashier]);
    $this->actingAs($cashier)->get($product->imageUrl())
        ->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('preserves, replaces and explicitly removes an image using multipart method spoofing', function () {
    Storage::fake('local');
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($manager)->post(route('products.store'), imageProductPayload(['image' => productImageUpload()]));
    $product = Product::query()->firstOrFail();
    $original = $product->image_path;
    $this->post(route('products.update', $product), imageProductPayload([
        '_method' => 'put', 'version' => 0,
    ]))->assertRedirect(route('products.index'));
    expect($product->refresh()->image_path)->toBe($original);
    Storage::disk('local')->assertExists($original);

    $this->post(route('products.update', $product), imageProductPayload([
        '_method' => 'put', 'version' => 1, 'image' => productImageUpload('replacement.png'),
    ]))->assertRedirect(route('products.index'));
    $replacement = $product->refresh()->image_path;
    expect($replacement)->not->toBe($original);
    Storage::disk('local')->assertExists($replacement);
    Storage::disk('local')->assertMissing($original);

    $this->post(route('products.update', $product), imageProductPayload([
        '_method' => 'put', 'version' => 2, 'remove_image' => '1',
    ]))->assertRedirect(route('products.index'));
    expect($product->refresh()->image_path)->toBeNull()->and($product->imageUrl())->toBeNull();
    Storage::disk('local')->assertMissing($replacement);
    $this->get(route('products.image', $product))->assertNotFound();
});

it('cleans up a rejected stale upload without removing the current image', function () {
    Storage::fake('local');
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($manager)->post(route('products.store'), imageProductPayload(['image' => productImageUpload()]));
    $product = Product::query()->firstOrFail();
    $original = $product->image_path;
    $product->update(['version' => 1]);
    $this->post(route('products.update', $product), imageProductPayload([
        '_method' => 'put', 'version' => 0, 'image' => productImageUpload('stale.png'),
    ]))->assertSessionHasErrors('product');
    expect($product->refresh()->image_path)->toBe($original)
        ->and(Storage::disk('local')->allFiles('products'))->toBe([$original]);
});

it('rejects unsafe, oversized or invalid files without writing product images', function (string $kind) {
    Storage::fake('local');
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $file = match ($kind) {
        'svg' => UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'fake' => UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";'),
        'oversized' => productImageUpload()->size(2049),
    };
    $this->actingAs($manager)->post(route('products.store'), imageProductPayload(['image' => $file]))
        ->assertSessionHasErrors('image');
    expect(Product::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles('products'))->toBe([]);
})->with(['svg', 'fake', 'oversized']);

it('protects images and image uploads using existing product and sales permissions', function () {
    Storage::fake('local');
    $path = productImageUpload()->store('products', 'local');
    $product = Product::query()->create(imageProductPayload(['image_path' => $path]));
    $this->get(route('products.image', $product))->assertRedirect(route('login'));
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $this->actingAs($viewer)->get(route('products.image', $product))->assertForbidden();
    $cashier = User::factory()->create(['role' => UserRole::Cashier]);
    $this->actingAs($cashier)->post(route('products.store'), imageProductPayload(['image' => productImageUpload()]))->assertForbidden();
    Storage::disk('local')->assertExists($path);
});
