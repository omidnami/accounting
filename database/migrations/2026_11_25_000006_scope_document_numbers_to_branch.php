<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $scopes = [
            'journal_entries' => ['entry_number', 'journal_entries_branch_number_unique'],
            'invoices' => ['invoice_number', 'invoices_branch_number_unique'],
            'receipts' => ['receipt_number', 'receipts_branch_number_unique'],
            'payments' => ['payment_number', 'payments_branch_number_unique'],
        ];

        foreach ($scopes as $table => [$column, $index]) {
            $duplicate = DB::table($table)
                ->select('store_id', 'branch_id', 'fiscal_year_id', $column)
                ->selectRaw('COUNT(*) AS aggregate')
                ->groupBy('store_id', 'branch_id', 'fiscal_year_id', $column)
                ->havingRaw('COUNT(*) > 1')
                ->first();

            if ($duplicate !== null) {
                throw new RuntimeException("Duplicate scoped document numbers exist in {$table}.");
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $index): void {
                $blueprint->dropUnique("{$table}_{$column}_unique");
                $blueprint->unique(['store_id', 'branch_id', 'fiscal_year_id', $column], $index);
            });
        }
    }

    public function down(): void
    {
        $scopes = [
            'journal_entries' => ['entry_number', 'journal_entries_branch_number_unique'],
            'invoices' => ['invoice_number', 'invoices_branch_number_unique'],
            'receipts' => ['receipt_number', 'receipts_branch_number_unique'],
            'payments' => ['payment_number', 'payments_branch_number_unique'],
        ];

        foreach ($scopes as $table => [$column, $index]) {
            $duplicate = DB::table($table)
                ->select($column)
                ->selectRaw('COUNT(*) AS aggregate')
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->first();

            if ($duplicate !== null) {
                throw new RuntimeException("Rollback blocked: {$column} is shared by multiple scopes.");
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $index): void {
                $blueprint->dropUnique($index);
                $blueprint->unique($column, "{$table}_{$column}_unique");
            });
        }
    }
};
