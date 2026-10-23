<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->enum('entry_kind', [
                'system',
                'manual',
                'opening',
                'closing',
                'reversal',
            ])->default('system')->after('status');

            $table->index(['store_id', 'entry_kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'entry_kind', 'status']);
            $table->dropColumn('entry_kind');
        });
    }
};
