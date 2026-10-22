<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->enum('type', [
                'asset',
                'liability',
                'equity',
                'revenue',
                'expense',
            ]);
            $table->enum('level', ['general', 'subsidiary']);
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'level']);
            $table->index(['parent_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
