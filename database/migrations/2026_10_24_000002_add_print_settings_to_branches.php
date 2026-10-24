<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedTinyInteger('thermal_paper_width')
                ->default(80)
                ->after('is_active');
            $table->unsignedTinyInteger('thermal_copies')
                ->default(1)
                ->after('thermal_paper_width');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['thermal_paper_width', 'thermal_copies']);
        });
    }
};
