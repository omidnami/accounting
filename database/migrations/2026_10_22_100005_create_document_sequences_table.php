<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('fiscal_year_id')
                ->constrained()
                ->restrictOnDelete();
            $table->enum('document_type', [
                'journal_entry',
                'sales_invoice',
                'purchase_invoice',
                'quotation',
                'receipt',
                'payment',
                'check',
            ]);
            $table->string('prefix', 30)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['store_id', 'branch_id', 'fiscal_year_id', 'document_type'],
                'document_sequences_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
