<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 50)->unique();
            $table->enum('type', ['sales', 'purchase', 'quotation']);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->enum('status', [
                'draft',
                'confirmed',
                'paid',
                'cancelled',
            ])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'invoice_date']);
            $table->index(['status', 'invoice_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
