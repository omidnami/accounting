<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 180);
            $table->string('unit', 30)->default('عدد');
            $table->decimal('purchase_price', 18, 2)->default(0);
            $table->decimal('sale_price', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
