<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 50)->unique();
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->enum('payment_method', ['cash', 'bank', 'card', 'check']);
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('check_id')
                ->nullable()
                ->unique()
                ->constrained('checks')
                ->restrictOnDelete();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->enum('status', [
                'pending',
                'confirmed',
                'cancelled',
            ])->default('confirmed');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['payment_date', 'status']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
