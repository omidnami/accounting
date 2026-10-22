<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->string('check_number', 50);
            $table->string('bank_name', 120);
            $table->string('branch_name', 120)->nullable();
            $table->decimal('amount', 18, 2);
            $table->date('issue_date')->nullable();
            $table->date('due_date');
            $table->enum('type', ['receivable', 'payable']);
            $table->enum('status', [
                'pending',
                'collected',
                'bounced',
                'spent',
            ])->default('pending');
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['bank_name', 'check_number', 'type']);
            $table->index(['type', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
