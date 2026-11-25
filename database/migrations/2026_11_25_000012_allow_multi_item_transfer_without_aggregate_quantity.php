<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_transfers')->whereNull('quantity')->exists()) {
            throw new RuntimeException('Rollback blocked: multi-item transfers have no aggregate quantity.');
        }

        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->nullable(false)->change();
        });
    }
};
