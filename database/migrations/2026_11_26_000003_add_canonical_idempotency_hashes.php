<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_movements', 'idempotency_request_hash')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->string('idempotency_request_hash', 64)
                    ->nullable()
                    ->after('idempotency_key');
            });
        }

        if (! Schema::hasColumn('pos_sessions', 'close_request_hash')) {
            Schema::table('pos_sessions', function (Blueprint $table): void {
                $table->string('close_request_hash', 64)
                    ->nullable()
                    ->after('closed_at');
            });
        }

        if (! Schema::hasColumn('pos_tenders', 'resolution_request_hash')) {
            Schema::table('pos_tenders', function (Blueprint $table): void {
                $table->string('resolution_request_hash', 64)
                    ->nullable()
                    ->after('resolved_at');
            });
        }

        if (Schema::hasColumn('pos_sales', 'request_hash')) {
            Schema::table('pos_sales', function (Blueprint $table): void {
                $table->string('request_hash', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Keep replay-protection metadata on rollback. Dropping these columns
        // would silently make legacy retries indistinguishable from new ones.
    }
};
