<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('role')
                ->constrained()
                ->nullOnDelete();
        });

        $defaultBranchId = DB::table('branches')
            ->where('is_default', true)
            ->value('id');

        if ($defaultBranchId !== null) {
            DB::table('users')
                ->whereIn('role', ['cashier', 'warehouse'])
                ->update(['branch_id' => $defaultBranchId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
