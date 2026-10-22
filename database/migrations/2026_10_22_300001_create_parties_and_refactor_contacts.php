<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $storeId = $this->resolveLegacyStoreId();

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('code', 30);
            $table->enum('entity_type', ['individual', 'organization'])
                ->default('individual');
            $table->string('name', 180);
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'code']);
            $table->index(['is_active', 'name']);
            $table->index('national_id');
            $table->index('tax_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('party_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('payment_terms', 100)->nullable();
            $table->text('sales_notes')->nullable();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('party_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('payment_terms', 100)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->text('purchasing_notes')->nullable();
        });

        $this->backfillParties('customers', $storeId);
        $this->backfillParties('suppliers', $storeId);

        Schema::table('customers', function (Blueprint $table) {
            $table->unique('party_id');
            $table->dropUnique(['code']);
            $table->dropIndex(['is_active', 'name']);
            $table->dropColumn([
                'code',
                'name',
                'phone',
                'email',
                'national_id',
                'tax_id',
                'address',
            ]);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unique('party_id');
            $table->dropUnique(['code']);
            $table->dropIndex(['is_active', 'name']);
            $table->dropColumn([
                'code',
                'name',
                'phone',
                'email',
                'national_id',
                'tax_id',
                'address',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('code', 30)->nullable();
            $table->string('name', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->text('address')->nullable();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('code', 30)->nullable();
            $table->string('name', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->text('address')->nullable();
        });

        $this->restorePartyColumns('customers');
        $this->restorePartyColumns('suppliers');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['party_id']);
            $table->dropConstrainedForeignId('party_id');
            $table->dropColumn(['payment_terms', 'sales_notes']);
            $table->unique('code');
            $table->index(['is_active', 'name']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['party_id']);
            $table->dropConstrainedForeignId('party_id');
            $table->dropColumn(['payment_terms', 'lead_time_days', 'purchasing_notes']);
            $table->unique('code');
            $table->index(['is_active', 'name']);
        });

        Schema::dropIfExists('parties');
    }

    private function backfillParties(string $table, int $storeId): void
    {
        DB::table($table)
            ->whereNull('party_id')
            ->orderBy('id')
            ->each(function (object $contact) use ($table, $storeId): void {
                $partyId = DB::table('parties')->insertGetId([
                    'store_id' => $storeId,
                    'code' => $this->uniquePartyCode(
                        $storeId,
                        (string) $contact->code,
                        $table === 'customers' ? 'C' : 'S',
                        (int) $contact->id,
                    ),
                    'entity_type' => 'individual',
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'national_id' => $contact->national_id,
                    'tax_id' => $contact->tax_id,
                    'address' => $contact->address,
                    'is_active' => $contact->is_active,
                    'created_at' => $contact->created_at,
                    'updated_at' => $contact->updated_at,
                ]);

                DB::table($table)
                    ->where('id', $contact->id)
                    ->update(['party_id' => $partyId]);
            });
    }

    private function restorePartyColumns(string $table): void
    {
        DB::table($table)
            ->whereNotNull('party_id')
            ->orderBy('id')
            ->each(function (object $contact) use ($table): void {
                $party = DB::table('parties')
                    ->where('id', $contact->party_id)
                    ->first();

                if ($party === null) {
                    return;
                }

                DB::table($table)
                    ->where('id', $contact->id)
                    ->update([
                        'code' => $party->code,
                        'name' => $party->name,
                        'phone' => $party->phone,
                        'email' => $party->email,
                        'national_id' => $party->national_id,
                        'tax_id' => $party->tax_id,
                        'address' => $party->address,
                    ]);
            });
    }

    private function resolveLegacyStoreId(): int
    {
        $hasContacts = DB::table('customers')->exists() || DB::table('suppliers')->exists();
        $storeId = DB::table('stores')->where('code', 'MAIN')->value('id')
            ?? DB::table('stores')->orderBy('id')->value('id');

        if ($storeId !== null) {
            return (int) $storeId;
        }

        if ($hasContacts) {
            throw new RuntimeException('برای انتقال طرف‌حساب‌های موجود، ابتدا فروشگاه پیش‌فرض را بسازید.');
        }

        return DB::table('stores')->insertGetId([
            'code' => 'MAIN',
            'name' => 'فروشگاه اصلی',
            'currency' => 'IRR',
            'timezone' => 'Asia/Tehran',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uniquePartyCode(
        int $storeId,
        string $baseCode,
        string $typeSuffix,
        int $recordId,
    ): string {
        if (! DB::table('parties')->where('store_id', $storeId)->where('code', $baseCode)->exists()) {
            return $baseCode;
        }

        $suffix = '-'.$typeSuffix.$recordId;
        $candidate = mb_substr($baseCode, 0, 30 - mb_strlen($suffix)).$suffix;
        $counter = 1;

        while (DB::table('parties')->where('store_id', $storeId)->where('code', $candidate)->exists()) {
            $counterSuffix = $suffix.'-'.$counter;
            $candidate = mb_substr($baseCode, 0, 30 - mb_strlen($counterSuffix)).$counterSuffix;
            $counter++;
        }

        return $candidate;
    }
};
