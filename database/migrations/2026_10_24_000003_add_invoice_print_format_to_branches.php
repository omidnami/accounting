<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('invoice_print_format', 30)
                ->default('thermal_80')
                ->after('thermal_copies');
            $table->string('invoice_header_note', 255)
                ->nullable()
                ->after('invoice_print_format');
        });

        DB::table('branches')
            ->where('thermal_paper_width', 58)
            ->update(['invoice_print_format' => 'thermal_58']);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['invoice_print_format', 'invoice_header_note']);
        });
    }
};
