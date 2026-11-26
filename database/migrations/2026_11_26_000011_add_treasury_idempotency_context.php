<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('checks', 'fiscal_year_id')) {
            Schema::table('checks', function (Blueprint $table): void {
                $table->foreignId('fiscal_year_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained()
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('checks', 'source_invoice_ids')) {
            Schema::table('checks', function (Blueprint $table): void {
                $table->json('source_invoice_ids')->nullable()->after('description');
            });
        }

        if (! Schema::hasColumn('checks', 'settlement_request_hash')) {
            Schema::table('checks', function (Blueprint $table): void {
                $table->string('settlement_request_hash', 64)
                    ->nullable()
                    ->after('settlement_treasury_account_id');
            });
        }

        if (! Schema::hasColumn('checks', 'bounce_request_hash')) {
            Schema::table('checks', function (Blueprint $table): void {
                $table->string('bounce_request_hash', 64)->nullable()->after('bounce_entry_id');
            });
        }
    }

    public function down(): void
    {
        // Preserve replay-protection and historical source context on rollback.
    }
};
