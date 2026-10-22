<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('type', [
                'sales',
                'sales_return',
                'purchase',
                'purchase_return',
                'quotation',
            ])->change();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost_snapshot', 18, 4)->nullable()->after('line_total');
            $table->decimal('total_cost_snapshot', 18, 2)->nullable()->after('unit_cost_snapshot');
        });

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->enum('document_type', [
                'journal_entry',
                'sales_invoice',
                'sales_return',
                'purchase_invoice',
                'purchase_return',
                'quotation',
                'receipt',
                'payment',
                'check',
            ])->change();
        });

        Schema::create('receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('invoice_id')
                ->constrained()
                ->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['receipt_id', 'invoice_id']);
            $table->index(['invoice_id', 'receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_allocations');

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->enum('document_type', [
                'journal_entry',
                'sales_invoice',
                'purchase_invoice',
                'purchase_return',
                'quotation',
                'receipt',
                'payment',
                'check',
            ])->change();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost_snapshot', 'total_cost_snapshot']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('type', [
                'sales',
                'purchase',
                'purchase_return',
                'quotation',
            ])->change();
        });
    }
};
