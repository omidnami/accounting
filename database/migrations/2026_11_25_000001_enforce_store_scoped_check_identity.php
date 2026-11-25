<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('checks')
            ->select('store_id', 'bank_name', 'check_number', 'type')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('store_id', 'bank_name', 'check_number', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Duplicate external checks exist. Resolve store/bank/check-number/type duplicates before enforcing uniqueness.'
            );
        }

        Schema::table('checks', function (Blueprint $table): void {
            $table->dropIndex('checks_external_number_index');
            $table->unique(
                ['store_id', 'bank_name', 'check_number', 'type'],
                'checks_external_number_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->dropUnique('checks_external_number_unique');
            $table->index(
                ['store_id', 'bank_name', 'check_number', 'type'],
                'checks_external_number_index',
            );
        });
    }
};
