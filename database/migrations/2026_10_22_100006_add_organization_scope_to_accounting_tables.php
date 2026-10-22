<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->index(['store_id', 'is_active']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->after('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('fiscal_year_id')
                ->nullable()
                ->after('entry_date')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('accounting_period_id')
                ->nullable()
                ->after('fiscal_year_id')
                ->constrained()
                ->restrictOnDelete();
            $table->index(['store_id', 'branch_id', 'entry_date']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->after('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('fiscal_year_id')
                ->nullable()
                ->after('branch_id')
                ->constrained()
                ->restrictOnDelete();
            $table->index(['store_id', 'branch_id', 'invoice_date']);
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->after('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->index(['store_id', 'branch_id', 'due_date']);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->after('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('fiscal_year_id')
                ->nullable()
                ->after('branch_id')
                ->constrained()
                ->restrictOnDelete();
            $table->index(['store_id', 'branch_id', 'receipt_date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->after('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('fiscal_year_id')
                ->nullable()
                ->after('branch_id')
                ->constrained()
                ->restrictOnDelete();
            $table->index(['store_id', 'branch_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'branch_id', 'payment_date']);
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'branch_id', 'receipt_date']);
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'branch_id', 'due_date']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'branch_id', 'invoice_date']);
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'branch_id', 'entry_date']);
            $table->dropConstrainedForeignId('accounting_period_id');
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_active']);
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
