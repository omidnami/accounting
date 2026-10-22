<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('warehouse_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('inventory_value', 18, 2)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
            $table->index(['store_id', 'branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};
