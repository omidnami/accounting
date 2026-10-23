<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_accounts', function (Blueprint $table) {
            $table->string('default_slot', 20)->nullable()->after('is_default');
            $table->unsignedInteger('version')->default(0)->after('is_active');
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->foreignId('settlement_treasury_account_id')
                ->nullable()
                ->after('settlement_entry_id')
                ->constrained('treasury_accounts')
                ->restrictOnDelete();
        });

        $defaultSlots = [];
        DB::table('treasury_accounts')
            ->where('is_default', true)
            ->orderBy('id')
            ->each(function (object $account) use (&$defaultSlots): void {
                $slot = $account->branch_id.':'.$account->type;

                if (isset($defaultSlots[$slot])) {
                    DB::table('treasury_accounts')
                        ->where('id', $account->id)
                        ->update([
                            'is_default' => false,
                            'default_slot' => null,
                        ]);

                    return;
                }

                $defaultSlots[$slot] = true;
                DB::table('treasury_accounts')
                    ->where('id', $account->id)
                    ->update(['default_slot' => $account->type]);
            });

        $checks = DB::table('checks')
            ->whereNotNull('settlement_entry_id')
            ->get(['id', 'store_id', 'branch_id', 'type', 'settlement_entry_id']);

        foreach ($checks as $check) {
            $line = DB::table('journal_entry_lines')
                ->where('journal_entry_id', $check->settlement_entry_id)
                ->when(
                    $check->type === 'receivable',
                    fn ($query) => $query->where('debit', '>', 0),
                    fn ($query) => $query->where('credit', '>', 0),
                )
                ->first(['account_id']);

            if (! $line) {
                continue;
            }

            $treasuryAccountId = DB::table('treasury_accounts')
                ->where('store_id', $check->store_id)
                ->where('branch_id', $check->branch_id)
                ->where('gl_account_id', $line->account_id)
                ->where('type', 'bank')
                ->value('id');

            if ($treasuryAccountId) {
                DB::table('checks')
                    ->where('id', $check->id)
                    ->update(['settlement_treasury_account_id' => $treasuryAccountId]);
            }
        }

        $this->seedCardAccounts();

        Schema::table('treasury_accounts', function (Blueprint $table) {
            $table->unique(
                ['branch_id', 'default_slot'],
                'treasury_accounts_branch_default_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('treasury_accounts', function (Blueprint $table) {
            $table->dropUnique('treasury_accounts_branch_default_unique');
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settlement_treasury_account_id');
        });

        Schema::table('treasury_accounts', function (Blueprint $table) {
            $table->dropColumn(['default_slot', 'version']);
        });
    }

    private function seedCardAccounts(): void
    {
        $branches = DB::table('branches')
            ->where('is_default', true)
            ->get(['id', 'store_id']);
        $now = now();

        foreach ($branches as $branch) {
            $bankAccountId = DB::table('accounts')
                ->where('store_id', $branch->store_id)
                ->where('code', '1102')
                ->value('id');

            if ($bankAccountId === null) {
                continue;
            }

            $exists = DB::table('treasury_accounts')
                ->where('branch_id', $branch->id)
                ->where('code', 'CARD')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('treasury_accounts')->insert([
                'store_id' => $branch->store_id,
                'branch_id' => $branch->id,
                'gl_account_id' => $bankAccountId,
                'code' => 'CARD',
                'name' => 'کارت اصلی',
                'type' => 'card',
                'is_default' => true,
                'default_slot' => 'card',
                'is_active' => true,
                'version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
