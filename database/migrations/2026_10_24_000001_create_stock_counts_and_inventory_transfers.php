<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->string('count_number', 60)->unique();
            $table->date('count_date');
            $table->enum('status', ['draft', 'posted', 'cancelled'])
                ->default('draft');
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('idempotency_request_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'branch_id', 'count_date', 'status']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('system_quantity', 15, 3);
            $table->decimal('counted_quantity', 15, 3);
            $table->decimal('difference_quantity', 15, 3);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
        });

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_warehouse_id')
                ->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')
                ->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->string('transfer_number', 60)->unique();
            $table->date('transfer_date');
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('total_cost', 18, 2)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('idempotency_request_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'branch_id', 'transfer_date']);
            $table->index(
                ['source_warehouse_id', 'destination_warehouse_id'],
                'inventory_transfers_route_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
