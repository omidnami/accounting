<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_alerts', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->dropUnique('inventory_alerts_product_batch_id_type_unique');
            $table->unique(
                ['product_batch_id', 'type', 'branch_id', 'warehouse_id'],
                'inventory_alerts_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_alerts', function (Blueprint $table): void {
            $table->dropUnique('inventory_alerts_scope_unique');
            $table->dropConstrainedForeignId('warehouse_id');
            $table->unique(['product_batch_id', 'type'], 'inventory_alerts_product_batch_id_type_unique');
        });
    }
};
