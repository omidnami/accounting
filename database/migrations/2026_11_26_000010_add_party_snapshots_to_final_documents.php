<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = [
        'invoices',
        'receipts',
        'payments',
        'checks',
        'return_refunds',
        'journal_entry_lines',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'party_snapshot')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->json('party_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNotNull('party_snapshot')->exists()) {
                throw new RuntimeException(
                    "Rollback blocked: {$table} contains final party identity snapshots."
                );
            }
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'party_snapshot')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('party_snapshot');
            });
        }
    }
};
