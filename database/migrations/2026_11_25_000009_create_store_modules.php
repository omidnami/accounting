<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('module', 50);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_modules');
    }
};
