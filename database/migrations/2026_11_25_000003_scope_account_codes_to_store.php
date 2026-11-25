<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('accounts')
            ->select('store_id', 'code')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('store_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Duplicate store/account codes must be resolved before adding scoped uniqueness.');
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique('accounts_code_unique');
            $table->unique(['store_id', 'code'], 'accounts_store_code_unique');
        });
    }

    public function down(): void
    {
        $duplicate = DB::table('accounts')
            ->select('code')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Rollback blocked: account codes are shared by multiple stores.');
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique('accounts_store_code_unique');
            $table->unique('code', 'accounts_code_unique');
        });
    }
};
