<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
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

        $this->backfillParties('customers');
        $this->backfillParties('suppliers');

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

    private function backfillParties(string $table): void
    {
        DB::table($table)
            ->whereNull('party_id')
            ->orderBy('id')
            ->each(function (object $contact) use ($table): void {
                $partyId = DB::table('parties')->insertGetId([
                    'code' => $contact->code,
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
};
