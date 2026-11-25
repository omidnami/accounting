<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('journal_entries')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->select('source_type', 'source_id', 'entry_kind', 'branch_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('source_type', 'source_id', 'entry_kind', 'branch_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Duplicate source-event journal entries must be resolved before adding uniqueness.');
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreignId('posted_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->unique(
                ['source_type', 'source_id', 'entry_kind', 'branch_id'],
                'journal_entries_source_event_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique('journal_entries_source_event_unique');
            $table->dropConstrainedForeignId('posted_by');
        });
    }
};
