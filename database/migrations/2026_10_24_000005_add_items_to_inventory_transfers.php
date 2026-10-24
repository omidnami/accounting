<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_transfer_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('total_cost', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['inventory_transfer_id', 'product_id']);
            $table->index(['product_id', 'inventory_transfer_id']);
        });

        DB::table('inventory_transfers')
            ->chunkById(100, function ($transfers): void {
                foreach ($transfers as $transfer) {
                    DB::table('inventory_transfer_items')->insert([
                        'inventory_transfer_id' => $transfer->id,
                        'product_id' => $transfer->product_id,
                        'quantity' => $transfer->quantity,
                        'unit_cost' => $transfer->unit_cost,
                        'total_cost' => $transfer->total_cost,
                        'created_at' => $transfer->created_at,
                        'updated_at' => $transfer->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
    }
};
