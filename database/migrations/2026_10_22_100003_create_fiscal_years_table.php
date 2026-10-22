<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('title', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', ['open', 'closing', 'closed'])
                ->default('open');
            $table->boolean('is_current')->default(false);
            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'title']);
            $table->index(['store_id', 'is_current']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
