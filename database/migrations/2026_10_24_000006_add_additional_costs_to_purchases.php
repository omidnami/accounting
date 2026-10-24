<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('additional_cost_total', 18, 2)
                ->default(0)
                ->after('tax_total');
        });

        Schema::create('purchase_additional_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('description', 120);
            $table->decimal('amount', 18, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_additional_costs');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('additional_cost_total');
        });
    }
};
