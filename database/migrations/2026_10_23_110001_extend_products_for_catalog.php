<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 64)
                ->nullable()
                ->after('name')
                ->unique();
            $table->decimal('reorder_level', 15, 3)
                ->default(0)
                ->after('tax_rate');
            $table->text('description')
                ->nullable()
                ->after('reorder_level');
            $table->unsignedBigInteger('version')
                ->default(0)
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn(['barcode', 'reorder_level', 'description', 'version']);
        });
    }
};
