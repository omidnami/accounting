<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('fiscal_year_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('period_number');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', ['open', 'soft_closed', 'closed'])
                ->default('open');
            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'period_number']);
            $table->index(['store_id', 'status']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
