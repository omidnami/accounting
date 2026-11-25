<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->enum('status', [
                'pending',
                'collected',
                'bounced',
                'spent',
                'cancelled',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('checks')->where('status', 'cancelled')->exists()) {
            throw new RuntimeException('Rollback blocked: cancelled checks exist.');
        }

        Schema::table('checks', function (Blueprint $table): void {
            $table->enum('status', [
                'pending',
                'collected',
                'bounced',
                'spent',
            ])->default('pending')->change();
        });
    }
};
