<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('product_price_histories')
                ->whereColumn('product_price_histories.product_id', 'products.id'))
            ->orderBy('id')
            ->chunk(100, function ($products): void {
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
    }

    public function down(): void
    {
        DB::table('product_price_histories')
            ->where('source', 'migration')
            ->delete();
    }
};
