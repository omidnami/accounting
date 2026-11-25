<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('original_invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('direction', 20);
            $table->decimal('amount', 18, 2);
            $table->date('refund_date');
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('idempotency_key')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['source_invoice_id', 'direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_refunds');
    }
};
