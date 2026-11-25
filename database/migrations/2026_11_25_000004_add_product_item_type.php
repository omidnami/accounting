<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('item_type', 20)->default('stock')->after('name');
            $table->index(['item_type', 'is_active']);
        });
    }

    public function down(): void
    {
        if (DB::table('products')->where('item_type', '!=', 'stock')->exists()) {
            throw new RuntimeException('Rollback blocked: non-stock or service products exist.');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['item_type', 'is_active']);
            $table->dropColumn('item_type');
        });
    }
};
