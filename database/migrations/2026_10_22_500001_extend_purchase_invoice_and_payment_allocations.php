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
                'purchase',
                'purchase_return',
                'quotation',
            ])->change();
            $table->enum('status', [
                'draft',
                'confirmed',
                'partially_paid',
                'paid',
                'reversed',
                'cancelled',
            ])->default('draft')->change();
            $table->foreignId('parent_invoice_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('invoices')
                ->restrictOnDelete();
            $table->index(['parent_invoice_id', 'type']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('parent_invoice_item_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('invoice_items')
                ->restrictOnDelete();
            $table->index('parent_invoice_item_id');
        });

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

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('invoice_id')
                ->constrained()
                ->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['payment_id', 'invoice_id']);
            $table->index(['invoice_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->enum('document_type', [
                'journal_entry',
                'sales_invoice',
                'purchase_invoice',
                'quotation',
                'receipt',
                'payment',
                'check',
            ])->change();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex(['parent_invoice_item_id']);
            $table->dropConstrainedForeignId('parent_invoice_item_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['parent_invoice_id', 'type']);
            $table->dropConstrainedForeignId('parent_invoice_id');
            $table->enum('status', [
                'draft',
                'confirmed',
                'paid',
                'cancelled',
            ])->default('draft')->change();
            $table->enum('type', ['sales', 'purchase', 'quotation'])->change();
        });
    }
};
