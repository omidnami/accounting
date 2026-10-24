<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('id')
                ->constrained('product_categories')
                ->restrictOnDelete();
            $table->foreignId('parent_product_id')
                ->nullable()
                ->after('category_id')
                ->constrained('products')
                ->restrictOnDelete();
            $table->string('variant_color', 80)->nullable()->after('name');
            $table->string('variant_size', 80)->nullable()->after('variant_color');
            $table->decimal('safety_stock', 15, 3)->default(0)->after('reorder_level');
            $table->decimal('reorder_quantity', 15, 3)->default(0)->after('safety_stock');
            $table->decimal('max_stock', 15, 3)->nullable()->after('reorder_quantity');
            $table->string('stock_policy', 20)->default('simple')->after('max_stock');

            $table->index(['category_id', 'is_active']);
            $table->index(['parent_product_id', 'variant_color', 'variant_size'], 'products_variant_idx');
        });

        Schema::create('product_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('purchase_price', 18, 2);
            $table->decimal('sale_price', 18, 2);
            $table->string('source', 20)->default('manual');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['product_id', 'effective_at']);
        });

        DB::table('products')
            ->chunkById(100, function ($products): void {
                foreach ($products as $product) {
                    DB::table('product_price_histories')->insert([
                        'product_id' => $product->id,
                        'purchase_price' => $product->purchase_price,
                        'sale_price' => $product->sale_price,
                        'source' => 'migration',
                        'changed_by' => null,
                        'effective_at' => $product->updated_at ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 80);
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'batch_number']);
            $table->index(['expiry_date', 'product_id']);
        });

        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('serial_number', 120)->unique();
            $table->string('status', 20)->default('available');
            $table->date('acquired_at')->nullable();
            $table->date('sold_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('product_price_histories');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_variant_idx');
            $table->dropIndex(['category_id', 'is_active']);
            $table->dropConstrainedForeignId('parent_product_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn([
                'variant_color',
                'variant_size',
                'safety_stock',
                'reorder_quantity',
                'max_stock',
                'stock_policy',
            ]);
        });

        Schema::dropIfExists('product_categories');
    }
};
