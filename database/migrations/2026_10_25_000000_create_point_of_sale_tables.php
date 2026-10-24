<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $table->foreignId('card_account_id')->nullable()->constrained('treasury_accounts')->restrictOnDelete();
            // Nullable slots allow historical closed sessions, but only one active drawer/user.
            $table->unsignedBigInteger('active_user_id')->nullable()->unique();
            $table->unsignedBigInteger('active_cash_account_id')->nullable()->unique();
            $table->decimal('opening_cash', 18, 2)->default(0);
            $table->decimal('expected_cash', 18, 2)->nullable();
            $table->decimal('counted_cash', 18, 2)->nullable();
            $table->decimal('cash_difference', 18, 2)->nullable();
            $table->string('closing_notes', 2000)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->string('request_hash', 64);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_tenders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained()->restrictOnDelete();
            $table->string('method', 16);
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100)->nullable();
            $table->boolean('check_received')->default(false);
            $table->string('check_number', 100)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('receipt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['method', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_tenders');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_sessions');
    }
};
