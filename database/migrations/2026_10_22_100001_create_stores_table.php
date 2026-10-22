<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('legal_name', 180)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->text('address')->nullable();
            $table->char('currency', 3)->default('IRR');
            $table->string('timezone', 64)->default('Asia/Tehran');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
