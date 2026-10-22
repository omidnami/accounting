<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('requires_party')->default(false)->after('normal_balance');
        });

        DB::table('accounts')
            ->whereIn('code', ['1103', '2101'])
            ->update(['requires_party' => true]);

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('party_id')
                ->nullable()
                ->after('account_id')
                ->constrained()
                ->restrictOnDelete();
            $table->index(['party_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['party_id', 'account_id']);
            $table->dropConstrainedForeignId('party_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('requires_party');
        });
    }
};
