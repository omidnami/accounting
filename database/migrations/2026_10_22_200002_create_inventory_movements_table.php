<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
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
            $table->enum('direction', ['in', 'out']);
            $table->enum('movement_type', [
                'opening',
                'purchase',
                'sale',
                'sales_return',
                'purchase_return',
                'transfer',
                'adjustment',
            ]);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('total_cost', 18, 2);
            $table->decimal('quantity_after', 15, 3);
            $table->decimal('average_cost_after', 18, 4);
            $table->decimal('value_after', 18, 2);
            $table->date('movement_date');
            $table->nullableMorphs('source');
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id', 'movement_date']);
            $table->index(['store_id', 'branch_id', 'movement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
